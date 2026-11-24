<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PiwigoPrivacy\Config;
use PiwigoPrivacy\HttpException;
use PiwigoPrivacy\MediaResponder;
use Tester\Assert;

final class FakeResponse
{
    public ?int $status = null;
    /** @var string[] */
    public array $headers = [];
    public string $body = '';

    public function createResponder(): MediaResponder
    {
        return new MediaResponder(
            statusEmitter: function (int $c): void { $this->status = $c; },
            headerEmitter: function (string $h): void { $this->headers[] = $h; },
            bodyEmitter: function (string $b): void { $this->body .= $b; },
        );
    }

    public function hasHeaderPrefix(string $prefix): bool
    {
        foreach ($this->headers as $h) {
            if (stripos($h, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getHeader(string $name): ?string
    {
        $needle = strtolower($name) . ':';
        foreach ($this->headers as $h) {
            if (str_starts_with(strtolower($h), $needle)) {
                return trim(substr($h, strlen($needle)));
            }
        }
        return null;
    }
}

$root = sys_get_temp_dir() . '/pwg_priv_mr_' . bin2hex(random_bytes(4));
mkdir($root . '/upload/2026/a+b', 0755, true);
$videoPath = $root . '/upload/2026/a+b/clip #1.mp4';
$svgPath = $root . '/upload/2026/vector.svg';
$webpPath = $root . '/upload/2026/photo.webp';
file_put_contents($videoPath, '0123456789ABCDEFGHIJ'); // 20 bytes
file_put_contents($svgPath, '<svg/>');
file_put_contents($webpPath, 'RIFF....WEBP');
touch($videoPath, 1700000100);

register_shutdown_function(static function () use ($root, $videoPath, $svgPath, $webpPath): void {
    @unlink($videoPath);
    @unlink($svgPath);
    @unlink($webpPath);
    @rmdir($root . '/upload/2026/a+b');
    @rmdir($root . '/upload/2026');
    @rmdir($root . '/upload');
    @rmdir($root);
});

// 1. Nginx X-Accel-Redirect: single slash, encoded segments, NO Content-Type override, private headers!
$nginxCfg = Config::fromPiwigoConf([
    'piwigo_privacy_redirect_header' => 'X-Accel-Redirect',
    'file_ext' => ['jpg', 'webp', 'mp4', 'svg'],
]);
$rNginx = new FakeResponse();
$rNginx->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $nginxCfg, [], false);

Assert::same('/_pwg_priv/upload/2026/a%2Bb/clip%20%231.mp4', $rNginx->getHeader('X-Accel-Redirect'));
Assert::same('video/mp4', $rNginx->getHeader('Content-Type'));
Assert::same('private, max-age=300, must-revalidate', $rNginx->getHeader('Cache-Control'));
Assert::same('Cookie, X-PIWIGO-API', $rNginx->getHeader('Vary'));
Assert::same('nosniff', $rNginx->getHeader('X-Content-Type-Options'));
Assert::same('same-origin', $rNginx->getHeader('Cross-Origin-Resource-Policy'));

// 2. Cache-buster (&b=) switches Cache-Control to no-store, SVG emits sandbox CSP & attachment disposition!
$rSvg = new FakeResponse();
$rSvg->createResponder()->serveFile('./upload/2026/vector.svg', $root . '/', $nginxCfg, [], true);
Assert::same('private, no-store, max-age=0', $rSvg->getHeader('Cache-Control'));
Assert::contains('sandbox', (string) $rSvg->getHeader('Content-Security-Policy'));
Assert::contains('attachment', (string) $rSvg->getHeader('Content-Disposition'));

// 3. Standalone PHP mode: 2026 MIME table & 200 OK without illegal Content-Range / Connection: close.
$phpCfg = Config::fromPiwigoConf([
    'file_ext' => ['jpg', 'webp', 'mp4', 'mov', 'mkv', 'mp3', 'svg', 'avif'],
]);
Assert::same('image/webp', MediaResponder::guessMimeType('webp', $phpCfg));
Assert::same('image/avif', MediaResponder::guessMimeType('avif', $phpCfg));
Assert::same('video/quicktime', MediaResponder::guessMimeType('mov', $phpCfg));
Assert::same('video/x-matroska', MediaResponder::guessMimeType('mkv', $phpCfg));
Assert::same('audio/mpeg', MediaResponder::guessMimeType('mp3', $phpCfg));
Assert::same('image/svg+xml', MediaResponder::guessMimeType('svg', $phpCfg));

$r200 = new FakeResponse();
$r200->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['REQUEST_METHOD' => 'GET']);
Assert::same(200, $r200->status);
Assert::same('video/mp4', $r200->getHeader('Content-Type'));
Assert::same('bytes', $r200->getHeader('Accept-Ranges'));
Assert::same('20', $r200->getHeader('Content-Length'));
Assert::false($r200->hasHeaderPrefix('Content-Range:'));
Assert::false($r200->hasHeaderPrefix('Connection:'));
Assert::same('0123456789ABCDEFGHIJ', $r200->body);

// 4. HEAD request emits headers only, zero body.
$rHead = new FakeResponse();
$rHead->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['REQUEST_METHOD' => 'HEAD']);
Assert::same(200, $rHead->status);
Assert::same('20', $rHead->getHeader('Content-Length'));
Assert::same('', $rHead->body);

// 5. ETag / If-None-Match 304 Not Modified.
$etag = $r200->getHeader('ETag');
$r304 = new FakeResponse();
$r304->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['HTTP_IF_NONE_MATCH' => $etag]);
Assert::same(304, $r304->status);
Assert::same('', $r304->body);

// 6. Suffix Range (bytes=-5) -> last 5 bytes ("FGHIJ"), NOT first bytes!
$rSuffix = new FakeResponse();
$rSuffix->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['HTTP_RANGE' => 'bytes=-5']);
Assert::same(206, $rSuffix->status);
Assert::same('bytes 15-19/20', $rSuffix->getHeader('Content-Range'));
Assert::same('5', $rSuffix->getHeader('Content-Length'));
Assert::same('FGHIJ', $rSuffix->body);

// 7. Standard Range with clamp beyond EOF (bytes=12-999) -> bytes 12-19/20.
$rClamp = new FakeResponse();
$rClamp->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['HTTP_RANGE' => 'bytes=12-999']);
Assert::same(206, $rClamp->status);
Assert::same('bytes 12-19/20', $rClamp->getHeader('Content-Range'));
Assert::same('CDEFGHIJ', $rClamp->body);

// 8. Unsatisfiable Range (bytes=50-60) & Multi-range -> 416 with bytes */20.
$r416 = new FakeResponse();
$r416->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['HTTP_RANGE' => 'bytes=50-60']);
Assert::same(416, $r416->status);
Assert::same('bytes */20', $r416->getHeader('Content-Range'));

$rMulti = new FakeResponse();
$rMulti->createResponder()->serveFile('./upload/2026/a+b/clip #1.mp4', $root . '/', $phpCfg, ['HTTP_RANGE' => 'bytes=0-1, 4-5']);
Assert::same(416, $rMulti->status);
Assert::same('bytes */20', $rMulti->getHeader('Content-Range'));

// 9. Error semantics: 302 (never 301!) root-anchored redirect; 401 vs 403!
$redirCfg = Config::fromPiwigoConf(['piwigo_privacy_default_redirect' => true]);
$rRedir = new FakeResponse();
$rRedir->createResponder()->sendError(new HttpException(401, 'Denied'), $redirCfg, 'https://example.com/piwigo/');
Assert::same(302, $rRedir->status);
Assert::same('https://example.com/piwigo/plugins/piwigo_privacy/default.png', $rRedir->getHeader('Location'));
Assert::same('private, no-store, no-cache, must-revalidate', $rRedir->getHeader('Cache-Control'));

$r401 = new FakeResponse();
$r401->createResponder()->sendError(new HttpException(401, 'Guest'), $phpCfg, 'https://example.com/');
Assert::same(401, $r401->status);
Assert::true($r401->hasHeaderPrefix('WWW-Authenticate:'));

$r403 = new FakeResponse();
$r403->createResponder()->sendError(new HttpException(403, 'Forbidden'), $phpCfg, 'https://example.com/');
Assert::same(403, $r403->status);
Assert::false($r403->hasHeaderPrefix('WWW-Authenticate:'));
