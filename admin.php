<?php
declare(strict_types=1);

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

check_status(ACCESS_ADMINISTRATOR);

global $conf, $template;

$config = pwg_privacy_get_config();
$rootUrl = function_exists('get_absolute_root_url') ? (string) get_absolute_root_url(false) : '/';

$graphicsLibrary = 'unavailable';
if (!class_exists('pwg_image', false) && is_file(PHPWG_ROOT_PATH . 'admin/include/image.class.php')) {
    include_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';
}
if (class_exists('pwg_image', false)) {
    $lib = pwg_image::get_library();
    $graphicsLibrary = is_string($lib) && $lib !== '' ? $lib : 'none';
}

$derivStyleMap = [
    0 => '0 — Auto (static when cached, i.php when uncached)',
    1 => '1 — Static (_data/i/...)',
    2 => '2 — Dynamic Script (i.php?/upload/...)',
];
$rawStyle = isset($conf['derivative_url_style']) ? (int) $conf['derivative_url_style'] : 0;

$template->assign([
    'PWG_PRIVACY_MODE' => $config->mode,
    'PWG_PRIVACY_REDIRECT_HEADER' => $config->redirectHeader ?? 'None (PHP Streaming)',
    'PWG_PRIVACY_INTERNAL_PREFIX' => $config->internalPrefix,
    'PWG_PRIVACY_REWRITE_URLS' => $config->rewriteUrls ? 'Enabled' : 'Disabled (Transparent Gatekeeper)',
    'PWG_PRIVACY_DERIV_STYLE' => $derivStyleMap[$rawStyle] ?? (string) $rawStyle,
    'PWG_PRIVACY_CACHE_CONTROL' => $config->cacheControl,
    'PWG_PRIVACY_PHP_VERSION' => PHP_VERSION,
    'PWG_PRIVACY_PIWIGO_VERSION' => defined('PHPWG_VERSION') ? PHPWG_VERSION : 'unknown',
    'PWG_PRIVACY_GFX_LIB' => $graphicsLibrary,
    'PWG_PRIVACY_API_KEY_SUPPORT' => function_exists('auth_key_login') ? 'Supported (Piwigo 16 X-PIWIGO-API)' : 'Legacy Session Only',
    'PWG_PRIVACY_PSHARE_SUPPORT' => defined('PSHARE_KEYS_TABLE') ? 'Active (Integrated)' : 'Not Installed',
    'PWG_PRIVACY_FORMATS_SUPPORT' => $config->enableFormats ? 'Enabled' : 'Disabled',
    'PWG_PRIVACY_STRIP_META' => $config->stripMetadata ? 'Forced on all derivatives' : 'Piwigo default (strip below threshold / when EXIF hidden)',
    'PWG_PRIVACY_ROOT_URL' => rtrim($rootUrl, '/') . '/',
]);

$template->set_filename('piwigo_privacy_admin', realpath(__DIR__ . '/admin.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'piwigo_privacy_admin');
