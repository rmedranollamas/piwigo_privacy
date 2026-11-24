<?php
declare(strict_types=1);

/*
Plugin Name: piwigo_privacy
Version: auto
Description: Keep your Piwigo gallery media private with Nginx X-Accel-Redirect, full i.php derivative access control, and Piwigo 16 support.
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=849
Author: Yoni Jah / rmedranollamas
Author URI: https://github.com/rmedranollamas/piwigo_privacy
Has Settings: true
*/

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PiwigoPrivacy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (str_contains($relative, '..') || str_contains($relative, "\0")) {
        return;
    }
    $file = __DIR__ . '/include/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * Returns the normalized Config object for the current request.
 */
function pwg_privacy_get_config(): PiwigoPrivacy\Config
{
    global $conf;
    return PiwigoPrivacy\Config::fromPiwigoConf(is_array($conf) ? $conf : [], basename(__DIR__));
}

add_event_handler('get_admin_plugin_menu_links', 'pwg_privacy_admin_menu');

/**
 * Registers the Admin -> Plugins configuration & diagnostics link.
 *
 * @param array<int, array<string, string>> $menu
 * @return array<int, array<string, string>>
 */
function pwg_privacy_admin_menu($menu)
{
    $dir = basename(__DIR__);
    $url = $dir === 'piwigo_privacy'
        ? get_root_url() . 'admin.php?page=plugin-piwigo_privacy'
        : get_root_url() . 'admin.php?page=plugin&section=' . rawurlencode($dir . '/admin.php');

    $menu[] = [
        'NAME' => 'Piwigo Privacy',
        'URL' => $url,
    ];
    return $menu;
}

// Early priority ensures compatibility ahead of plugins like piwigo-videojs and bootstrap_darkroom.
$pwgPrivacyPriority = defined('EVENT_HANDLER_PRIORITY_NEUTRAL') ? EVENT_HANDLER_PRIORITY_NEUTRAL - 20 : 30;
add_event_handler('get_derivative_url', 'pwg_privacy_plugin_replace_derivative_url', $pwgPrivacyPriority);
add_event_handler('get_src_image_url', 'pwg_privacy_plugin_replace_url', $pwgPrivacyPriority);
add_event_handler('picture_pictures_data', 'pwg_privacy_plugin_replace_picture', $pwgPrivacyPriority);
add_event_handler('loc_action_before_http_headers', 'pwg_privacy_harden_action_download');

/**
 * Replaces Cache-Control: public in core action.php, enforces HD restrictions on
 * representatives/formats, releases session lock, and offloads local file downloads
 * to Nginx X-Accel-Redirect when enabled.
 */
function pwg_privacy_harden_action_download(): void
{
    global $file, $element_info, $user;

    PiwigoPrivacy\Authenticator::releaseSessionLock();

    if (function_exists('header_remove')) {
        @header_remove('Expires');
        @header_remove('Pragma');
    }

    $config = pwg_privacy_get_config();
    $ext = is_string($file) ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';

    header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
    header('Vary: Cookie, X-PIWIGO-API');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: ' . $config->corp);
    header('Referrer-Policy: same-origin');

    if (in_array($ext, ['svg', 'svgz', 'pdf', 'html', 'htm', 'xml'], true)) {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    $isAdmin = function_exists('is_admin') ? (bool) is_admin() : false;
    $userArr = is_array($user ?? null) ? $user : [];
    $enabledHigh = $isAdmin || PiwigoPrivacy\AccessVerifier::isHighResEnabled($userArr);
    if (!$enabledHigh) {
        $part = isset($_GET['part']) && is_string($_GET['part']) ? $_GET['part'] : '';
        $parentDir = is_string($file) ? basename(dirname($file)) : '';
        $denyStatus = (function_exists('is_a_guest') && is_a_guest()) ? 401 : 403;

        if ($part === 'f' || $parentDir === 'pwg_format' || isset($_GET['format'])) {
            http_response_code($denyStatus);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'High resolution access denied';
            exit();
        }

        if (($part === 'r' || $parentDir === 'pwg_representative')
            && is_array($element_info)
            && isset($element_info['path'])
            && is_string($element_info['path'])
        ) {
            $srcExt = strtolower(pathinfo($element_info['path'], PATHINFO_EXTENSION));
            if (PiwigoPrivacy\AccessVerifier::isHighResRepSourceExt($srcExt)
                && class_exists('SrcImage')
                && class_exists('DerivativeImage')
            ) {
                $srcImage = new SrcImage($element_info);
                $largest = DerivativeImage::get_one(defined('IMG_4XLARGE') ? IMG_4XLARGE : IMG_XXLARGE, $srcImage);
                if ($largest === null || !$largest->same_as_source()) {
                    http_response_code($denyStatus);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'High resolution access denied';
                    exit();
                }
            }
        }
    }

    if ($config->mode !== PiwigoPrivacy\Config::MODE_NGINX
        || $config->redirectHeader === null
        || !is_string($file)
        || $file === ''
        || (function_exists('url_is_remote') && url_is_remote($file))
    ) {
        return;
    }

    if (!@is_readable($file)) {
        return;
    }

    // Normalize relative path against PHPWG_ROOT_PATH and verify it maps to an internal Nginx media root.
    $rel = $file;
    $rootPath = defined('PHPWG_ROOT_PATH') ? (string) constant('PHPWG_ROOT_PATH') : './';
    if ($rootPath !== './' && str_starts_with($rel, $rootPath)) {
        $rel = substr($rel, strlen($rootPath));
    }
    while (str_starts_with($rel, './') || str_starts_with($rel, '../')) {
        $rel = str_starts_with($rel, '../') ? substr($rel, 3) : substr($rel, 2);
    }
    $rel = ltrim($rel, '/');
    $segments = explode('/', $rel);
    $firstSeg = $segments[0];

    if (!in_array($firstSeg, $config->getAllowedMediaRoots(false), true)) {
        return;
    }

    if (isset($_GET['download'])) {
        $downloadName = is_array($element_info) && isset($element_info['file']) && is_string($element_info['file'])
            ? htmlspecialchars_decode($element_info['file'])
            : basename($rel);
        $safeAscii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\', "\r", "\n"], '_', $downloadName));
        $utf8Encoded = rawurlencode($downloadName);
        header('Content-Disposition: attachment; filename="' . $safeAscii . '"; filename*=UTF-8\'\'' . $utf8Encoded);
    } else {
        $inlineName = basename($rel);
        $safeAscii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\', "\r", "\n"], '_', $inlineName));
        header('Content-Disposition: inline; filename="' . $safeAscii . '"');
    }

    $mime = PiwigoPrivacy\MediaResponder::guessMimeType($ext, $config);
    header('Content-Type: ' . $mime);

    $internalUri = PiwigoPrivacy\MediaResponder::buildInternalRedirectPath('./' . $rel, $config->internalPrefix);
    header($config->redirectHeader . ': ' . $internalUri);
    exit();
}

/**
 * Rewrites ONLY $image['element_url'] in PHP-rewrite fallback mode (and appends pshare when active)
 * without ever proxying/mutating SrcImage.
 *
 * @param mixed $picture
 * @return mixed
 */
function pwg_privacy_plugin_replace_picture($picture)
{
    global $page;

    if (!is_array($picture)) {
        return $picture;
    }

    $config = pwg_privacy_get_config();
    $isPshare = is_array($page) && ($page['section'] ?? '') === 'pshare' && !empty($page['pshare_key']) && is_string($page['pshare_key']);
    if (!$config->rewriteUrls && !$isPshare) {
        return $picture;
    }

    $rootUrl = function_exists('get_root_url') ? (string) get_root_url() : './';

    foreach ($picture as &$image) {
        if (!is_array($image)) {
            continue;
        }
        if (isset($image['element_url']) && is_string($image['element_url']) && isset($image['id'])) {
            if ($config->rewriteUrls) {
                $image['element_url'] = PiwigoPrivacy\UrlRewriter::modifyUrl(
                    $image['element_url'],
                    $image['id'],
                    $config,
                    $rootUrl
                );
            }
            if ($isPshare
                && !str_contains($image['element_url'], 'action.php')
                && !PiwigoPrivacy\UrlRewriter::isExternalUrl($image['element_url'], $rootUrl)
            ) {
                $image['element_url'] = PiwigoPrivacy\UrlRewriter::appendPshareToken(
                    $image['element_url'],
                    $page['pshare_key']
                );
            }
        }
    }
    unset($image);

    return $picture;
}

/**
 * @param string $url
 * @param mixed $params
 * @param mixed $image
 * @return string
 */
function pwg_privacy_plugin_replace_derivative_url($url, $params, $image)
{
    global $page;

    $config = pwg_privacy_get_config();
    $rootUrl = function_exists('get_root_url') ? (string) get_root_url() : './';
    $imageId = is_object($image) && property_exists($image, 'id') ? $image->id : null;

    if ($config->rewriteUrls) {
        $url = PiwigoPrivacy\UrlRewriter::modifyUrl((string) $url, $imageId, $config, $rootUrl);
    }

    if (is_array($page)
        && ($page['section'] ?? '') === 'pshare'
        && !empty($page['pshare_key'])
        && is_string($page['pshare_key'])
        && !PiwigoPrivacy\UrlRewriter::isExternalUrl((string) $url, $rootUrl)
    ) {
        $url = PiwigoPrivacy\UrlRewriter::appendPshareToken((string) $url, $page['pshare_key']);
    }

    return (string) $url;
}

/**
 * @param string $url
 * @param mixed $image
 * @return string
 */
function pwg_privacy_plugin_replace_url($url, $image)
{
    global $page;

    $config = pwg_privacy_get_config();
    $rootUrl = function_exists('get_root_url') ? (string) get_root_url() : './';
    $imageId = is_object($image) && property_exists($image, 'id') ? $image->id : null;

    if ($config->rewriteUrls) {
        $url = PiwigoPrivacy\UrlRewriter::modifyUrl((string) $url, $imageId, $config, $rootUrl);
    }

    if (is_array($page)
        && ($page['section'] ?? '') === 'pshare'
        && !empty($page['pshare_key'])
        && is_string($page['pshare_key'])
        && !str_contains((string) $url, 'action.php')
        && !PiwigoPrivacy\UrlRewriter::isExternalUrl((string) $url, $rootUrl)
    ) {
        $url = PiwigoPrivacy\UrlRewriter::appendPshareToken((string) $url, $page['pshare_key']);
    }

    return (string) $url;
}
