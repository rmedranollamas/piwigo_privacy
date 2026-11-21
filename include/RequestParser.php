<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Parses and canonicalizes incoming HTTP requests (canonical Nginx paths,
 * i.php / clean /i derivative URLs, subdirectory mounts, legacy /<id>/... URLs,
 * and standalone PHP ?img_id=...&file=... query mode).
 */
final class RequestParser
{
    /**
     * @param array<string, mixed> $server
     * @throws HttpException on invalid syntax, non-numeric IDs, or path traversal attempts
     */
    public static function parse(array $server, Config $config): ParsedRequest
    {
        $rawUri = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : '';

        if ($rawUri === '') {
            throw new HttpException(400, 'Empty request URI');
        }

        // Handle HTTP/1.1 absolute-form request targets without relying on FILTER_SANITIZE_URL.
        if (preg_match('#^https?://[^/?\#]+(.*)$#i', $rawUri, $absMatch) === 1) {
            $rawUri = $absMatch[1] === '' ? '/' : $absMatch[1];
        }

        $hashPos = strpos($rawUri, '#');
        if ($hashPos !== false) {
            $rawUri = substr($rawUri, 0, $hashPos);
        }

        $qPos = strpos($rawUri, '?');
        if ($qPos !== false) {
            $rawUriPath = substr($rawUri, 0, $qPos);
            $rawQuery = substr($rawUri, $qPos + 1);
        } else {
            $rawUriPath = $rawUri;
            $rawQuery = '';
        }

        $basePath = self::resolveBasePath($server, $config);
        if ($basePath !== '/') {
            if (!str_starts_with($rawUriPath, $basePath)) {
                throw new HttpException(400, 'Request URI outside configured base path');
            }
            $relUriPath = substr($rawUriPath, strlen($basePath));
        } else {
            $relUriPath = ltrim($rawUriPath, '/');
        }

        $queryParams = [];
        if ($rawQuery !== '') {
            parse_str($rawQuery, $queryParams);
        }

        $ajaxload = isset($queryParams['ajaxload']) && $queryParams['ajaxload'] === 'true';
        $cacheBuster = array_key_exists('b', $queryParams);
        $pshareKey = (isset($queryParams['pshare']) && is_string($queryParams['pshare'])
            && preg_match('/^[a-zA-Z0-9]{30}$/', $queryParams['pshare']) === 1)
            ? $queryParams['pshare']
            : null;

        $imgId = null;
        $isDerivative = false;
        $candidatePath = '';

        $getEntryRel = 'plugins/' . $config->pluginDirName . '/get.php';

        if ($relUriPath === $getEntryRel) {
            // Standalone PHP query mode: get.php?img_id=42&file=...
            if (!isset($queryParams['file']) || !is_string($queryParams['file']) || $queryParams['file'] === '') {
                throw new HttpException(400, 'Missing file parameter');
            }

            if (isset($queryParams['img_id'])) {
                if (!is_string($queryParams['img_id']) || preg_match('/^[1-9]\d*$/', $queryParams['img_id']) !== 1) {
                    throw new HttpException(400, 'Image id must be a positive integer');
                }
                $imgId = (int) $queryParams['img_id'];
            } else {
                throw new HttpException(400, 'Missing image id');
            }

            $fileParam = $queryParams['file'];
            $innerQuery = '';
            if (preg_match('#^i(?:\.php)?(?:\?|/)(.*)$#s', $fileParam, $iMatch) === 1) {
                $inner = $iMatch[1];
                $ampPos = strpos($inner, '&');
                if ($ampPos !== false) {
                    $innerQuery = substr($inner, $ampPos + 1);
                    $inner = substr($inner, 0, $ampPos);
                }
                if ($innerQuery !== '') {
                    parse_str($innerQuery, $innerParams);
                    $ajaxload = $ajaxload || (isset($innerParams['ajaxload']) && $innerParams['ajaxload'] === 'true');
                    $cacheBuster = $cacheBuster || array_key_exists('b', $innerParams);
                    if ($pshareKey === null && isset($innerParams['pshare']) && is_string($innerParams['pshare'])
                        && preg_match('/^[a-zA-Z0-9]{30}$/', $innerParams['pshare']) === 1) {
                        $pshareKey = $innerParams['pshare'];
                    }
                }
                $candidatePath = $config->derivativeDir . ltrim($inner, '/');
                $isDerivative = true;
            } else {
                $candidatePath = $fileParam;
                $derivPrefix = trim($config->derivativeDir, '/') . '/';
                $isDerivative = str_starts_with(ltrim($candidatePath, '/'), $derivPrefix);
            }
        } else {
            // Legacy v1.x prefix URLs: /<img_id>/<rest>
            if (preg_match('#^([1-9]\d*)/(.+)$#s', $relUriPath, $prefixMatch) === 1) {
                $rest = $prefixMatch[2];
                if (self::looksLikeMediaRoute($rest, $config)) {
                    $imgId = (int) $prefixMatch[1];
                    $relUriPath = $rest;
                }
            }

            if ($relUriPath === 'i.php' || $relUriPath === 'i') {
                // Derivative query style: /i.php?/upload/...-me.jpg&ajaxload=true&b=123
                if ($rawQuery === '') {
                    throw new HttpException(400, 'Missing derivative path in i.php query');
                }
                $ampPos = strpos($rawQuery, '&');
                $firstToken = $ampPos !== false ? substr($rawQuery, 0, $ampPos) : $rawQuery;
                if ($firstToken === '' || str_contains($firstToken, '=')) {
                    throw new HttpException(400, 'Invalid derivative query format');
                }
                $decodedToken = rawurldecode($firstToken);
                $candidatePath = $config->derivativeDir . ltrim($decodedToken, '/');
                $isDerivative = true;
            } elseif (preg_match('#^i(?:\.php)?/(.+)$#s', $relUriPath, $pathInfoMatch) === 1) {
                // Clean PATH_INFO derivative style: /i.php/upload/...-me.jpg or /i/upload/...-me.jpg
                $decodedSub = rawurldecode($pathInfoMatch[1]);
                $candidatePath = $config->derivativeDir . ltrim($decodedSub, '/');
                $isDerivative = true;
            } else {
                $candidatePath = rawurldecode($relUriPath);
                $derivPrefix = trim($config->derivativeDir, '/') . '/';
                $normalizedCandidate = str_starts_with($candidatePath, './')
                    ? substr($candidatePath, 2)
                    : ltrim($candidatePath, '/');
                $isDerivative = str_starts_with($normalizedCandidate, $derivPrefix);
            }
        }

        $normalizedPath = self::sanitizeRelativePath($candidatePath, $config, $isDerivative);

        return new ParsedRequest(
            normalizedPath: $normalizedPath,
            imgId: $imgId,
            isDerivativeRequest: $isDerivative,
            ajaxload: $ajaxload,
            cacheBuster: $cacheBuster,
            pshareKey: $pshareKey,
        );
    }

    /**
     * Validates and normalizes a repository-relative path without relying on disk symlinks.
     * Rejects control characters, backslashes, dot-only traversal segments (.., ...),
     * private core folders, and unauthorized character sets while allowing filenames like photo..1.jpg.
     *
     * @throws HttpException
     */
    public static function sanitizeRelativePath(string $path, Config $config, bool $isDerivative): string
    {
        if ($path === '' || !mb_check_encoding($path, 'UTF-8')) {
            throw new HttpException(400, 'Invalid or non-UTF-8 path');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || str_contains($path, '\\')) {
            throw new HttpException(400, 'Path contains forbidden control characters or backslashes');
        }

        $working = $path;
        if (str_starts_with($working, './')) {
            $working = substr($working, 2);
        }
        $working = ltrim($working, '/');

        if ($working === '' || str_ends_with($working, '/')) {
            throw new HttpException(400, 'Empty or directory-only path');
        }

        $rawSegments = explode('/', $working);
        $cleanSegments = [];

        foreach ($rawSegments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if (preg_match('/^\.{2,}$/', $segment) === 1) {
                throw new HttpException(400, 'Path traversal segment rejected');
            }

            self::assertValidSegmentChars($segment, $config);
            $cleanSegments[] = $segment;
        }

        if (count($cleanSegments) < 2) {
            throw new HttpException(400, 'Path must reside within an allowed media directory');
        }

        $lastSegment = $cleanSegments[count($cleanSegments) - 1];
        if (!str_contains($lastSegment, '.') || str_ends_with($lastSegment, '.')) {
            throw new HttpException(400, 'Missing or empty file extension');
        }

        $joined = implode('/', $cleanSegments);

        if ($isDerivative) {
            $derivPrefix = trim($config->derivativeDir, '/') . '/';
            if (!str_starts_with($joined, $derivPrefix)) {
                throw new HttpException(400, 'Invalid derivative directory');
            }
            $insideDeriv = substr($joined, strlen($derivPrefix));
            if ($insideDeriv === '' || !str_contains($insideDeriv, '/')) {
                throw new HttpException(400, 'Invalid derivative target');
            }
            $innerRoot = explode('/', $insideDeriv)[0];
            if (!in_array($innerRoot, $config->getAllowedMediaRoots(true), true)) {
                throw new HttpException(400, 'Derivative source root is not allowed');
            }
        } else {
            $rootSeg = $cleanSegments[0];
            $allowedRoots = array_values(array_filter(
                $config->getAllowedMediaRoots(false),
                static fn (string $r): bool => $r !== explode('/', trim($config->dataLocation, '/'))[0]
            ));
            if (!in_array($rootSeg, $allowedRoots, true)) {
                throw new HttpException(400, 'Media root is not allowed');
            }
        }

        return './' . $joined;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function resolveBasePath(array $server, Config $config): string
    {
        if ($config->basePath !== null) {
            return $config->basePath;
        }

        $scriptName = isset($server['SCRIPT_NAME']) && is_string($server['SCRIPT_NAME'])
            ? $server['SCRIPT_NAME']
            : '';

        if ($scriptName !== '' && preg_match('#^(.*?)/plugins/[^/]+/get\.php$#', $scriptName, $m) === 1) {
            $trimmed = trim($m[1], '/');
            return $trimmed === '' ? '/' : '/' . $trimmed . '/';
        }

        return '/';
    }

    private static function looksLikeMediaRoute(string $rest, Config $config): bool
    {
        if (str_starts_with($rest, 'i.php')
            || str_starts_with($rest, 'i/')
            || str_starts_with($rest, 'i?')
            || str_starts_with($rest, $config->dataLocation)
        ) {
            return true;
        }

        foreach ($config->getAllowedMediaRoots(true) as $root) {
            if (str_starts_with($rest, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws HttpException
     */
    private static function assertValidSegmentChars(string $segment, Config $config): void
    {
        if ($config->allowSpecialChars) {
            // Already validated UTF-8, no control chars, no \, no /, not dot-only.
            return;
        }

        $testSegment = $config->allowWhitespaces
            ? str_replace(' ', '_', $segment)
            : $segment;

        if (@preg_match($config->syncCharsRegex, $testSegment) !== 1) {
            throw new HttpException(400, 'Path segment contains disallowed characters');
        }
    }
}
