<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

use DerivativeImage;
use SrcImage;

/**
 * Resolves parsed requests to DB records or theme assets using indexed lookups,
 * enforcing Piwigo privacy levels, album ACLs, admin lounge/orphan access,
 * Piwigo-private_share tokens, pwg_format sidecars, and HD/representative rules.
 */
final class AccessVerifier
{
    /** @var string[] Photo/RAW formats whose pwg_representative holds a full-res still image */
    private const HIGH_RES_REP_SOURCE_EXTS = [
        'tif', 'tiff', 'heic', 'heif', 'cr2', 'nef', 'dng', 'arw', 'orf', 'rw2', 'psd', 'eps', 'ai',
    ];

    /**
     * @param array<string, mixed> $user
     * @throws HttpException
     */
    public static function verify(
        ParsedRequest $req,
        Config $config,
        array $user,
        string $rootPath,
    ): ResolvedMedia {
        $isAdmin = function_exists('is_admin') ? (bool) is_admin() : false;
        $isGuest = function_exists('is_a_guest') ? (bool) is_a_guest() : true;
        $denyCode = $isGuest ? 401 : 403;

        $enabledHigh = $isAdmin || self::isHighResEnabled($user);

        if ($req->isDerivativeRequest) {
            $parsed = DerivativeGenerator::parseRequestPath($req->normalizedPath, $config);

            if ($parsed->isThemeOrPluginSource) {
                self::assertAllowedThemeOrPluginAsset($parsed, $config, $rootPath);
                return new ResolvedMedia(
                    kind: ResolvedMedia::KIND_DERIVATIVE_THEME,
                    serveRelPath: $parsed->derivativeRelPath,
                    elementInfo: null,
                    parsedDerivative: $parsed,
                    enabledHigh: $enabledHigh,
                );
            }

            if ($parsed->isRepresentativeSource) {
                $candidates = self::findImagesByRepresentativePath($parsed->srcRelPath, $req->imgId);
            } else {
                $found = self::findImageByPath($parsed->srcRelPath, $req->imgId, $config);
                $candidates = $found !== null ? [$found] : [];
            }

            if ($candidates === []) {
                throw new HttpException($denyCode, 'Access Denied');
            }

            $authMatch = self::authorizeFirstCandidate(
                candidates: $candidates,
                isAdmin: $isAdmin,
                user: $user,
                config: $config,
                isGuest: $isGuest,
                pshareKey: $req->pshareKey,
                denyCode: $denyCode,
                allowPshare: true,
                pshareDerivativeType: $parsed->type,
            );

            return new ResolvedMedia(
                kind: ResolvedMedia::KIND_DERIVATIVE,
                serveRelPath: $parsed->derivativeRelPath,
                elementInfo: $authMatch['elementInfo'],
                parsedDerivative: $parsed,
                enabledHigh: $enabledHigh,
                grantedViaPshare: $authMatch['pshareGranted'],
            );
        }

        $normPath = $req->normalizedPath;
        $parentDirName = basename(dirname($normPath));

        if ($parentDirName === 'pwg_representative') {
            $candidates = self::findImagesByRepresentativePath($normPath, $req->imgId);
            if ($candidates === []) {
                throw new HttpException($denyCode, 'Access Denied');
            }

            $authMatch = self::authorizeFirstCandidate(
                candidates: $candidates,
                isAdmin: $isAdmin,
                user: $user,
                config: $config,
                isGuest: $isGuest,
                pshareKey: $req->pshareKey,
                denyCode: $denyCode,
                allowPshare: true,
                pshareDerivativeType: IMG_MEDIUM,
                checkRepresentativeHd: true,
                enabledHigh: $enabledHigh,
            );
            $elementInfo = $authMatch['elementInfo'];
            $pshareGranted = $authMatch['pshareGranted'];

            $repExt = (string) ($elementInfo['representative_ext'] ?? '');
            $canonicalRep = self::originalToRepresentative(
                self::preserveDbPrefix((string) $elementInfo['path']),
                $repExt
            );

            return new ResolvedMedia(
                kind: ResolvedMedia::KIND_REPRESENTATIVE,
                serveRelPath: $canonicalRep,
                elementInfo: $elementInfo,
                parsedDerivative: null,
                enabledHigh: $enabledHigh,
                grantedViaPshare: $pshareGranted,
            );
        }

        if ($parentDirName === 'pwg_format') {
            if (!$config->enableFormats || !defined('IMAGE_FORMAT_TABLE')) {
                throw new HttpException($denyCode, 'Formats disabled');
            }

            $matches = self::findImagesByFormatPath($normPath, $req->imgId);
            if ($matches === []) {
                throw new HttpException($denyCode, 'Access Denied');
            }

            $lastError = null;
            $authorizedFormat = null;
            foreach ($matches as $matched) {
                try {
                    self::authorizeImageAccess(
                        elementInfo: $matched['image'],
                        isAdmin: $isAdmin,
                        user: $user,
                        config: $config,
                        isGuest: $isGuest,
                        pshareKey: null, // Formats are HD/RAW sidecars, not direct inline pshare assets
                        denyCode: $denyCode,
                        allowPshare: false,
                        pshareDerivativeType: null,
                    );
                    $authorizedFormat = $matched;
                    break;
                } catch (HttpException $e) {
                    $lastError = $e;
                }
            }

            if ($authorizedFormat === null) {
                throw $lastError;
            }

            if (!$enabledHigh) {
                throw new HttpException($denyCode, 'High resolution access denied');
            }

            $elementInfo = $authorizedFormat['image'];
            $formatExt = $authorizedFormat['ext'];

            $canonicalFormat = self::originalToFormat(
                self::preserveDbPrefix((string) $elementInfo['path']),
                $formatExt
            );

            return new ResolvedMedia(
                kind: ResolvedMedia::KIND_FORMAT,
                serveRelPath: $canonicalFormat,
                elementInfo: $elementInfo,
                parsedDerivative: null,
                enabledHigh: $enabledHigh,
            );
        }

        // Direct original asset (./upload/... or ./galleries/...)
        $elementInfo = self::findImageByPath($normPath, $req->imgId, $config);
        if ($elementInfo === null) {
            throw new HttpException($denyCode, 'Access Denied');
        }

        $pshareGranted = self::authorizeImageAccess(
            elementInfo: $elementInfo,
            isAdmin: $isAdmin,
            user: $user,
            config: $config,
            isGuest: $isGuest,
            pshareKey: $req->pshareKey,
            denyCode: $denyCode,
            allowPshare: true,
            pshareDerivativeType: 'Original',
        );

        $srcImage = new SrcImage($elementInfo);
        if ($pshareGranted) {
            $med = DerivativeImage::get_one(IMG_MEDIUM, $srcImage);
            if ($med === null || !$med->same_as_source()) {
                throw new HttpException($denyCode, 'Shared access limited to medium derivative');
            }
        } elseif ($srcImage->is_original() && !$enabledHigh) {
            // Only apply enabled_high gate when is_original() is true (photos), matching Piwigo action.php:125.
            // Videos/audio/PDFs are allowed for standard users; photos allowed only if <= largest enabled derivative.
            $largest = DerivativeImage::get_one(defined('IMG_4XLARGE') ? IMG_4XLARGE : IMG_XXLARGE, $srcImage);
            if ($largest === null || !$largest->same_as_source()) {
                throw new HttpException($denyCode, 'High resolution access denied');
            }
        }

        $canonicalOriginal = self::preserveDbPrefix((string) $elementInfo['path']);

        return new ResolvedMedia(
            kind: ResolvedMedia::KIND_ORIGINAL,
            serveRelPath: $canonicalOriginal,
            elementInfo: $elementInfo,
            parsedDerivative: null,
            enabledHigh: $enabledHigh,
            grantedViaPshare: $pshareGranted,
        );
    }

    /**
     * Checks whether an unchanging derivative fallback (no-op resize returning original)
     * violates enabled_high or pshare restrictions for non-HD users.
     */
    public static function assertDerivativeFallbackPermitted(ResolvedMedia $media, DerivativeResult $result): void
    {
        if (!$result->unchangedFallback || $media->enabledHigh || $media->elementInfo === null) {
            return;
        }

        $srcImage = new SrcImage($media->elementInfo);
        $isRep = $media->parsedDerivative !== null && $media->parsedDerivative->isRepresentativeSource;
        $srcExt = strtolower(pathinfo((string) ($media->elementInfo['path'] ?? ''), PATHINFO_EXTENSION));
        $isHighResRep = $isRep && self::isHighResRepSourceExt($srcExt);

        if ($srcImage->is_original() || $isHighResRep || $media->grantedViaPshare) {
            $limitType = $media->grantedViaPshare
                ? IMG_MEDIUM
                : (defined('IMG_4XLARGE') ? IMG_4XLARGE : IMG_XXLARGE);
            $limitDeriv = DerivativeImage::get_one($limitType, $srcImage);
            if ($limitDeriv === null || !$limitDeriv->same_as_source()) {
                $isGuest = function_exists('is_a_guest') ? (bool) is_a_guest() : true;
                throw new HttpException($isGuest ? 401 : 403, 'High resolution fallback denied');
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isHighResEnabled(array $user): bool
    {
        return self::toBool($user['enabled_high'] ?? false);
    }

    public static function isHighResRepSourceExt(string $ext): bool
    {
        return in_array(strtolower($ext), self::HIGH_RES_REP_SOURCE_EXTS, true);
    }

    /**
     * @throws HttpException
     */
    private static function assertAllowedThemeOrPluginAsset(
        ParsedDerivative $parsed,
        Config $config,
        string $rootPath,
    ): void {
        $ext = strtolower(ltrim($parsed->ext, '.'));
        if (!in_array($ext, $config->pictureExt, true)) {
            throw new HttpException(403, 'Disallowed extension for theme/plugin derivative');
        }

        $relNoDot = substr($parsed->srcRelPath, 2);
        // Never allow derivative generation from piwigo_privacy's own files.
        if (str_starts_with($relNoDot, 'plugins/' . $config->pluginDirName . '/')) {
            throw new HttpException(403, 'Access denied to plugin internals');
        }

        $allowedBase = str_starts_with($relNoDot, 'themes/') ? 'themes' : 'plugins';
        $baseReal = @realpath(rtrim($rootPath, '/') . '/' . $allowedBase);
        $targetReal = @realpath(rtrim($rootPath, '/') . '/' . $relNoDot);

        if ($baseReal === false || $targetReal === false || !is_file($targetReal)) {
            throw new HttpException(404, 'Theme/plugin source image not found');
        }

        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($targetReal, $basePrefix)) {
            throw new HttpException(403, 'Theme/plugin path escapes allowed root');
        }
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $user
     * @return array{elementInfo: array<string, mixed>, pshareGranted: bool}
     * @throws HttpException
     */
    private static function authorizeFirstCandidate(
        array $candidates,
        bool $isAdmin,
        array $user,
        Config $config,
        bool $isGuest,
        ?string $pshareKey,
        int $denyCode,
        bool $allowPshare,
        ?string $pshareDerivativeType,
        bool $checkRepresentativeHd = false,
        bool $enabledHigh = false,
    ): array {
        $lastError = null;
        foreach ($candidates as $candidate) {
            try {
                $pshareGranted = self::authorizeImageAccess(
                    elementInfo: $candidate,
                    isAdmin: $isAdmin,
                    user: $user,
                    config: $config,
                    isGuest: $isGuest,
                    pshareKey: $pshareKey,
                    denyCode: $denyCode,
                    allowPshare: $allowPshare,
                    pshareDerivativeType: $pshareDerivativeType,
                );

                if ($checkRepresentativeHd) {
                    $srcExt = strtolower(pathinfo((string) ($candidate['path'] ?? ''), PATHINFO_EXTENSION));
                    $isHighResRepSource = self::isHighResRepSourceExt($srcExt);
                    if ($pshareGranted) {
                        if ($isHighResRepSource) {
                            $srcImage = new SrcImage($candidate);
                            $med = DerivativeImage::get_one(IMG_MEDIUM, $srcImage);
                            if ($med === null || !$med->same_as_source()) {
                                throw new HttpException($denyCode, 'Shared access limited to medium derivative');
                            }
                        }
                    } elseif (!$enabledHigh && $isHighResRepSource) {
                        $srcImage = new SrcImage($candidate);
                        $largest = DerivativeImage::get_one(defined('IMG_4XLARGE') ? IMG_4XLARGE : IMG_XXLARGE, $srcImage);
                        if ($largest === null || !$largest->same_as_source()) {
                            throw new HttpException($denyCode, 'High resolution access denied');
                        }
                    }
                }

                return [
                    'elementInfo' => $candidate,
                    'pshareGranted' => $pshareGranted,
                ];
            } catch (HttpException $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new HttpException($denyCode, 'Access Denied');
    }

    /**
     * @param array<string, mixed> $elementInfo
     * @param array<string, mixed> $user
     * @throws HttpException
     */
    private static function authorizeImageAccess(
        array $elementInfo,
        bool $isAdmin,
        array $user,
        Config $config,
        bool $isGuest,
        ?string $pshareKey,
        int $denyCode,
        bool $allowPshare,
        ?string $pshareDerivativeType,
    ): bool {
        // Admins always retain access (covers piwigo_lounge and Batch Manager orphans with zero category links).
        if ($isAdmin) {
            return false;
        }

        $imageId = (int) $elementInfo['id'];

        if ($allowPshare && $pshareKey !== null && self::hasValidPshareKey($imageId, $pshareKey)) {
            $allowedPshareTypes = [
                IMG_SQUARE, IMG_THUMB, IMG_XXSMALL, IMG_XSMALL, IMG_SMALL, IMG_MEDIUM, 'Original',
            ];
            if ($pshareDerivativeType !== null && !in_array($pshareDerivativeType, $allowedPshareTypes, true)) {
                throw new HttpException($denyCode, 'Derivative size exceeds private share scope');
            }
            return true;
        }

        // Enforce guest_access=false cleanly with 401 instead of core check_status()/access_denied() HTML redirects.
        if (function_exists('is_autorize_status')) {
            $requiredAccess = defined('ACCESS_GUEST') ? ACCESS_GUEST : 1;
            if (!is_autorize_status($requiredAccess)) {
                throw new HttpException($denyCode, 'Guest access disabled');
            }
        } elseif (!$config->guestAccess && $isGuest) {
            throw new HttpException($denyCode, 'Guest access disabled');
        }

        $userLevel = isset($user['level']) && is_numeric($user['level']) ? (int) $user['level'] : 0;
        $imageLevel = isset($elementInfo['level']) && is_numeric($elementInfo['level']) ? (int) $elementInfo['level'] : 0;
        if ($imageLevel > $userLevel) {
            throw new HttpException($denyCode, 'Access Denied');
        }

        $fandf = '';
        if (function_exists('get_sql_condition_FandF')) {
            // Passing 'ic.image_id' enforces both category exclusions and per-image image_access_list ACLs.
            $fandf = (string) get_sql_condition_FandF(
                [
                    'forbidden_categories' => 'ic.category_id',
                    'forbidden_images' => 'ic.image_id',
                ],
                ' AND '
            );
        }

        $query = 'SELECT 1'
            . ' FROM `' . CATEGORIES_TABLE . '` AS c'
            . ' INNER JOIN `' . IMAGE_CATEGORY_TABLE . '` AS ic ON ic.category_id = c.id'
            . ' INNER JOIN `' . IMAGES_TABLE . '` AS i ON i.id = ic.image_id'
            . ' WHERE ic.image_id = ' . $imageId
            . $fandf
            . ' LIMIT 1;';

        $row = self::fetchAssoc($query);
        if ($row === null) {
            throw new HttpException($denyCode, 'Access Denied');
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findImageByPath(string $normPath, ?int $expectedId, Config $config): ?array
    {
        $withoutDot = str_starts_with($normPath, './') ? substr($normPath, 2) : $normPath;
        $candidates = [$normPath, $withoutDot];
        if ($config->uploadDir !== null && str_starts_with($config->uploadDir, '../')) {
            $candidates[] = '../' . $withoutDot;
        }

        $quoted = array_map(static fn (string $c): string => "'" . self::escape($c) . "'", array_unique($candidates));
        $idClause = $expectedId !== null ? ' AND id = ' . $expectedId : '';

        $query = 'SELECT * FROM `' . IMAGES_TABLE . '` WHERE path IN (' . implode(', ', $quoted) . ')' . $idClause . ' LIMIT 5;';
        $rows = self::fetchAll($query);

        foreach ($rows as $row) {
            $rowPath = isset($row['path']) && is_string($row['path']) ? $row['path'] : '';
            if ($rowPath === '') {
                continue;
            }
            $rowNorm = self::normalizeDbPath($rowPath);
            if ($rowNorm === $normPath && ($expectedId === null || (int) $row['id'] === $expectedId)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Resolves a pwg_representative path safely without dotless '.' collisions or prefix leaks (SEC-03).
     *
     * @return list<array<string, mixed>>
     */
    private static function findImagesByRepresentativePath(string $normRepPath, ?int $expectedId): array
    {
        $repDir = dirname($normRepPath);
        if (basename($repDir) !== 'pwg_representative') {
            return [];
        }

        $parentDir = dirname($repDir);
        $stem = pathinfo($normRepPath, PATHINFO_FILENAME);
        $repExt = pathinfo($normRepPath, PATHINFO_EXTENSION);
        if ($stem === '' || $repExt === '' || $parentDir === '' || $parentDir === '.') {
            return [];
        }

        $prefixWithDot = $parentDir . '/' . $stem . '.';
        $prefixNoDot = str_starts_with($prefixWithDot, './') ? substr($prefixWithDot, 2) : $prefixWithDot;
        $parentUploadPrefix = '../' . $prefixNoDot;

        $idClause = $expectedId !== null ? ' AND id = ' . $expectedId : '';
        $query = 'SELECT * FROM `' . IMAGES_TABLE . '`'
            . ' WHERE (path LIKE \'' . self::escapeLike($prefixWithDot) . '%\''
            . ' OR path LIKE \'' . self::escapeLike($prefixNoDot) . '%\''
            . ' OR path LIKE \'' . self::escapeLike($parentUploadPrefix) . '%\')'
            . ' AND representative_ext IS NOT NULL AND representative_ext <> \'\''
            . $idClause
            . ' ORDER BY LENGTH(path) ASC'
            . ' LIMIT 100;';

        $matches = [];
        $rows = self::fetchAll($query);
        foreach ($rows as $row) {
            $rowPath = isset($row['path']) && is_string($row['path']) ? self::normalizeDbPath($row['path']) : '';
            $rowRepExt = isset($row['representative_ext']) && is_string($row['representative_ext']) ? $row['representative_ext'] : '';
            if ($rowPath === '' || $rowRepExt === '') {
                continue;
            }
            // Reject dotless gallery entries whose strrpos('.') matches index 0 of './'.
            if (!str_contains(basename($rowPath), '.')) {
                continue;
            }
            $expectedRep = self::originalToRepresentative($rowPath, $rowRepExt);
            if ($expectedRep === $normRepPath && ($expectedId === null || (int) $row['id'] === $expectedId)) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * Resolves a pwg_format/<stem>.<ext> file against images + image_format tables.
     *
     * @return list<array{image: array<string, mixed>, ext: string}>
     */
    private static function findImagesByFormatPath(string $normFormatPath, ?int $expectedId): array
    {
        $fmtDir = dirname($normFormatPath);
        if (basename($fmtDir) !== 'pwg_format') {
            return [];
        }

        $parentDir = dirname($fmtDir);
        $stem = pathinfo($normFormatPath, PATHINFO_FILENAME);
        $fmtExt = pathinfo($normFormatPath, PATHINFO_EXTENSION);
        if ($stem === '' || $fmtExt === '' || $parentDir === '' || $parentDir === '.') {
            return [];
        }

        $prefixWithDot = $parentDir . '/' . $stem . '.';
        $prefixNoDot = str_starts_with($prefixWithDot, './') ? substr($prefixWithDot, 2) : $prefixWithDot;
        $parentUploadPrefix = '../' . $prefixNoDot;
        $idClause = $expectedId !== null ? ' AND id = ' . $expectedId : '';

        $query = 'SELECT * FROM `' . IMAGES_TABLE . '`'
            . ' WHERE (path LIKE \'' . self::escapeLike($prefixWithDot) . '%\''
            . ' OR path LIKE \'' . self::escapeLike($prefixNoDot) . '%\''
            . ' OR path LIKE \'' . self::escapeLike($parentUploadPrefix) . '%\')'
            . $idClause
            . ' ORDER BY LENGTH(path) ASC'
            . ' LIMIT 100;';

        $matches = [];
        $rows = self::fetchAll($query);
        foreach ($rows as $row) {
            $rowPath = isset($row['path']) && is_string($row['path']) ? self::normalizeDbPath($row['path']) : '';
            if ($rowPath === '' || !str_contains(basename($rowPath), '.')) {
                continue;
            }
            $rowId = (int) $row['id'];
            $fmtQuery = 'SELECT ext FROM `' . IMAGE_FORMAT_TABLE . '`'
                . ' WHERE image_id = ' . $rowId
                . ' AND LOWER(ext) = \'' . self::escape(strtolower($fmtExt)) . '\''
                . ' LIMIT 1;';
            $fmtRow = self::fetchAssoc($fmtQuery);
            if ($fmtRow === null || !isset($fmtRow['ext']) || !is_string($fmtRow['ext'])) {
                continue;
            }
            if (self::originalToFormat($rowPath, $fmtRow['ext']) === $normFormatPath) {
                $matches[] = [
                    'image' => $row,
                    'ext' => $fmtRow['ext'],
                ];
            }
        }

        return $matches;
    }

    private static function hasValidPshareKey(int $imageId, string $key): bool
    {
        if (!defined('PSHARE_KEYS_TABLE')) {
            return false;
        }
        $query = 'SELECT 1 FROM `' . PSHARE_KEYS_TABLE . '`'
            . ' WHERE uuid = \'' . self::escape($key) . '\''
            . ' AND image_id = ' . $imageId
            . ' AND expire_on >= NOW()'
            . ' LIMIT 1;';
        return self::fetchAssoc($query) !== null;
    }

    private static function preserveDbPrefix(string $path): string
    {
        if (str_starts_with($path, './') || str_starts_with($path, '../')) {
            return $path;
        }
        return './' . ltrim($path, '/');
    }

    private static function normalizeDbPath(string $path): string
    {
        if (str_starts_with($path, './')) {
            return $path;
        }
        if (str_starts_with($path, '../')) {
            return './' . substr($path, 3);
        }
        return './' . ltrim($path, '/');
    }

    private static function originalToRepresentative(string $path, string $ext): string
    {
        if (function_exists('original_to_representative')) {
            return (string) original_to_representative($path, $ext);
        }
        $slashPos = strrpos($path, '/');
        $withDir = $slashPos !== false
            ? substr_replace($path, 'pwg_representative/', $slashPos + 1, 0)
            : 'pwg_representative/' . $path;
        $dotPos = strrpos($withDir, '.');
        return $dotPos !== false ? substr_replace($withDir, $ext, $dotPos + 1) : $withDir . '.' . $ext;
    }

    private static function originalToFormat(string $path, string $ext): string
    {
        if (function_exists('original_to_format')) {
            return (string) original_to_format($path, $ext);
        }
        $slashPos = strrpos($path, '/');
        $withDir = $slashPos !== false
            ? substr_replace($path, 'pwg_format/', $slashPos + 1, 0)
            : 'pwg_format/' . $path;
        $dotPos = strrpos($withDir, '.');
        return $dotPos !== false ? substr_replace($withDir, $ext, $dotPos + 1) : $withDir . '.' . $ext;
    }

    private static function toBool(mixed $value): bool
    {
        if (function_exists('get_boolean')) {
            return (bool) get_boolean($value);
        }
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function escape(string $value): string
    {
        return function_exists('pwg_db_real_escape_string')
            ? (string) pwg_db_real_escape_string($value)
            : addslashes($value);
    }

    private static function escapeLike(string $value): string
    {
        return self::escape(addcslashes($value, '\\%_'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchAssoc(string $query): ?array
    {
        $res = pwg_query($query);
        $row = pwg_db_fetch_assoc($res);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchAll(string $query): array
    {
        $res = pwg_query($query);
        $rows = [];
        while (is_array($row = pwg_db_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }
}
