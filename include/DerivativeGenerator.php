<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

use DerivativeParams;
use ImageStdParams;
use SizingParams;
use pwg_image;

/**
 * Piwigo 16 i.php-parity derivative parser and on-demand generator with:
 * - try_switch_source() larger-derivative cascading
 * - pwg_representative rotation=0 guard
 * - IMG_CUSTOM sharpening & allowlist checks (PHP 8 safe)
 * - IMG_3XLARGE / IMG_4XLARGE compression quality cap
 * - No-op $changes===0 fast path
 * - Conditional/configured EXIF metadata stripping
 * - Per-derivative advisory flock + extension-preserving atomic temp rename
 */
final class DerivativeGenerator
{
    /**
     * Parses a normalized derivative path (e.g. ./_data/i/upload/2026/01/img-me.jpg).
     *
     * @throws HttpException
     */
    public static function parseRequestPath(string $normalizedDerivativePath, Config $config): ParsedDerivative
    {
        $derivPrefix = './' . trim($config->derivativeDir, '/') . '/';
        if (!str_starts_with($normalizedDerivativePath, $derivPrefix)) {
            throw new HttpException(400, 'Request is not inside derivative directory');
        }

        $rel = substr($normalizedDerivativePath, strlen($derivPrefix));
        $dotPos = strrpos($rel, '.');
        if ($dotPos === false) {
            throw new HttpException(400, 'Missing extension in derivative path');
        }

        $ext = substr($rel, $dotPos);
        $stem = substr($rel, 0, $dotPos);

        $dashPos = strrpos($stem, '-');
        if ($dashPos === false) {
            throw new HttpException(400, 'Missing derivative suffix separator');
        }

        $derivTokenStr = substr($stem, $dashPos + 1);
        $srcWithoutExt = substr($stem, 0, $dashPos);
        if ($srcWithoutExt === '' || $derivTokenStr === '') {
            throw new HttpException(400, 'Empty source stem or derivative suffix');
        }

        $tokens = explode('_', $derivTokenStr);
        $firstToken = $tokens[0];

        $derivativeType = null;
        $derivativeParams = null;

        foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
            if (derivative_to_url($type) === $firstToken) {
                $derivativeType = (string) $type;
                $derivativeParams = $params;
                break;
            }
        }

        if ($derivativeType === null) {
            if (derivative_to_url(IMG_CUSTOM) === $firstToken) {
                $derivativeType = IMG_CUSTOM;
            } else {
                throw new HttpException(400, 'Unknown derivative type');
            }
        }

        $postfix = (string) array_shift($tokens);

        if ($derivativeType === IMG_CUSTOM) {
            if (count($tokens) < 1) {
                throw new HttpException(400, 'Empty custom derivative parameters');
            }
            $derivativeParams = self::parseCustomParams($tokens);
            ImageStdParams::apply_global($derivativeParams);

            if ($derivativeParams->sizing->ideal_size[0] < 20 || $derivativeParams->sizing->ideal_size[1] < 20) {
                throw new HttpException(400, 'Custom derivative dimensions too small');
            }
            if ($derivativeParams->sizing->max_crop < 0 || $derivativeParams->sizing->max_crop > 1) {
                throw new HttpException(400, 'Custom derivative crop out of range');
            }

            $keyTokens = [];
            $derivativeParams->add_url_tokens($keyTokens);
            $customKey = implode('_', $keyTokens);
            if (!isset(ImageStdParams::$custom[$customKey])) {
                throw new HttpException(403, 'Custom derivative size not registered');
            }

            $postfix .= '_' . $customKey;
        }

        if (!$derivativeParams instanceof DerivativeParams) {
            throw new HttpException(400, 'Unable to resolve derivative parameters');
        }

        $srcRelPath = './' . $srcWithoutExt . $ext;
        $derivativeRelPath = $derivPrefix . $srcWithoutExt . '-' . $postfix . $ext;
        $isThemeOrPlugin = str_starts_with($srcWithoutExt, 'themes/')
            || str_starts_with($srcWithoutExt, 'plugins/');
        $isRepresentative = !$isThemeOrPlugin
            && basename(dirname($srcRelPath)) === 'pwg_representative';

        return new ParsedDerivative(
            postfix: $postfix,
            type: $derivativeType,
            params: $derivativeParams,
            srcRelPath: $srcRelPath,
            derivativeRelPath: $derivativeRelPath,
            ext: $ext,
            isRepresentativeSource: $isRepresentative,
            isThemeOrPluginSource: $isThemeOrPlugin,
        );
    }

    /**
     * Generates the derivative if missing or stale and returns the relative path to serve.
     *
     * @param array<string, mixed>|null $elementInfo DB row from piwigo_images, null for theme/plugin assets
     * @param callable(string): object|null $imageFactory Optional factory for testing (returns pwg_image-compatible object)
     * @throws HttpException
     */
    public static function ensureGenerated(
        ParsedDerivative $parsed,
        ?array $elementInfo,
        string $rootPath,
        Config $config,
        ?callable $imageFactory = null,
    ): DerivativeResult {
        $srcRelPath = $parsed->srcRelPath;
        $srcFullPath = self::joinRootPath($rootPath, $srcRelPath);

        if (!@is_file($srcFullPath)
            && !$parsed->isThemeOrPluginSource
            && $elementInfo !== null
            && isset($elementInfo['path'])
            && is_string($elementInfo['path'])
            && str_starts_with($elementInfo['path'], '../')
        ) {
            $parentRel = '../' . substr($srcRelPath, 2);
            $parentFull = self::joinRootPath($rootPath, $parentRel);
            if (@is_file($parentFull)) {
                $srcRelPath = $parentRel;
                $srcFullPath = $parentFull;
            }
        }

        $srcMtime = @filemtime($srcFullPath);
        if ($srcMtime === false) {
            throw new HttpException(404, 'Source image not found');
        }

        // Work on an isolated clone so try_switch_source() never mutates global ImageStdParams.
        $params = clone $parsed->params;
        $derivFullPath = self::joinRootPath($rootPath, $parsed->derivativeRelPath);

        $derivMtime = @filemtime($derivFullPath);
        if ($derivMtime !== false
            && $derivMtime >= $srcMtime
            && $derivMtime >= $params->last_mod_time
            && ((int) @filesize($derivFullPath)) > 0
        ) {
            return new DerivativeResult($parsed->derivativeRelPath, generatedNow: false);
        }

        // Acquire bounded advisory lock to prevent stampedes, TOCTOU races, and half-written JPEGs.
        $tmpDir = rtrim($rootPath, '/') . '/' . trim($config->dataLocation, '/') . '/tmp';
        if (!self::ensureDir($tmpDir)) {
            throw new HttpException(500, 'Could not create derivative temp directory');
        }

        $lockBucket = substr(sha1($parsed->derivativeRelPath), 0, 3);
        $lockPath = $tmpDir . '/pwg_priv_' . $lockBucket . '.lock';
        $lockFp = @fopen($lockPath, 'c+b');
        if ($lockFp === false) {
            throw new HttpException(500, 'Could not open derivative lock file');
        }

        $tmpFile = null;
        $image = null;
        $wmImage = null;

        try {
            if (!@flock($lockFp, LOCK_EX)) {
                throw new HttpException(500, 'Could not acquire derivative lock');
            }
            clearstatcache(true, $derivFullPath);
            $derivMtimeLocked = @filemtime($derivFullPath);
            if ($derivMtimeLocked !== false
                && $derivMtimeLocked >= $srcMtime
                && $derivMtimeLocked >= $params->last_mod_time
                && ((int) @filesize($derivFullPath)) > 0
            ) {
                return new DerivativeResult($parsed->derivativeRelPath, generatedNow: false);
            }

            if (!self::ensureDir(dirname($derivFullPath))) {
                throw new HttpException(500, 'Could not create derivative cache directory');
            }

            // Resolve original dimensions & rotation angle under lock to avoid duplicate DB writes.
            $originalSize = null;
            if ($elementInfo !== null && !$parsed->isRepresentativeSource
                && isset($elementInfo['width'], $elementInfo['height'])
                && is_numeric($elementInfo['width']) && is_numeric($elementInfo['height'])
            ) {
                $originalSize = [(int) $elementInfo['width'], (int) $elementInfo['height']];
            } elseif ($imageInfo = @getimagesize($srcFullPath)) {
                $originalSize = [(int) $imageInfo[0], (int) $imageInfo[1]];
            }

            $coi = ($elementInfo !== null && !$parsed->isRepresentativeSource && isset($elementInfo['coi']) && is_string($elementInfo['coi']) && $elementInfo['coi'] !== '')
                ? $elementInfo['coi']
                : null;

            if ($parsed->isRepresentativeSource || $parsed->isThemeOrPluginSource || $elementInfo === null) {
                // Piwigo core i.php:472-520: never apply original photo rotation metadata or write images.rotation
                // for FFmpeg/VideoJS pwg_representative posters or theme/plugin icons.
                $rotationAngle = 0;
            } elseif (isset($elementInfo['rotation']) && is_numeric($elementInfo['rotation'])) {
                $rotationAngle = (int) pwg_image::get_rotation_angle_from_code((string) $elementInfo['rotation']);
            } else {
                $detected = pwg_image::get_rotation_angle($srcFullPath);
                $rotationAngle = is_numeric($detected) ? (int) $detected : 0;
                if (isset($elementInfo['id']) && function_exists('single_update') && defined('IMAGES_TABLE')) {
                    single_update(
                        IMAGES_TABLE,
                        ['rotation' => pwg_image::get_rotation_code_from_angle($rotationAngle)],
                        ['id' => (int) $elementInfo['id']]
                    );
                }
            }

            // Attempt try_switch_source() cascade from a cached larger derivative under lock.
            $activeSrcRelPath = $srcRelPath;
            $activeSrcFullPath = $srcFullPath;
            $activeRotationAngle = $rotationAngle;
            $switched = self::trySwitchSource(
                $params,
                $derivFullPath,
                $parsed->derivativeRelPath,
                $parsed->postfix,
                $parsed->ext,
                $srcMtime,
                $originalSize,
                $rotationAngle
            );
            if ($switched !== null) {
                $activeSrcRelPath = $switched['relPath'];
                $activeSrcFullPath = $switched['fullPath'];
                $activeRotationAngle = 0;
            } elseif ($params->type === IMG_CUSTOM) {
                self::applyCustomSharpen($params);
            }

            if (PHP_SAPI !== 'cli') {
                ignore_user_abort(true);
                @set_time_limit(0);
            }

            $factory = $imageFactory ?? static fn (string $path): object => new pwg_image($path);
            try {
                /** @var pwg_image $image */
                $image = $factory($activeSrcFullPath);
            } catch (\Throwable $e) {
                if ($switched === null) {
                    throw $e;
                }
                // Fallback cleanly to original source if intermediate cached derivative vanished.
                $params = clone $parsed->params;
                $activeSrcRelPath = $srcRelPath;
                $activeSrcFullPath = $srcFullPath;
                $activeRotationAngle = $rotationAngle;
                if ($params->type === IMG_CUSTOM) {
                    self::applyCustomSharpen($params);
                }
                /** @var pwg_image $image */
                $image = $factory($activeSrcFullPath);
            }

            $changes = 0;

            if ($activeRotationAngle !== 0) {
                $image->rotate($activeRotationAngle);
                $changes++;
            }

            $oSize = $dSize = [(int) $image->get_width(), (int) $image->get_height()];
            $cropRect = null;
            $scaledSize = null;
            $params->sizing->compute($oSize, $coi, $cropRect, $scaledSize);

            if ($cropRect !== null) {
                $image->crop($cropRect->width(), $cropRect->height(), $cropRect->l, $cropRect->t);
                $changes++;
            }

            if ($scaledSize !== null) {
                $image->resize($scaledSize[0], $scaledSize[1]);
                $dSize = [(int) $scaledSize[0], (int) $scaledSize[1]];
                $changes++;
            }

            if ($params->sharpen > 0) {
                $changes += (int) $image->sharpen($params->sharpen);
            }

            if ($params->will_watermark($dSize)) {
                $wm = ImageStdParams::get_watermark();
                $wmPath = self::joinRootPath($rootPath, $wm->file);
                /** @var pwg_image $wmImage */
                $wmImage = $factory($wmPath);
                $wmSize = [(int) $wmImage->get_width(), (int) $wmImage->get_height()];

                if ($dSize[0] < $wmSize[0] || $dSize[1] < $wmSize[1]) {
                    $wmScaling = SizingParams::classic($dSize[0], $dSize[1]);
                    $wmTmpRect = null;
                    $wmScaledSize = null;
                    $wmScaling->compute($wmSize, null, $wmTmpRect, $wmScaledSize);
                    if (is_array($wmScaledSize)) {
                        $wmSize = [(int) $wmScaledSize[0], (int) $wmScaledSize[1]];
                        $wmImage->resize($wmSize[0], $wmSize[1]);
                    }
                }

                $x = (int) round(($wm->xpos / 100) * ($dSize[0] - $wmSize[0]));
                $y = (int) round(($wm->ypos / 100) * ($dSize[1] - $wmSize[1]));
                if ($image->compose($wmImage, $x, $y, $wm->opacity)) {
                    $changes++;
                    if ($wm->xrepeat || $wm->yrepeat) {
                        $xpad = $wmSize[0] + max(30, (int) round($wmSize[0] / 4));
                        $ypad = $wmSize[1] + max(30, (int) round($wmSize[1] / 4));
                        for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                            for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                                if ($i === 0 && $j === 0) {
                                    continue;
                                }
                                $x2 = $x + $i * $xpad;
                                $y2 = $y + $j * $ypad;
                                if ($x2 >= 0 && ($x2 + $wmSize[0]) < $dSize[0]
                                    && $y2 >= 0 && ($y2 + $wmSize[1]) < $dSize[1]
                                ) {
                                    if (!$image->compose($wmImage, $x2, $y2, $wm->opacity)) {
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                self::safeDestroy($wmImage);
                $wmImage = null;
            }

            $needsStrip = ($dSize[0] * $dSize[1] < $config->stripMetadataThreshold)
                || !$config->showExif
                || $config->stripMetadata;

            if ($changes === 0 && !$needsStrip) {
                self::safeDestroy($image);
                $image = null;
                return new DerivativeResult($activeSrcRelPath, generatedNow: false, unchangedFallback: true);
            }

            if ($needsStrip) {
                $image->strip();
            }

            $quality = (int) ImageStdParams::$quality;
            if (in_array($parsed->type, [IMG_3XLARGE, IMG_4XLARGE], true)) {
                $quality = min($quality, 75);
            }
            $image->set_compression_quality($quality);

            // Extension-preserving sibling temp filename so pwg_image::write() infers format correctly.
            $tmpFile = dirname($derivFullPath) . '/.tmp_' . bin2hex(random_bytes(6)) . $parsed->ext;
            $image->write($tmpFile);
            self::safeDestroy($image);
            $image = null;

            if (!@is_file($tmpFile) || ((int) @filesize($tmpFile)) === 0) {
                throw new HttpException(500, 'Generated derivative file is empty');
            }
            @chmod($tmpFile, 0644);
            if (!@rename($tmpFile, $derivFullPath)) {
                throw new HttpException(500, 'Failed to move derivative into place');
            }
            $tmpFile = null;

            return new DerivativeResult($parsed->derivativeRelPath, generatedNow: true);
        } finally {
            if ($wmImage !== null) {
                self::safeDestroy($wmImage);
            }
            if ($image !== null) {
                self::safeDestroy($image);
            }
            if ($tmpFile !== null && @is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    }

    private static function applyCustomSharpen(DerivativeParams $params): void
    {
        $sharpenSum = 0.0;
        $typeMap = ImageStdParams::get_defined_type_map();
        if (count($typeMap) > 0) {
            foreach ($typeMap as $stdParams) {
                $sharpenSum += (float) $stdParams->sharpen;
            }
            $params->sharpen = round($sharpenSum / count($typeMap));
        }
    }

    /**
     * Port of Piwigo 16 i.php try_switch_source() so smaller derivatives cascade from cached larger ones.
     *
     * @param int[]|null $originalSize
     * @return array{relPath: string, fullPath: string}|null
     */
    private static function trySwitchSource(
        DerivativeParams $params,
        string $derivFullPath,
        string $derivRelPath,
        string $postfix,
        string $ext,
        int $originalMtime,
        ?array $originalSize,
        int $rotationAngle,
    ): ?array {
        if ($originalSize === null) {
            return null;
        }

        $currentSuffix = '-' . $postfix . $ext;
        if (!str_ends_with($derivFullPath, $currentSuffix) || !str_ends_with($derivRelPath, $currentSuffix)) {
            return null;
        }
        $baseFullPath = substr($derivFullPath, 0, -strlen($currentSuffix));
        $baseRelPath = substr($derivRelPath, 0, -strlen($currentSuffix));

        if ($rotationAngle === 90 || $rotationAngle === 270) {
            $originalSize = [$originalSize[1], $originalSize[0]];
        }

        $dSize = $params->compute_final_size($originalSize);
        $useWatermark = (bool) $params->use_watermark;
        if ($useWatermark) {
            $useWatermark = $params->will_watermark($dSize);
        }

        $candidates = [];
        foreach (ImageStdParams::get_defined_type_map() as $candidate) {
            if ($candidate->type === $params->type) {
                continue;
            }
            if ((bool) $candidate->use_watermark !== $useWatermark) {
                continue;
            }
            if ($candidate->max_width() < $params->max_width() || $candidate->max_height() < $params->max_height()) {
                continue;
            }
            $candidateSize = $candidate->compute_final_size($originalSize);
            if ($dSize != $params->compute_final_size($candidateSize)) {
                continue;
            }

            if ($params->sizing->max_crop == 0) {
                if ($candidate->sizing->max_crop != 0) {
                    continue;
                }
            } else {
                if ($useWatermark && $candidate->use_watermark) {
                    continue;
                }
                if ($candidate->sizing->max_crop != 0) {
                    continue;
                }
                $minW = is_array($params->sizing->min_size) ? ($params->sizing->min_size[0] ?? 0) : 0;
                $minH = is_array($params->sizing->min_size) ? ($params->sizing->min_size[1] ?? 0) : 0;
                if ($candidateSize[0] < $minW || $candidateSize[1] < $minH) {
                    continue;
                }
            }
            $candidates[] = $candidate;
        }

        foreach (array_reverse($candidates) as $candidate) {
            $candSuffix = '-' . derivative_to_url($candidate->type) . $ext;
            $candFullPath = $baseFullPath . $candSuffix;
            $candMtime = @filemtime($candFullPath);
            if ($candMtime === false
                || $candMtime < $originalMtime
                || $candMtime < $candidate->last_mod_time
                || ((int) @filesize($candFullPath)) === 0
            ) {
                continue;
            }
            $candRelPath = $baseRelPath . $candSuffix;
            $params->use_watermark = false;
            $params->sharpen = min(1, $params->sharpen);
            return [
                'relPath' => $candRelPath,
                'fullPath' => $candFullPath,
            ];
        }

        return null;
    }

    /**
     * @param string[] $tokens
     * @throws HttpException
     */
    private static function parseCustomParams(array $tokens): DerivativeParams
    {
        if (count($tokens) < 1) {
            throw new HttpException(400, 'Empty custom sizing tokens');
        }

        $crop = 0.0;
        $minSize = null;
        $token = (string) array_shift($tokens);
        if ($token === '') {
            throw new HttpException(400, 'Empty custom sizing parameter');
        }

        if ($token[0] === 's') {
            $size = self::urlToSize(substr($token, 1));
        } elseif ($token[0] === 'e') {
            $crop = 1.0;
            $size = $minSize = self::urlToSize(substr($token, 1));
        } else {
            if (count($tokens) < 2) {
                throw new HttpException(400, 'Incomplete custom crop parameters');
            }
            $size = self::urlToSize($token);
            $cropToken = (string) array_shift($tokens);
            if ($cropToken === '' || strlen($cropToken) !== 1 || $cropToken < 'a' || $cropToken > 'z') {
                throw new HttpException(400, 'Invalid custom crop fraction token');
            }
            $crop = (float) char_to_fraction($cropToken);
            $minSizeToken = (string) array_shift($tokens);
            $minSize = self::urlToSize($minSizeToken);
        }

        return new DerivativeParams(new SizingParams($size, $crop, $minSize));
    }

    /**
     * @return int[]
     */
    private static function urlToSize(string $s): array
    {
        $pos = strpos($s, 'x');
        if ($pos === false) {
            $v = (int) $s;
            return [$v, $v];
        }
        return [(int) substr($s, 0, $pos), (int) substr($s, $pos + 1)];
    }

    private static function joinRootPath(string $rootPath, string $relPath): string
    {
        if (str_starts_with($relPath, './')) {
            $relPath = substr($relPath, 2);
        } else {
            $relPath = ltrim($relPath, '/');
        }
        return rtrim($rootPath, '/') . '/' . $relPath;
    }

    private static function ensureDir(string $dir): bool
    {
        if (function_exists('mkgetdir')) {
            return (bool) mkgetdir($dir);
        }
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    private static function safeDestroy(object $image): void
    {
        // PHP 8.5 deprecates imagedestroy(), which Piwigo's image_gd::destroy() still calls unconditionally.
        $library = property_exists($image, 'library') ? (string) $image->library : '';
        if (PHP_VERSION_ID >= 80500 && $library === 'gd') {
            return;
        }
        if ($image instanceof pwg_image || method_exists($image, 'destroy')) {
            $image->destroy();
        }
    }
}
