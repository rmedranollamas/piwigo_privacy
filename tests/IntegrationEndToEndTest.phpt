<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
init_piwigo_derivative_models();

use PiwigoPrivacy\Config;
use PiwigoPrivacy\FrontController;
use PiwigoPrivacy\MediaResponder;
use PiwigoPrivacy\UrlRewriter;
use Tester\Assert;

define('IMAGES_TABLE', 'piwigo_images');
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('IMAGE_FORMAT_TABLE', 'piwigo_image_format');
define('PSHARE_KEYS_TABLE', 'piwigo_pshare_keys');
define('ACCESS_GUEST', 0);
define('ACCESS_ADMINISTRATOR', 4);
define('EVENT_HANDLER_PRIORITY_NEUTRAL', 50);
define('PHPWG_ROOT_PATH', './');

$conf = [
    'piwigo_privacy_redirect_header' => 'X-Accel-Redirect',
    'derivative_url_style' => 2,
    'picture_ext' => ['jpg', 'png', 'webp'],
    'file_ext' => ['jpg', 'png', 'webp', 'mp4'],
    'data_location' => '_data/',
];

$user = ['id' => 2, 'level' => 0, 'enabled_high' => 'false'];
$page = ['errors' => [], 'infos' => [], 'warnings' => [], 'messages' => [], 'section' => 'categories'];
$pwg_event_handlers = [];
$logger = new class {
    public array $logs = [];
    public function debug(string $msg, string $cat = '', array $ctx = []): void { $this->logs[] = [$msg, $cat, $ctx]; }
};

final class SrcImage
{
    public int|string|null $id = null;
    public string $rel_path = '';
    public int $rotation = 0;
    public function __construct(public array $infos)
    {
        $this->id = $infos['id'] ?? null;
        $this->rel_path = (string) ($infos['path'] ?? '');
    }
    public function is_original(): bool { return true; }
    public function get_path(): string { return './' . ltrim($this->rel_path, './'); }
    public function get_size(): array { return [(int) ($this->infos['width'] ?? 1000), (int) ($this->infos['height'] ?? 800)]; }
}

final class DerivativeImage
{
    public function __construct(public string $type, public SrcImage $src) {}
    public static function get_one(string $type, SrcImage $src): self { return new self($type, $src); }
    public function same_as_source(): bool { return true; }
}

class PluginMaintain
{
    public function __construct(public string $id = 'piwigo_privacy') {}
    public function install($v, &$e = []) {}
    public function activate($v, &$e = []) {}
    public function deactivate() {}
    public function uninstall() {}
    public function update($o, $n, &$e = []) {}
}

function check_status(int $lvl): void {}
function is_admin(): bool { return ($GLOBALS['user']['id'] ?? 2) === 1; }
function is_a_guest(): bool { return ($GLOBALS['user']['id'] ?? 2) === 2; }
function get_root_url(): string { return 'https://gallery.example.com/piwigo/'; }
function get_absolute_root_url(bool $s = true): string { return 'https://gallery.example.com/piwigo/'; }
function pwg_db_real_escape_string(string $s): string { return addslashes($s); }
function add_event_handler(string $e, callable|string $f, int $p = 50): bool
{
    $GLOBALS['pwg_event_handlers'][$e][$p][] = $f;
    return true;
}
function trigger_notify(string $e, mixed ...$args): void {}
function auth_key_login(string $key, bool $byHeader = false): bool
{
    if ($key === 'pkid-20261116-abcdef12345678901234:secretsecretsecretsecretsecretsecret1234') {
        $GLOBALS['user']['id'] = 1; // Admin via X-PIWIGO-API!
        return true;
    }
    return false;
}
function build_user(int $uid, bool $cache = true): array
{
    return ['id' => $uid, 'level' => 8, 'enabled_high' => 'true'];
}
function get_sql_condition_FandF(array $f, ?string $p = null): string { return ''; }
function pwg_query(string $sql): array
{
    if (str_contains($sql, 'FROM `' . IMAGES_TABLE . '`')) {
        return [['id' => 42, 'path' => './upload/2026/11/16/secret.jpg', 'width' => 2000, 'height' => 1500, 'rotation' => 0, 'coi' => null, 'level' => 8]];
    }
    return [['1' => 1]];
}
function pwg_db_fetch_assoc(array &$rows): array|false
{
    $r = array_shift($rows);
    return $r ?? false;
}

class pwg_image
{
    public static function get_rotation_angle(string $p): int { return 0; }
    public static function get_rotation_code_from_angle(int $a): int { return 0; }
    public static function get_rotation_angle_from_code(int|string $c): int { return 0; }
}

class E2EImageDouble
{
    public string $library = 'gd';
    public int $w = 2000;
    public int $h = 1500;
    public function get_width(): int { return $this->w; }
    public function get_height(): int { return $this->h; }
    public function rotate(int $r): bool { return true; }
    public function crop(int $w, int $h, int $l, int $t): bool { $this->w = $w; $this->h = $h; return true; }
    public function resize(int $w, int $h): bool { $this->w = $w; $this->h = $h; return true; }
    public function sharpen(float|int $s): int { return 1; }
    public function compose(object $o, int $x, int $y, int $op): bool { return true; }
    public function strip(): bool { return true; }
    public function set_compression_quality(int $q): bool { return true; }
    public function write(string $p): void { file_put_contents($p, 'DERIV'); }
    public function destroy(): void {}
}

// Temp root for full FrontController pipeline test.
$root = sys_get_temp_dir() . '/pwg_priv_e2e_' . bin2hex(random_bytes(4));
mkdir($root . '/upload/2026/11/16', 0755, true);
mkdir($root . '/_data/tmp', 0755, true);
file_put_contents($root . '/upload/2026/11/16/secret.jpg', 'ORIG');

register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($root);
});

// 1. Test main.inc.php & maintain.class.php load cleanly with derivative_url_style = 2 (NO error, NO SrcImageProxy!).
require_once __DIR__ . '/../main.inc.php';
require_once __DIR__ . '/../maintain.class.php';

Assert::count(0, $page['errors']);
Assert::false(class_exists('SrcImageProxy', false));
Assert::true(isset($pwg_event_handlers['loc_action_before_http_headers']));
Assert::true(isset($pwg_event_handlers['get_derivative_url'][30]));

// 2. Test UrlRewriter idempotency (fixes ARCH-02 U_ORIGINAL double rewrite) and Private Share propagation.
$phpCfg = Config::fromPiwigoConf(['piwigo_privacy_mode' => 'php']);
$uOnce = UrlRewriter::modifyUrl('https://gallery.example.com/piwigo/upload/2026/11/16/a+b.jpg', 42, $phpCfg, 'https://gallery.example.com/piwigo/');
Assert::same('https://gallery.example.com/piwigo/plugins/piwigo_privacy/get.php?img_id=42&file=upload%2F2026%2F11%2F16%2Fa%2Bb.jpg', $uOnce);
// Second invocation (picture_pictures_data after SrcImage::get_url) MUST remain identical!
Assert::same($uOnce, UrlRewriter::modifyUrl($uOnce, 42, $phpCfg, 'https://gallery.example.com/piwigo/'));

// Theme asset with $id = null stays untouched.
Assert::same('https://gallery.example.com/piwigo/themes/modus/icon.png', UrlRewriter::modifyUrl('https://gallery.example.com/piwigo/themes/modus/icon.png', null, $phpCfg, 'https://gallery.example.com/piwigo/'));

// picture_pictures_data keeps SrcImage instance untouched (final class contract safe!).
$srcObj = new SrcImage(['id' => 42, 'path' => './upload/2026/11/16/a.jpg']);
$conf['piwigo_privacy_mode'] = 'php';
unset($conf['piwigo_privacy_redirect_header']);
$picData = ['current' => ['id' => 42, 'element_url' => $uOnce, 'src_image' => $srcObj]];
$picOut = pwg_privacy_plugin_replace_picture($picData);
Assert::same($srcObj, $picOut['current']['src_image']);
Assert::same($uOnce, $picOut['current']['element_url']);

// Private Share hook appends pshare token cleanly without duplicating.
$page['section'] = 'pshare';
$page['pshare_key'] = str_repeat('KeyAlpha12', 3);
$conf['piwigo_privacy_redirect_header'] = 'X-Accel-Redirect';
unset($conf['piwigo_privacy_mode']);
$pshareUrl = pwg_privacy_plugin_replace_derivative_url('https://gallery.example.com/piwigo/i.php?/upload/a-me.jpg', null, $srcObj);
Assert::same('https://gallery.example.com/piwigo/i.php?/upload/a-me.jpg&pshare=' . $page['pshare_key'], $pshareUrl);
Assert::same($pshareUrl, UrlRewriter::appendPshareToken($pshareUrl, $page['pshare_key']));

// 3. End-to-End FrontController with Piwigo 16 X-PIWIGO-API, session_start() -> session_write_close() lock release, and ajaxload=true!
session_save_path(sys_get_temp_dir());
@session_start();
Assert::same(PHP_SESSION_ACTIVE, session_status());

$captured = ['status' => null, 'headers' => [], 'body' => ''];
$responder = new MediaResponder(
    static function (int $c) use (&$captured): void { $captured['status'] = $c; },
    static function (string $h) use (&$captured): void { $captured['headers'][] = $h; },
    static function (string $b) use (&$captured): void { $captured['body'] .= $b; },
);

$cfgNginx = Config::fromPiwigoConf($conf);
FrontController::handle(
    [
        'SCRIPT_NAME' => '/piwigo/plugins/piwigo_privacy/get.php',
        'REQUEST_URI' => '/piwigo/i.php?/upload/2026/11/16/secret-me.jpg&ajaxload=true',
        'HTTP_X_PIWIGO_API' => 'pkid-20261116-abcdef12345678901234:secretsecretsecretsecretsecretsecret1234',
    ],
    $cfgNginx,
    $user,
    $root . '/',
    $responder,
    'https://gallery.example.com/piwigo/',
    static fn (string $p): object => new E2EImageDouble()
);

// Session lock MUST already be closed (ARCH-07)!
Assert::notSame(PHP_SESSION_ACTIVE, session_status());
Assert::same(200, $captured['status']);
Assert::same('{"url":"https://gallery.example.com/piwigo/_data/i/upload/2026/11/16/secret-me.jpg"}', $captured['body']);
Assert::true(is_file($root . '/_data/i/upload/2026/11/16/secret-me.jpg'));

// Now serve cached derivative via Nginx X-Accel-Redirect!
$captured2 = ['status' => null, 'headers' => [], 'body' => ''];
$responder2 = new MediaResponder(
    static function (int $c) use (&$captured2): void { $captured2['status'] = $c; },
    static function (string $h) use (&$captured2): void { $captured2['headers'][] = $h; },
    static function (string $b) use (&$captured2): void { $captured2['body'] .= $b; },
);
FrontController::handle(
    [
        'SCRIPT_NAME' => '/piwigo/plugins/piwigo_privacy/get.php',
        'REQUEST_URI' => '/piwigo/_data/i/upload/2026/11/16/secret-me.jpg',
        'HTTP_X_PIWIGO_API' => 'pkid-20261116-abcdef12345678901234:secretsecretsecretsecretsecretsecret1234',
    ],
    $cfgNginx,
    $user,
    $root . '/',
    $responder2,
    'https://gallery.example.com/piwigo/',
    static fn (string $p): object => new E2EImageDouble()
);
Assert::contains('X-Accel-Redirect: /_pwg_priv/_data/i/upload/2026/11/16/secret-me.jpg', $captured2['headers']);

// 4. Invalid X-PIWIGO-API fails closed with 401!
$user = ['id' => 2, 'level' => 0, 'enabled_high' => 'false'];
$capturedBadKey = ['status' => null, 'headers' => [], 'body' => ''];
FrontController::handle(
    [
        'REQUEST_URI' => '/upload/2026/11/16/secret.jpg',
        'HTTP_X_PIWIGO_API' => 'pkid-invalid:wrong',
    ],
    $cfgNginx,
    $user,
    $root . '/',
    new MediaResponder(
        static function (int $c) use (&$capturedBadKey): void { $capturedBadKey['status'] = $c; },
        static function (string $h) use (&$capturedBadKey): void { $capturedBadKey['headers'][] = $h; },
        static function (string $b) use (&$capturedBadKey): void { $capturedBadKey['body'] .= $b; },
    ),
    'https://gallery.example.com/piwigo/',
);
Assert::same(401, $capturedBadKey['status']);
