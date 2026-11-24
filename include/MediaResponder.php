<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Emits hardened HTTP headers and serves authorized media either via
 * zero-copy Nginx internal redirect (X-Accel-Redirect) or standalone
 * HTTP/1.1–HTTP/3 PHP streaming (RFC 9110 Byte-Ranges, ETag, 304, HEAD).
 */
final class MediaResponder
{
    private const SANDBOXED_EXTS = ['svg', 'svgz', 'pdf', 'html', 'htm', 'xml'];

    /** @var array<string, string> */
    private const MIME_MAP = [
        // Images (modern 2026 formats included)
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jxl'  => 'image/jxl',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'svg'  => 'image/svg+xml',
        'svgz' => 'image/svg+xml',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
        'bmp'  => 'image/bmp',
        'ico'  => 'image/x-icon',
        // Video (standards-compliant IANA media types)
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/x-m4v',
        'mov'  => 'video/quicktime',
        'mkv'  => 'video/x-matroska',
        'webm' => 'video/webm',
        'webmv' => 'video/webm',
        'ogv'  => 'video/ogg',
        'avi'  => 'video/x-msvideo',
        'wmv'  => 'video/x-ms-wmv',
        'mpg'  => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'flv'  => 'video/x-flv',
        '3gp'  => 'video/3gpp',
        'gp3'  => 'video/3gpp',
        'gp4'  => 'video/3gpp',
        // Audio
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'flac' => 'audio/flac',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'opus' => 'audio/ogg',
        'aac'  => 'audio/aac',
        // Documents & archives
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain; charset=utf-8',
        'xml'  => 'application/xml',
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'zip'  => 'application/zip',
    ];

    /** @var string[] */
    private const RANGE_EXTS = [
        'mp4', 'm4v', 'mov', 'mkv', 'webm', 'webmv', 'ogv', 'avi', 'wmv', 'mpg', 'mpeg', 'flv', '3gp', 'gp3', 'gp4',
        'mp3', 'm4a', 'flac', 'wav', 'ogg', 'opus', 'aac',
        'pdf',
    ];

    /** @var callable(int): void */
    private $statusEmitter;
    /** @var callable(string): void */
    private $headerEmitter;
    /** @var callable(string): void */
    private $bodyEmitter;

    /**
     * @param callable(int): void|null $statusEmitter
     * @param callable(string): void|null $headerEmitter
     * @param callable(string): void|null $bodyEmitter
     */
    public function __construct(
        ?callable $statusEmitter = null,
        ?callable $headerEmitter = null,
        ?callable $bodyEmitter = null,
    ) {
        $this->statusEmitter = $statusEmitter ?? static function (int $code): void {
            http_response_code($code);
        };
        $this->headerEmitter = $headerEmitter ?? static function (string $header): void {
            header($header);
        };
        $this->bodyEmitter = $bodyEmitter ?? static function (string $chunk): void {
            echo $chunk;
        };
    }

    /**
     * Serves an authorized local file via X-Accel-Redirect (Nginx mode) or streaming PHP.
     *
     * @param array<string, mixed> $server
     * @throws HttpException
     */
    public function serveFile(
        string $relPath,
        string $rootPath,
        Config $config,
        array $server,
        bool $cacheBuster = false,
    ): void {
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $config->fileExt, true)) {
            throw new HttpException(403, 'File extension is not allowed');
        }

        $this->emitSecurityAndCacheHeaders($config, $ext, $cacheBuster);

        $mime = self::guessMimeType($ext, $config);
        ($this->headerEmitter)('Content-Type: ' . $mime);

        if ($config->mode === Config::MODE_NGINX && $config->redirectHeader !== null) {
            if (in_array($ext, ['svg', 'svgz', 'html', 'htm', 'xml'], true)) {
                $safeName = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\', "\r", "\n"], '_', basename($relPath)));
                ($this->headerEmitter)('Content-Disposition: attachment; filename="' . $safeName . '"');
            }
            $internalUri = self::buildInternalRedirectPath($relPath, $config->internalPrefix);
            ($this->headerEmitter)($config->redirectHeader . ': ' . $internalUri);
            return;
        }

        $fullPath = self::resolveDiskPath($rootPath, $relPath);
        $fp = @fopen($fullPath, 'rb');
        if ($fp === false) {
            throw new HttpException(404, 'Media file not found');
        }

        try {
            $fstat = @fstat($fp);
            if ($fstat === false) {
                throw new HttpException(500, 'Could not stat media file');
            }

            $size = (int) $fstat['size'];
            $mtime = (int) $fstat['mtime'];
            $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
            $etag = sprintf('"%x-%x"', $mtime, $size);

            ($this->headerEmitter)('Last-Modified: ' . $lastModified);
            ($this->headerEmitter)('ETag: ' . $etag);

            if ($this->isNotModified($server, $etag, $mtime)) {
                ($this->statusEmitter)(304);
                return;
            }

            $rangeSupported = in_array($ext, self::RANGE_EXTS, true);
            $method = strtoupper(isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
                ? $server['REQUEST_METHOD']
                : 'GET');
            $isHead = ($method === 'HEAD');

            if ($rangeSupported) {
                ($this->headerEmitter)('Accept-Ranges: bytes');
            }

            $rangeHeader = isset($server['HTTP_RANGE']) && is_string($server['HTTP_RANGE'])
                ? trim($server['HTTP_RANGE'])
                : '';

            if ($rangeSupported && $rangeHeader !== '' && $this->ifRangeMatches($server, $etag, $mtime)) {
                $parsedRange = self::parseRangeHeader($rangeHeader, $size);
                if ($parsedRange['status'] === 416) {
                    ($this->statusEmitter)(416);
                    ($this->headerEmitter)('Content-Range: bytes */' . $size);
                    return;
                }

                $start = $parsedRange['start'];
                $end = $parsedRange['end'];
                $length = $end - $start + 1;

                ($this->statusEmitter)(206);
                ($this->headerEmitter)("Content-Range: bytes {$start}-{$end}/{$size}");
                ($this->headerEmitter)("Content-Length: {$length}");

                if ($isHead) {
                    return;
                }

                $this->streamHandle($fp, $start, $length);
                return;
            }

            ($this->statusEmitter)(200);
            ($this->headerEmitter)("Content-Length: {$size}");

            if ($isHead || $size === 0) {
                return;
            }

            $this->streamHandle($fp, 0, $size);
        } finally {
            @fclose($fp);
        }
    }

    /**
     * Responds to thumbnails.loader.js (?ajaxload=true) JSON requests.
     */
    public function sendAjaxDerivativeJson(string $derivativeUrl): void
    {
        ($this->statusEmitter)(200);
        ($this->headerEmitter)('Cache-Control: private, no-store, no-cache, must-revalidate');
        ($this->headerEmitter)('Vary: Cookie, X-PIWIGO-API');
        ($this->headerEmitter)('X-Content-Type-Options: nosniff');
        ($this->headerEmitter)('Content-Type: application/json; charset=utf-8');
        $payload = json_encode(['url' => $derivativeUrl], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ($this->bodyEmitter)($payload);
    }

    /**
     * Handles unauthorized or malformed access consistently without caching or redirect loops.
     */
    public function sendError(HttpException $e, Config $config, string $absoluteRootUrl): void
    {
        ($this->headerEmitter)('Cache-Control: private, no-store, no-cache, must-revalidate');
        ($this->headerEmitter)('Vary: Cookie, X-PIWIGO-API');
        ($this->headerEmitter)('X-Content-Type-Options: nosniff');

        if ($config->defaultRedirect && in_array($e->statusCode, [401, 403], true)) {
            $target = $config->defaultImage;
            if ($target === null) {
                $target = rtrim($absoluteRootUrl, '/') . '/plugins/' . $config->pluginDirName . '/default.png';
            } elseif (!preg_match('#^https?://#i', $target) && !str_starts_with($target, '/')) {
                $target = rtrim($absoluteRootUrl, '/') . '/' . ltrim($target, '/');
            }

            // Use 302 (never permanent 301) so logging in immediately restores real media.
            ($this->statusEmitter)(302);
            ($this->headerEmitter)('Location: ' . $target);
            return;
        }

        $code = $e->statusCode;
        ($this->statusEmitter)($code);
        ($this->headerEmitter)('Content-Type: text/plain; charset=utf-8');

        if ($code === 401) {
            ($this->headerEmitter)('WWW-Authenticate: Piwigo realm="Piwigo"');
            ($this->bodyEmitter)('Access Denied');
            return;
        }

        $messages = [
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];
        ($this->bodyEmitter)($messages[$code] ?? 'Error');
    }

    /**
     * Builds a sanitized, segment-encoded internal URI with guaranteed single leading slash.
     */
    public static function buildInternalRedirectPath(string $relPath, string $internalPrefix): string
    {
        $cleanRel = $relPath;
        if (str_starts_with($cleanRel, './')) {
            $cleanRel = substr($cleanRel, 2);
        } elseif (str_starts_with($cleanRel, '../')) {
            $cleanRel = substr($cleanRel, 3);
        }
        $cleanRel = trim($cleanRel, '/');

        $encodedSegments = array_map(
            static fn (string $seg): string => rawurlencode($seg),
            $cleanRel === '' ? [] : explode('/', $cleanRel)
        );
        $encodedPath = implode('/', $encodedSegments);

        $prefixTrimmed = trim($internalPrefix, '/');
        if ($prefixTrimmed === '') {
            return '/' . $encodedPath;
        }
        return '/' . $prefixTrimmed . '/' . $encodedPath;
    }

    public static function guessMimeType(string $ext, Config $config): string
    {
        $ext = strtolower($ext);
        if (isset(self::MIME_MAP[$ext])) {
            return self::MIME_MAP[$ext];
        }
        if (isset($config->mimeTypesByExt[$ext])) {
            return $config->mimeTypesByExt[$ext];
        }
        return 'application/octet-stream';
    }

    /**
     * Parses a single RFC 9110 §14.1.2 byte-range spec against $size.
     *
     * @return array{status: 206, start: int, end: int}|array{status: 416}
     */
    public static function parseRangeHeader(string $rangeHeader, int $size): array
    {
        if ($size <= 0 || str_contains($rangeHeader, ',')) {
            return ['status' => 416];
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($rangeHeader), $matches) !== 1) {
            return ['status' => 416];
        }

        $startStr = $matches[1];
        $endStr = $matches[2];

        if ($startStr === '' && $endStr === '') {
            return ['status' => 416];
        }

        if ($startStr === '') {
            // Suffix byte-range: bytes=-N
            $suffix = (int) $endStr;
            if ($suffix <= 0) {
                return ['status' => 416];
            }
            return [
                'status' => 206,
                'start' => max(0, $size - $suffix),
                'end' => $size - 1,
            ];
        }

        $start = (int) $startStr;
        if ($start >= $size) {
            return ['status' => 416];
        }

        if ($endStr === '') {
            // Open-ended byte-range: bytes=M-
            return [
                'status' => 206,
                'start' => $start,
                'end' => $size - 1,
            ];
        }

        $end = (int) $endStr;
        if ($start > $end) {
            return ['status' => 416];
        }

        return [
            'status' => 206,
            'start' => $start,
            'end' => min($end, $size - 1),
        ];
    }

    private function emitSecurityAndCacheHeaders(Config $config, string $ext, bool $cacheBuster): void
    {
        $cacheControl = $cacheBuster ? 'private, no-store, max-age=0' : $config->cacheControl;
        ($this->headerEmitter)('Cache-Control: ' . $cacheControl);
        ($this->headerEmitter)('Vary: Cookie, X-PIWIGO-API');
        ($this->headerEmitter)('X-Content-Type-Options: nosniff');
        ($this->headerEmitter)('Cross-Origin-Resource-Policy: ' . $config->corp);
        ($this->headerEmitter)('Referrer-Policy: same-origin');

        if (in_array($ext, self::SANDBOXED_EXTS, true)) {
            ($this->headerEmitter)("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    private function isNotModified(array $server, string $etag, int $mtime): bool
    {
        $ifNoneMatch = isset($server['HTTP_IF_NONE_MATCH']) && is_string($server['HTTP_IF_NONE_MATCH'])
            ? trim($server['HTTP_IF_NONE_MATCH'])
            : '';
        if ($ifNoneMatch !== '') {
            if ($ifNoneMatch === '*') {
                return true;
            }
            $tags = array_map('trim', explode(',', $ifNoneMatch));
            foreach ($tags as $tag) {
                $normalizedTag = str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
                if ($normalizedTag === $etag) {
                    return true;
                }
            }
            return false;
        }

        $ifModifiedSince = isset($server['HTTP_IF_MODIFIED_SINCE']) && is_string($server['HTTP_IF_MODIFIED_SINCE'])
            ? trim($server['HTTP_IF_MODIFIED_SINCE'])
            : '';
        if ($ifModifiedSince !== '') {
            $sinceTs = @strtotime($ifModifiedSince);
            if ($sinceTs !== false && $sinceTs >= $mtime) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function ifRangeMatches(array $server, string $etag, int $mtime): bool
    {
        $ifRange = isset($server['HTTP_IF_RANGE']) && is_string($server['HTTP_IF_RANGE'])
            ? trim($server['HTTP_IF_RANGE'])
            : '';
        if ($ifRange === '') {
            return true;
        }

        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/')) {
            return $ifRange === $etag;
        }

        $sinceTs = @strtotime($ifRange);
        return $sinceTs !== false && $sinceTs === $mtime;
    }

    /**
     * @param resource $fp
     */
    private function streamHandle($fp, int $start, int $length): void
    {
        if (PHP_SAPI !== 'cli') {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            @set_time_limit(0);
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $remaining = $length;
        $chunkSize = 65536;

        while ($remaining > 0 && !feof($fp)) {
            if (PHP_SAPI !== 'cli' && connection_aborted() !== 0) {
                break;
            }
            $toRead = min($chunkSize, $remaining);
            $data = fread($fp, $toRead);
            if ($data === false || $data === '') {
                break;
            }
            ($this->bodyEmitter)($data);
            $remaining -= strlen($data);
            if (PHP_SAPI !== 'cli') {
                @flush();
            }
        }
    }

    private static function resolveDiskPath(string $rootPath, string $relPath): string
    {
        if (str_starts_with($relPath, './')) {
            $relPath = substr($relPath, 2);
        } else {
            $relPath = ltrim($relPath, '/');
        }
        return rtrim($rootPath, '/') . '/' . $relPath;
    }
}
