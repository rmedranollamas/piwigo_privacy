<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PiwigoPrivacy\Config;
use Tester\Assert;

// SEC-06: explicit false must disable debug instead of enabling it via isset().
$cfgFalseDebug = Config::fromPiwigoConf([
    'piwigo_privacy_debug' => false,
    'derivative_url_style' => '2', // String '2' accepted without strict !== 1 lockout.
]);
Assert::false($cfgFalseDebug->debug);
Assert::same(Config::MODE_PHP, $cfgFalseDebug->mode);
Assert::null($cfgFalseDebug->redirectHeader);
Assert::true($cfgFalseDebug->rewriteUrls);

// Debug true enables debug flag.
$cfgDebugTrue = Config::fromPiwigoConf(['piwigo_privacy_debug' => true]);
Assert::true($cfgDebugTrue->debug);

// Redirect header implies Nginx mode and disables URL rewriting by default (Transparent Gatekeeper).
$cfgNginx = Config::fromPiwigoConf([
    'piwigo_privacy_redirect_header' => 'X-Accel-Redirect',
]);
Assert::same(Config::MODE_NGINX, $cfgNginx->mode);
Assert::same('X-Accel-Redirect', $cfgNginx->redirectHeader);
Assert::same('/_pwg_priv/', $cfgNginx->internalPrefix);
Assert::false($cfgNginx->rewriteUrls);

// CRLF injection attempts in config values fall back to safe defaults.
$cfgUnsafeHeaders = Config::fromPiwigoConf([
    'piwigo_privacy_mode' => 'nginx',
    'piwigo_privacy_redirect_header' => "X-Accel\r\nEvil: 1",
    'piwigo_privacy_cache_control' => "private\r\nSet-Cookie: pwn=1",
    'piwigo_privacy_corp' => "same-origin\nBad: 1",
    'piwigo_privacy_internal_prefix' => "/bad\r\nprefix/",
]);
Assert::same('X-Accel-Redirect', $cfgUnsafeHeaders->redirectHeader);
Assert::same(Config::DEFAULT_CACHE_CONTROL, $cfgUnsafeHeaders->cacheControl);
Assert::same(Config::DEFAULT_CORP, $cfgUnsafeHeaders->corp);
Assert::same('/_pwg_priv/', $cfgUnsafeHeaders->internalPrefix);

// Custom data_location leading dot in directory name is preserved cleanly.
$cfgData = Config::fromPiwigoConf(['data_location' => './.pwg_data/']);
Assert::same('.pwg_data/', $cfgData->dataLocation);
Assert::same('.pwg_data/i/', $cfgData->derivativeDir);

// ISM-6.1: Consistent boolean normalization across string/int Piwigo DB config values.
$cfgStrings = Config::fromPiwigoConf([
    'piwigo_privacy_debug' => 'false',
    'piwigo_privacy_default_redirect' => '0',
    'piwigo_privacy_strip_metadata' => 'no',
    'piwigo_privacy_log_denials' => 'off',
    'piwigo_privacy_allow_whitespaces' => 'true',
    'piwigo_privacy_allow_special_chars' => '1',
    'show_exif' => 'false',
    'enable_formats' => 'true',
    'guest_access' => 'false',
]);
Assert::false($cfgStrings->debug);
Assert::false($cfgStrings->defaultRedirect);
Assert::false($cfgStrings->stripMetadata);
Assert::false($cfgStrings->logDenials);
Assert::true($cfgStrings->allowWhitespaces);
Assert::true($cfgStrings->allowSpecialChars);
Assert::false($cfgStrings->showExif);
Assert::true($cfgStrings->enableFormats);
Assert::false($cfgStrings->guestAccess);

// ISM-5.1 & ISM-5.3: Default format_ext and parent ../upload_dir root resolution.
Assert::contains('dng', $cfgStrings->formatExt);
Assert::contains('cr2', $cfgStrings->formatExt);

$cfgParentUpload = Config::fromPiwigoConf(['upload_dir' => '../photos']);
Assert::contains('photos', $cfgParentUpload->getAllowedMediaRoots(false));
