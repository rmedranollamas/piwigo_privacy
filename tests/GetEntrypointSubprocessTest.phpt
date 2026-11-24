<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Tester\Assert;

// Spins up an isolated temporary Piwigo filesystem tree around the real get.php entrypoint
// and executes real PHP subprocesses to verify bootstrap ordering & exit behavior.
$tmpPiwigo = sys_get_temp_dir() . '/pwg_priv_sub_' . bin2hex(random_bytes(4));
mkdir($tmpPiwigo . '/include', 0755, true);
mkdir($tmpPiwigo . '/upload/2026/11', 0755, true);
mkdir($tmpPiwigo . '/plugins/piwigo_privacy', 0755, true);

copy(__DIR__ . '/../get.php', $tmpPiwigo . '/plugins/piwigo_privacy/get.php');
symlink(realpath(__DIR__ . '/../include'), $tmpPiwigo . '/plugins/piwigo_privacy/include');
file_put_contents($tmpPiwigo . '/upload/2026/11/sample.jpg', 'IMGDATA');
file_put_contents($tmpPiwigo . '/upload/2026/11/level4.jpg', 'LVL4DATA');
file_put_contents($tmpPiwigo . '/upload/2026/11/level8.jpg', 'LVL8DATA');

$symlinkedPluginTarget = sys_get_temp_dir() . '/pwg_priv_target_' . bin2hex(random_bytes(4));
mkdir($symlinkedPluginTarget, 0755, true);
copy(__DIR__ . '/../get.php', $symlinkedPluginTarget . '/get.php');
symlink(realpath(__DIR__ . '/../include'), $symlinkedPluginTarget . '/include');
symlink($symlinkedPluginTarget, $tmpPiwigo . '/plugins/piwigo_privacy_sym');

mkdir($tmpPiwigo . '/_data/i/upload/2026/11', 0755, true);
file_put_contents($tmpPiwigo . '/_data/i/upload/2026/11/sample-me.jpg', 'CACHED_ME');
touch($tmpPiwigo . '/upload/2026/11/sample.jpg', 1700000001);
touch($tmpPiwigo . '/_data/i/upload/2026/11/sample-me.jpg', 1700000010);

$mockCommon = <<<'PHP'
<?php
// Verify get.php neutralized session_cache_limiter BEFORE common.inc.php runs session_start().
if (session_cache_limiter() !== '') {
    fwrite(STDERR, 'FAIL:session_cache_limiter=' . session_cache_limiter());
    exit(99);
}
if (!empty($_ENV['FORCE_SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = $_ENV['FORCE_SCRIPT_NAME'];
}
define('IMAGES_TABLE', 'piwigo_images');
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('ACCESS_GUEST', 1);
define('IMG_MEDIUM', 'medium');
define('IMG_CUSTOM', 'custom');

$conf = [
    'piwigo_privacy_redirect_header' => 'X-Accel-Redirect',
    'data_location' => '_data/',
    'picture_ext' => ['jpg', 'png', 'webp'],
    'file_ext' => ['jpg', 'png', 'webp'],
];
$user = !empty($_ENV['MOCK_COOKIE_USER'])
    ? ['id' => 5, 'level' => 4, 'enabled_high' => 'true']
    : ['id' => 2, 'level' => 0, 'enabled_high' => 'false'];

function check_status(int $lvl): void {}
function is_autorize_status(int $lvl): bool { return true; }
function is_admin(): bool { return ($GLOBALS['user']['id'] ?? 2) === 1; }
function is_a_guest(): bool { return ($GLOBALS['user']['id'] ?? 2) === 2; }
function get_boolean(mixed $v): bool { return $v === true || $v === 'true'; }
function get_absolute_root_url(bool $s = true): string {
    // Emulate Piwigo core cookie_path() behavior: only strips /plugins/<dir>/ when PHPWG_ROOT_PATH starts with '../'.
    if (str_starts_with(PHPWG_ROOT_PATH, '../')) {
        return '/';
    }
    return '/plugins/piwigo_privacy_sym/';
}
function pwg_db_real_escape_string(string $s): string { return addslashes($s); }
function derivative_to_url(string $t): string { return substr($t, 0, 2); }
function auth_key_login(string $h, bool $b = false): bool {
    if ($h === 'pkid-20261116-abcdef12345678901234:validsecret') {
        $GLOBALS['user']['id'] = 1;
        return true;
    }
    if ($h === 'pkid-20261116-useruser123456789012:usersecret') {
        $GLOBALS['user']['id'] = 5;
        return true;
    }
    return false;
}
function build_user(int $uid, bool $c = true): array {
    if ($uid === 1) {
        return ['id' => 1, 'level' => 8, 'enabled_high' => 'true'];
    }
    return ['id' => $uid, 'level' => 4, 'enabled_high' => 'true'];
}
function trigger_notify(string $e, mixed ...$a): void {}
function get_sql_condition_FandF(array $f, ?string $p = null): string { return ''; }
function pwg_query(string $q): array {
    if (str_contains($q, 'FROM `piwigo_images`')) {
        if (str_contains($q, 'level4.jpg')) {
            return [['id' => 56, 'path' => './upload/2026/11/level4.jpg', 'width' => 500, 'height' => 400, 'level' => 4]];
        }
        if (str_contains($q, 'level8.jpg')) {
            return [['id' => 57, 'path' => './upload/2026/11/level8.jpg', 'width' => 500, 'height' => 400, 'level' => 8]];
        }
        return [['id' => 55, 'path' => './upload/2026/11/sample.jpg', 'width' => 500, 'height' => 400, 'level' => 0]];
    }
    return [['1' => 1]];
}
function pwg_db_fetch_assoc(array &$rows): array|false {
    $r = array_shift($rows);
    return $r ?? false;
}
final class SizingParams {
    public function __construct(public array $ideal_size, public float $max_crop = 0.0, public ?array $min_size = null) {}
    public static function classic(int $w, int $h): self { return new self([$w, $h]); }
}
final class DerivativeParams {
    public string $type = IMG_MEDIUM;
    public int $last_mod_time = 0;
    public bool $use_watermark = false;
    public float $sharpen = 0.0;
    public function __construct(public SizingParams $sizing) {}
}
final class ImageStdParams {
    public static array $custom = [];
    public static int $quality = 95;
    public static function get_defined_type_map(): array { return [IMG_MEDIUM => new DerivativeParams(SizingParams::classic(792, 594))]; }
}
final class SrcImage {
    public function __construct(public array $i) {}
    public function is_original(): bool { return true; }
}
final class DerivativeImage {
    public static function get_one(string $t, SrcImage $s): self { return new self(); }
    public function same_as_source(): bool { return true; }
}
PHP;

file_put_contents($tmpPiwigo . '/include/common.inc.php', $mockCommon);
file_put_contents($tmpPiwigo . '/include/functions_picture.inc.php', "<?php\n");

register_shutdown_function(static function () use ($tmpPiwigo, $symlinkedPluginTarget): void {
    @unlink($tmpPiwigo . '/_data/i/upload/2026/11/sample-me.jpg');
    @rmdir($tmpPiwigo . '/_data/i/upload/2026/11');
    @rmdir($tmpPiwigo . '/_data/i/upload/2026');
    @rmdir($tmpPiwigo . '/_data/i/upload');
    @rmdir($tmpPiwigo . '/_data/i');
    @rmdir($tmpPiwigo . '/_data');
    @unlink($tmpPiwigo . '/plugins/piwigo_privacy_sym');
    @unlink($symlinkedPluginTarget . '/include');
    @unlink($symlinkedPluginTarget . '/get.php');
    @rmdir($symlinkedPluginTarget);
    @unlink($tmpPiwigo . '/plugins/piwigo_privacy/include');
    @unlink($tmpPiwigo . '/plugins/piwigo_privacy/get.php');
    @rmdir($tmpPiwigo . '/plugins/piwigo_privacy');
    @rmdir($tmpPiwigo . '/plugins');
    @unlink($tmpPiwigo . '/upload/2026/11/sample.jpg');
    @unlink($tmpPiwigo . '/upload/2026/11/level4.jpg');
    @unlink($tmpPiwigo . '/upload/2026/11/level8.jpg');
    @rmdir($tmpPiwigo . '/upload/2026/11');
    @rmdir($tmpPiwigo . '/upload/2026');
    @rmdir($tmpPiwigo . '/upload');
    @unlink($tmpPiwigo . '/include/common.inc.php');
    @unlink($tmpPiwigo . '/include/functions_picture.inc.php');
    @rmdir($tmpPiwigo . '/include');
    @rmdir($tmpPiwigo);
});

$runGet = static function (array $envOverrides, ?string $cwd = null, ?string $scriptPath = null) use ($tmpPiwigo): array {
    $pluginGetDir = $cwd ?? ($tmpPiwigo . '/plugins/piwigo_privacy');
    $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=stderr', $scriptPath ?? 'get.php'];
    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $pluginGetDir,
        array_merge($_ENV, $envOverrides)
    );
    Assert::type('resource', $proc);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
};

// 1. Valid X-PIWIGO-API request succeeds without triggering session_cache_limiter bug or fatal errors.
$scriptFilename = $tmpPiwigo . '/plugins/piwigo_privacy/get.php';
$resOk = $runGet([
    'REQUEST_URI' => '/upload/2026/11/sample.jpg',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
    'HTTP_X_PIWIGO_API' => 'pkid-20261116-abcdef12345678901234:validsecret',
]);
Assert::same(0, $resOk['code'], $resOk['stderr']);
Assert::same('', $resOk['stderr']);
Assert::same('', $resOk['stdout']);

// 2. Direct hit to /plugins/piwigo_privacy/get.php rejects cleanly ("Bad Request") with NO fatal mysqli error.
$resDirect = $runGet([
    'REQUEST_URI' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
]);
Assert::same(0, $resDirect['code'], $resDirect['stderr']);
Assert::same('Bad Request', $resDirect['stdout']);
Assert::same('', $resDirect['stderr']);

// 3. ISM-1.1: Non-admin X-PIWIGO-API user (level=4) accesses level-4 photo cleanly, denied 403 on level-8 photo.
$resNonAdminOk = $runGet([
    'REQUEST_URI' => '/upload/2026/11/level4.jpg',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
    'HTTP_X_PIWIGO_API' => 'pkid-20261116-useruser123456789012:usersecret',
]);
Assert::same(0, $resNonAdminOk['code'], $resNonAdminOk['stderr']);
Assert::same('', $resNonAdminOk['stdout']);

$resNonAdminDeny = $runGet([
    'REQUEST_URI' => '/upload/2026/11/level8.jpg',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
    'HTTP_X_PIWIGO_API' => 'pkid-20261116-useruser123456789012:usersecret',
]);
Assert::same(0, $resNonAdminDeny['code'], $resNonAdminDeny['stderr']);
Assert::same('Forbidden', $resNonAdminDeny['stdout']);

// 4. ISM-1.3: Invalid X-PIWIGO-API fails closed even when cookie user session is active.
$resApiPrecedence = $runGet([
    'REQUEST_URI' => '/upload/2026/11/level4.jpg',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
    'MOCK_COOKIE_USER' => '1',
    'HTTP_X_PIWIGO_API' => 'pkid-invalid:bad',
]);
Assert::same(0, $resApiPrecedence['code'], $resApiPrecedence['stderr']);
Assert::same('Access Denied', $resApiPrecedence['stdout']);

// 5. ISM-1.4: Symlinked plugin directory outside Piwigo root works and emits clean root URL (never /plugins/piwigo_privacy_sym/).
$symScript = $tmpPiwigo . '/plugins/piwigo_privacy_sym/get.php';
$resSymlink = $runGet([
    'REQUEST_URI' => '/i.php?/upload/2026/11/sample-me.jpg&ajaxload=true',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy_sym/get.php',
    'FORCE_SCRIPT_NAME' => '/plugins/piwigo_privacy_sym/get.php',
    'SCRIPT_FILENAME' => $symScript,
    'HTTP_X_PIWIGO_API' => 'pkid-20261116-abcdef12345678901234:validsecret',
], $tmpPiwigo . '/plugins/piwigo_privacy_sym', $symScript);
Assert::same(0, $resSymlink['code'], $resSymlink['stderr']);
Assert::same('', $resSymlink['stderr']);
Assert::same('{"url":"/_data/i/upload/2026/11/sample-me.jpg"}', $resSymlink['stdout']);
