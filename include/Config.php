<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Normalized plugin configuration derived from Piwigo's global $conf array.
 */
final class Config
{
    public const MODE_NGINX = 'nginx';
    public const MODE_PHP = 'php';
    public const DEFAULT_CACHE_CONTROL = 'private, max-age=300, must-revalidate';
    public const DEFAULT_CORP = 'same-origin';

    public string $mode;
    public ?string $redirectHeader;
    public string $internalPrefix;
    public bool $rewriteUrls;
    public bool $allowWhitespaces;
    public bool $allowSpecialChars;
    public bool $debug;
    public bool $defaultRedirect;
    public ?string $defaultImage;
    public bool $stripMetadata;
    public string $cacheControl;
    public string $corp;
    public bool $logDenials;
    public string $dataLocation;
    public string $derivativeDir;
    public string $syncCharsRegex;
    public string $pluginDirName;
    public ?string $basePath;
    public int $stripMetadataThreshold;
    public bool $showExif;
    public bool $enableFormats;
    public bool $guestAccess;
    public ?string $uploadDir;
    /** @var string[] */
    public array $pictureExt;
    /** @var string[] */
    public array $fileExt;
    /** @var string[] */
    public array $formatExt;
    /** @var array<string, string> */
    public array $mimeTypesByExt;

    /**
     * @param array<string, mixed> $conf
     */
    public static function fromPiwigoConf(array $conf, string $pluginDirName = 'piwigo_privacy'): self
    {
        $instance = new self();
        $instance->pluginDirName = $pluginDirName !== '' ? $pluginDirName : 'piwigo_privacy';

        $rawHeader = $conf['piwigo_privacy_redirect_header'] ?? null;
        $redirectHeader = null;
        if (is_string($rawHeader) && $rawHeader !== '' && preg_match('/^[A-Za-z0-9-]+$/', $rawHeader) === 1) {
            $redirectHeader = $rawHeader;
        }

        $explicitMode = $conf['piwigo_privacy_mode'] ?? null;
        if ($explicitMode === self::MODE_NGINX) {
            $instance->mode = self::MODE_NGINX;
            $instance->redirectHeader = $redirectHeader ?? 'X-Accel-Redirect';
        } elseif ($explicitMode === self::MODE_PHP) {
            $instance->mode = self::MODE_PHP;
            $instance->redirectHeader = null;
        } elseif ($redirectHeader !== null) {
            $instance->mode = self::MODE_NGINX;
            $instance->redirectHeader = $redirectHeader;
        } else {
            $instance->mode = self::MODE_PHP;
            $instance->redirectHeader = null;
        }

        $rawPrefix = $conf['piwigo_privacy_internal_prefix'] ?? '/_pwg_priv/';
        if (!is_string($rawPrefix) || preg_match('/[\r\n]/', $rawPrefix) === 1) {
            $rawPrefix = '/_pwg_priv/';
        }
        $trimmedPrefix = trim($rawPrefix, '/');
        $instance->internalPrefix = $trimmedPrefix === '' ? '/' : '/' . $trimmedPrefix . '/';

        $defaultRewrite = ($instance->mode === self::MODE_PHP);
        if (array_key_exists('piwigo_privacy_rewrite_urls', $conf)) {
            $instance->rewriteUrls = self::toBool($conf['piwigo_privacy_rewrite_urls'], $defaultRewrite);
        } else {
            $instance->rewriteUrls = $defaultRewrite;
        }

        $instance->allowWhitespaces = self::toBool($conf['piwigo_privacy_allow_whitespaces'] ?? false);
        $instance->allowSpecialChars = self::toBool($conf['piwigo_privacy_allow_special_chars'] ?? false);
        $instance->debug = self::toBool($conf['piwigo_privacy_debug'] ?? false);
        $instance->defaultRedirect = self::toBool($conf['piwigo_privacy_default_redirect'] ?? false);

        $defaultImg = $conf['piwigo_privacy_default_image'] ?? null;
        $instance->defaultImage = (is_string($defaultImg) && $defaultImg !== '' && preg_match('/[\r\n]/', $defaultImg) !== 1)
            ? $defaultImg
            : null;

        $instance->stripMetadata = self::toBool($conf['piwigo_privacy_strip_metadata'] ?? false);

        $cc = $conf['piwigo_privacy_cache_control'] ?? self::DEFAULT_CACHE_CONTROL;
        $instance->cacheControl = (is_string($cc) && $cc !== '' && preg_match('/[\r\n]/', $cc) !== 1)
            ? $cc
            : self::DEFAULT_CACHE_CONTROL;

        $corp = $conf['piwigo_privacy_corp'] ?? self::DEFAULT_CORP;
        $instance->corp = (is_string($corp) && $corp !== '' && preg_match('/[\r\n]/', $corp) !== 1)
            ? $corp
            : self::DEFAULT_CORP;

        $instance->logDenials = self::toBool($conf['piwigo_privacy_log_denials'] ?? false);

        $dataLoc = $conf['data_location'] ?? '_data/';
        $dataLocStr = is_string($dataLoc) && $dataLoc !== '' ? $dataLoc : '_data/';
        if (str_starts_with($dataLocStr, './')) {
            $dataLocStr = substr($dataLocStr, 2);
        }
        $dataLocNorm = trim($dataLocStr, '/');
        $instance->dataLocation = ($dataLocNorm === '' ? '_data' : $dataLocNorm) . '/';

        if (defined('PWG_DERIVATIVE_DIR')) {
            $derivStr = (string) constant('PWG_DERIVATIVE_DIR');
            if (str_starts_with($derivStr, './')) {
                $derivStr = substr($derivStr, 2);
            }
            $derivTrim = trim($derivStr, '/');
            $instance->derivativeDir = ($derivTrim === '' ? $instance->dataLocation . 'i' : $derivTrim) . '/';
        } else {
            $instance->derivativeDir = $instance->dataLocation . 'i/';
        }

        $syncRegex = $conf['sync_chars_regex'] ?? '/^[a-zA-Z0-9-_.]+$/';
        $instance->syncCharsRegex = (is_string($syncRegex) && $syncRegex !== '')
            ? $syncRegex
            : '/^[a-zA-Z0-9-_.]+$/';

        $basePath = $conf['piwigo_privacy_base_path'] ?? null;
        if (is_string($basePath) && $basePath !== '') {
            $trimmedBase = trim($basePath, '/');
            $instance->basePath = $trimmedBase === '' ? '/' : '/' . $trimmedBase . '/';
        } else {
            $instance->basePath = null;
        }

        $threshold = $conf['derivatives_strip_metadata_threshold'] ?? 256000;
        $instance->stripMetadataThreshold = is_numeric($threshold) ? (int) $threshold : 256000;
        $instance->showExif = self::toBool($conf['show_exif'] ?? true, true);
        $instance->enableFormats = self::toBool($conf['enable_formats'] ?? false);
        $instance->guestAccess = self::toBool($conf['guest_access'] ?? true, true);

        $uploadDir = $conf['upload_dir'] ?? './upload';
        $instance->uploadDir = is_string($uploadDir) && $uploadDir !== '' ? rtrim($uploadDir, '/') : null;

        $picExt = $conf['picture_ext'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $instance->pictureExt = is_array($picExt)
            ? array_values(array_unique(array_map(static fn (mixed $e): string => strtolower((string) $e), $picExt)))
            : ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $fileExt = $conf['file_ext'] ?? array_merge(
            $instance->pictureExt,
            ['tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic']
        );
        $instance->fileExt = is_array($fileExt)
            ? array_values(array_unique(array_map(static fn (mixed $e): string => strtolower((string) $e), $fileExt)))
            : $instance->pictureExt;

        $formatExt = $conf['format_ext'] ?? ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        $instance->formatExt = is_array($formatExt)
            ? array_values(array_unique(array_map(static fn (mixed $e): string => strtolower((string) $e), $formatExt)))
            : ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];

        $mimeMap = [];
        if (isset($conf['mime_types_for_ext']) && is_array($conf['mime_types_for_ext'])) {
            foreach ($conf['mime_types_for_ext'] as $ext => $types) {
                $extKey = strtolower((string) $ext);
                if (is_array($types) && isset($types[0]) && is_string($types[0])) {
                    $mimeMap[$extKey] = $types[0];
                } elseif (is_string($types) && $types !== '') {
                    $mimeMap[$extKey] = $types;
                }
            }
        }
        $instance->mimeTypesByExt = $mimeMap;

        return $instance;
    }

    /**
     * Returns allowed first path segments inside Piwigo root for media delivery.
     *
     * @return string[]
     */
    public function getAllowedMediaRoots(bool $includeThemesAndPlugins = false): array
    {
        $roots = ['upload', 'galleries'];

        $dataRoot = explode('/', trim($this->dataLocation, '/'))[0];
        if ($dataRoot !== '' && !in_array($dataRoot, $roots, true)) {
            $roots[] = $dataRoot;
        }

        if ($this->uploadDir !== null) {
            $trimmed = $this->uploadDir;
            while (str_starts_with($trimmed, './') || str_starts_with($trimmed, '../')) {
                $trimmed = str_starts_with($trimmed, '../') ? substr($trimmed, 3) : substr($trimmed, 2);
            }
            $trimmed = ltrim($trimmed, '/');
            if ($trimmed !== '' && $trimmed !== '.' && $trimmed !== '..') {
                $first = explode('/', $trimmed)[0];
                if ($first !== '' && $first !== '.' && $first !== '..' && !in_array($first, $roots, true)) {
                    $roots[] = $first;
                }
            }
        }

        if ($includeThemesAndPlugins) {
            $roots[] = 'themes';
            $roots[] = 'plugins';
        }

        return $roots;
    }

    private static function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $norm = strtolower(trim($value));
            if (in_array($norm, ['1', 'true', 'y', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($norm, ['', '0', 'false', 'n', 'no', 'off'], true)) {
                return false;
            }
            return true;
        }
        return (bool) $value;
    }
}
