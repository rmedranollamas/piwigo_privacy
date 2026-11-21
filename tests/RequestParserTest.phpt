<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PiwigoPrivacy\Config;
use PiwigoPrivacy\HttpException;
use PiwigoPrivacy\RequestParser;
use Tester\Assert;

$nginxCfg = Config::fromPiwigoConf(['piwigo_privacy_mode' => 'nginx']);
$phpCfg = Config::fromPiwigoConf([]);

// 1. Canonical Nginx routes (originals, derivatives, all i.php forms).
$r1 = RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/16/20261116-abcd1234.jpg'], $nginxCfg);
Assert::same('./upload/2026/11/16/20261116-abcd1234.jpg', $r1->normalizedPath);
Assert::null($r1->imgId);
Assert::false($r1->isDerivativeRequest);

// i.php?/upload/...&ajaxload=true&b=1700000000&pshare=<key>
$pshareKey = str_repeat('AbCdE12345', 3);
$r2 = RequestParser::parse([
    'REQUEST_URI' => '/i.php?/upload/2026/11/16/20261116-abcd1234-me.jpg&ajaxload=true&b=1700000000&pshare=' . $pshareKey,
], $nginxCfg);
Assert::same('./_data/i/upload/2026/11/16/20261116-abcd1234-me.jpg', $r2->normalizedPath);
Assert::true($r2->isDerivativeRequest);
Assert::true($r2->ajaxload);
Assert::true($r2->cacheBuster);
Assert::same($pshareKey, $r2->pshareKey);

// i.php/..., i?/..., i/...
$r3 = RequestParser::parse(['REQUEST_URI' => '/i.php/galleries/album/photo-sq.webp'], $nginxCfg);
Assert::same('./_data/i/galleries/album/photo-sq.webp', $r3->normalizedPath);
Assert::true($r3->isDerivativeRequest);

$r4 = RequestParser::parse(['REQUEST_URI' => '/i?/upload/2026/a+b/file%2B1-th.jpg'], Config::fromPiwigoConf([
    'piwigo_privacy_mode' => 'nginx',
    'piwigo_privacy_allow_special_chars' => true,
]));
// + stays +, %2B decodes to + (never corrupted into space).
Assert::same('./_data/i/upload/2026/a+b/file+1-th.jpg', $r4->normalizedPath);

$r5 = RequestParser::parse(['REQUEST_URI' => '/i/upload/2026/11/16/img-cu_s200x200.jpg'], $nginxCfg);
Assert::same('./_data/i/upload/2026/11/16/img-cu_s200x200.jpg', $r5->normalizedPath);
Assert::true($r5->isDerivativeRequest);

// 2. Subfolder deployment via SCRIPT_NAME stripping.
$rSub = RequestParser::parse([
    'SCRIPT_NAME' => '/piwigo/plugins/piwigo_privacy/get.php',
    'REQUEST_URI' => '/piwigo/upload/2026/11/16/img.jpg?b=123',
], $nginxCfg);
Assert::same('./upload/2026/11/16/img.jpg', $rSub->normalizedPath);
Assert::true($rSub->cacheBuster);

// HTTP/1.1 absolute-form request target.
$rAbs = RequestParser::parse([
    'REQUEST_URI' => 'https://gallery.example.com/upload/2026/11/16/img.jpg',
], $nginxCfg);
Assert::same('./upload/2026/11/16/img.jpg', $rAbs->normalizedPath);

// 3. Legacy /<id>/... prefix compatibility.
$rLegacy = RequestParser::parse(['REQUEST_URI' => '/42/_data/i/upload/2026/11/16/img-me.jpg'], $nginxCfg);
Assert::same(42, $rLegacy->imgId);
Assert::same('./_data/i/upload/2026/11/16/img-me.jpg', $rLegacy->normalizedPath);
Assert::true($rLegacy->isDerivativeRequest);

// 4. Standalone PHP query mode.
$rPhp = RequestParser::parse([
    'REQUEST_URI' => '/plugins/piwigo_privacy/get.php?img_id=77&file=i.php%3F%2Fupload%2F2026%2Fimg-sm.jpg&ajaxload=true',
], $phpCfg);
Assert::same(77, $rPhp->imgId);
Assert::same('./_data/i/upload/2026/img-sm.jpg', $rPhp->normalizedPath);
Assert::true($rPhp->isDerivativeRequest);
Assert::true($rPhp->ajaxload);

// 5. SEC-01 regressions: direct get.php hits & non-numeric / SQLi img_id MUST reject 400.
foreach ([
    '/plugins/piwigo_privacy/get.php',
    '/plugins/piwigo_privacy/get.php?img_id=abc&file=upload/a.jpg',
    '/plugins/piwigo_privacy/get.php?img_id=(0)OR(1=1)&file=upload/a.jpg',
    '/plugins/piwigo_privacy/get.php?img_id=0&file=upload/a.jpg',
    '/plugins/piwigo_privacy/get.php?img_id=1e2&file=upload/a.jpg',
    '/plugins/piwigo_privacy/get.php?file=upload/a.jpg',
] as $badUri) {
    Assert::exception(
        static fn () => RequestParser::parse(['REQUEST_URI' => $badUri], $phpCfg),
        HttpException::class,
        null,
        400
    );
}

// 6. Traversal and forbidden directory rejections vs legitimate double-dot filenames.
$rDoubleDotFile = RequestParser::parse(['REQUEST_URI' => '/upload/2026/photo..1.jpg'], $nginxCfg);
Assert::same('./upload/2026/photo..1.jpg', $rDoubleDotFile->normalizedPath);

foreach ([
    '/upload/../local/config/database.inc.php',
    '/upload/%2e%2e/local/config/database.inc.php',
    '/upload/.../secret.jpg',
    '/upload/a%00.jpg',
    '/upload/sub\\evil.jpg',
    '/local/config/database.inc.php',
    '/_data/logs/log_today.txt',
    '/_data/templates_c/foo.php',
] as $traversalUri) {
    Assert::exception(
        static fn () => RequestParser::parse(['REQUEST_URI' => $traversalUri], $nginxCfg),
        HttpException::class,
        null,
        400
    );
}

// 7. Whitespace & special chars configuration.
Assert::exception(
    static fn () => RequestParser::parse(['REQUEST_URI' => '/upload/2026/my%20photo.jpg'], $nginxCfg),
    HttpException::class,
    null,
    400
);

$wsCfg = Config::fromPiwigoConf([
    'piwigo_privacy_mode' => 'nginx',
    'piwigo_privacy_allow_whitespaces' => true,
]);
$rWs = RequestParser::parse(['REQUEST_URI' => '/upload/2026/my%20photo.jpg'], $wsCfg);
Assert::same('./upload/2026/my photo.jpg', $rWs->normalizedPath);

$utf8Cfg = Config::fromPiwigoConf([
    'piwigo_privacy_mode' => 'nginx',
    'piwigo_privacy_allow_special_chars' => true,
]);
$rUtf8 = RequestParser::parse(['REQUEST_URI' => '/galleries/vacances/caf%C3%A9%20(1).jpg'], $utf8Cfg);
Assert::same('./galleries/vacances/café (1).jpg', $rUtf8->normalizedPath);
// Even with allow_special_chars=true, traversal & control chars stay blocked!
Assert::exception(
    static fn () => RequestParser::parse(['REQUEST_URI' => '/galleries/%2e%2e/local/secret.php'], $utf8Cfg),
    HttpException::class,
    null,
    400
);
