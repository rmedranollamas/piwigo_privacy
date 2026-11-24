<?php
declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | Piwigo-Privacy - keep your Piwigo content private                     |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2017-2026 Yoni Jah / rmedranollamas                      |
// | GNU General Public License v2.0 or later                              |
// +-----------------------------------------------------------------------+

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

PiwigoPrivacy\Authenticator::prepareSessionHeaders();

if (!defined('PHPWG_ROOT_PATH')) {
    $pwgRoot = '../../';
    if (!is_file(__DIR__ . '/../../include/common.inc.php')) {
        $cwd = getcwd();
        if (is_string($cwd) && is_file($cwd . '/../../include/common.inc.php')) {
            $resolved = realpath($cwd . '/../..');
            if ($resolved !== false) {
                $pwgRoot = rtrim($resolved, DIRECTORY_SEPARATOR) . '/';
            }
        } elseif (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])) {
            $candidateRoot = dirname($_SERVER['SCRIPT_FILENAME'], 3) . '/';
            if (is_file($candidateRoot . 'include/common.inc.php')) {
                $pwgRoot = $candidateRoot;
            }
        }
    }
    define('PHPWG_ROOT_PATH', $pwgRoot);
}

include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_picture.inc.php';

global $conf, $user;
$confArr = is_array($conf ?? null) ? $conf : [];
$userArr = is_array($user ?? null) ? $user : [];

defined('PWG_DERIVATIVE_DIR') or define('PWG_DERIVATIVE_DIR', ($confArr['data_location'] ?? '_data/') . 'i/');

$config = PiwigoPrivacy\Config::fromPiwigoConf($confArr, basename(__DIR__));
$responder = new PiwigoPrivacy\MediaResponder();
$absoluteRootUrl = function_exists('get_absolute_root_url') ? (string) get_absolute_root_url(false) : '/';

PiwigoPrivacy\FrontController::handle(
    $_SERVER,
    $config,
    $userArr,
    PHPWG_ROOT_PATH,
    $responder,
    $absoluteRootUrl
);
