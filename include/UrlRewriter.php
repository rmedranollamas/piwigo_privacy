<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Idempotent URL helper used ONLY when standalone PHP delivery mode (or explicit URL rewriting)
 * or Piwigo-private_share token propagation is active. Never wraps SrcImage in a proxy.
 */
final class UrlRewriter
{
    public static function modifyUrl(string $url, int|string|null $id, Config $config, string $rootUrl): string
    {
        if ($url === '' || str_contains($url, 'action.php')) {
            return $url;
        }

        // Never rewrite theme/plugin static icons ($id === null) or remote CDNs.
        if ($id === null || $id === '' || !is_numeric((string) $id)) {
            return $url;
        }
        $intId = (int) $id;
        if ($intId <= 0) {
            return $url;
        }

        $getFragment = 'plugins/' . $config->pluginDirName . '/get.php?';
        if (str_contains($url, $getFragment)) {
            return $url;
        }

        [$prefixRoot, $rel] = self::splitRootPrefix($url, $rootUrl);
        if ($prefixRoot === null) {
            // Remote external URL outside gallery root.
            return $url;
        }

        // Prevent double-rewriting legacy /<id>/... prefix URLs (including custom upload_dir roots).
        $quotedRoots = array_map(
            static fn (string $r): string => preg_quote($r, '#'),
            $config->getAllowedMediaRoots(true)
        );
        $prefixPattern = '#^\d+/(?:' . implode('|', $quotedRoots) . '|i\.php|i(?:/|\?|$))#';
        if (preg_match($prefixPattern, $rel) === 1) {
            return $url;
        }

        if (!$config->rewriteUrls) {
            return $url;
        }

        if (str_starts_with($rel, '../') && $config->uploadDir !== null && str_starts_with($config->uploadDir, '../')) {
            $rel = substr($rel, 3);
        }

        if ($config->mode === Config::MODE_NGINX) {
            return $prefixRoot . $intId . '/' . $rel;
        }

        return $prefixRoot
            . 'plugins/' . $config->pluginDirName . '/get.php?img_id=' . $intId
            . '&file=' . rawurlencode($rel);
    }

    public static function isExternalUrl(string $url, string $rootUrl): bool
    {
        if ($url === '') {
            return false;
        }
        return self::splitRootPrefix($url, $rootUrl)[0] === null;
    }

    /**
     * Appends the Piwigo-private_share UUID token so unauthenticated share recipients can view images.
     */
    public static function appendPshareToken(string $url, string $pshareKey): string
    {
        if ($url === '' || preg_match('/^[a-zA-Z0-9]{30}$/', $pshareKey) !== 1) {
            return $url;
        }
        if (preg_match('/(?:^|[?&])pshare=/', $url) === 1) {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'pshare=' . $pshareKey;
    }

    /**
     * Splits a URL into [$rootPrefixForPrepend, $relativePathWithinGallery],
     * or [null, $url] if the URL points to a different host.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function splitRootPrefix(string $url, string $rootUrl): array
    {
        if ($rootUrl !== '' && str_starts_with($url, $rootUrl)) {
            $rel = self::stripLeadingDotSlash(substr($url, strlen($rootUrl)));
            if ($rootUrl === './') {
                return ['./', $rel];
            }
            return [rtrim($rootUrl, '/') . '/', $rel];
        }

        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, 'data:')) {
            return [null, $url];
        }

        $rootPathOnly = parse_url($rootUrl, PHP_URL_PATH);
        if (is_string($rootPathOnly) && $rootPathOnly !== '' && $rootPathOnly !== '/') {
            $normalizedRootPath = '/' . trim($rootPathOnly, '/') . '/';
            if (str_starts_with($url, $normalizedRootPath)) {
                $rel = self::stripLeadingDotSlash(substr($url, strlen($normalizedRootPath)));
                return [rtrim($rootUrl, '/') . '/', $rel];
            }
        }

        $rel = self::stripLeadingDotSlash($url);

        if ($rootUrl === './') {
            return ['./', $rel];
        }
        if ($rootUrl === '' || $rootUrl === '/') {
            return [$rootUrl === '/' ? '/' : '', $rel];
        }
        return [rtrim($rootUrl, '/') . '/', $rel];
    }

    private static function stripLeadingDotSlash(string $path): string
    {
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return ltrim($path, '/');
    }
}
