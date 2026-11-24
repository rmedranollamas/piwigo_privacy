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

$mockCommon = <<<'PHP'
<?php
// Verify get.php neutralized session_cache_limiter BEFORE common.inc.php runs session_start().
if (session_cache_limiter() !== '') {
    fwrite(STDERR, 'FAIL:session_cache_limiter=' . session_cache_limiter());
    exit(99);
}
define('IMAGES_TABLE', 'piwigo_images');
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('ACCESS_GUEST', 0);

$conf = [
    'piwigo_privacy_redirect_header' => 'X-Accel-Redirect',
    'data_location' => '_data/',
    'picture_ext' => ['jpg', 'png', 'webp'],
    'file_ext' => ['jpg', 'png', 'webp'],
];
$user = ['id' => 2, 'level' => 0, 'enabled_high' => 'false'];

function check_status(int $lvl): void {}
function is_admin(): bool { return ($GLOBALS['user']['id'] ?? 2) === 1; }
function is_a_guest(): bool { return ($GLOBALS['user']['id'] ?? 2) === 2; }
function get_boolean(mixed $v): bool { return $v === true || $v === 'true'; }
function get_absolute_root_url(bool $s = true): string { return 'https://example.com/'; }
function pwg_db_real_escape_string(string $s): string { return addslashes($s); }
function auth_key_login(string $h, bool $b = false): bool {
    if ($h === 'pkid-20261116-abcdef12345678901234:validsecret') {
        $GLOBALS['user']['id'] = 1;
        return true;
    }
    return false;
}
function build_user(int $uid, bool $c = true): array { return ['id' => $uid, 'level' => 8, 'enabled_high' => 'true']; }
function trigger_notify(string $e, mixed ...$a): void {}
function get_sql_condition_FandF(array $f, ?string $p = null): string { return ''; }
function pwg_query(string $q): array {
    if (str_contains($q, 'FROM `piwigo_images`')) {
        return [['id' => 55, 'path' => './upload/2026/11/sample.jpg', 'width' => 500, 'height' => 400, 'level' => 0]];
    }
    return [['1' => 1]];
}
function pwg_db_fetch_assoc(array &$rows): array|false {
    $r = array_shift($rows);
    return $r ?? false;
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

register_shutdown_function(static function () use ($tmpPiwigo): void {
    @unlink($tmpPiwigo . '/plugins/piwigo_privacy/include');
    @unlink($tmpPiwigo . '/plugins/piwigo_privacy/get.php');
    @rmdir($tmpPiwigo . '/plugins/piwigo_privacy');
    @rmdir($tmpPiwigo . '/plugins');
    @unlink($tmpPiwigo . '/upload/2026/11/sample.jpg');
    @rmdir($tmpPiwigo . '/upload/2026/11');
    @rmdir($tmpPiwigo . '/upload/2026');
    @rmdir($tmpPiwigo . '/upload');
    @unlink($tmpPiwigo . '/include/common.inc.php');
    @unlink($tmpPiwigo . '/include/functions_picture.inc.php');
    @rmdir($tmpPiwigo . '/include');
    @rmdir($tmpPiwigo);
});

$runGet = static function (array $envOverrides) use ($tmpPiwigo): array {
    $pluginGetDir = $tmpPiwigo . '/plugins/piwigo_privacy';
    $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=stderr', 'get.php'];
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

// 2. Direct hit to /plugins/piwigo_privacy/get.php rejects cleanly ("Bad Request") with NO fatal mysqli error.
$resDirect = $runGet([
    'REQUEST_URI' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_NAME' => '/plugins/piwigo_privacy/get.php',
    'SCRIPT_FILENAME' => $scriptFilename,
]);
Assert::same(0, $resDirect['code'], $resDirect['stderr']);
Assert::same('Bad Request', $resDirect['stdout']);
Assert::same('', $resDirect['stderr']);
