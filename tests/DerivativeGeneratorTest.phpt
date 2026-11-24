<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
init_piwigo_derivative_models();

use PiwigoPrivacy\Config;
use PiwigoPrivacy\DerivativeGenerator;
use PiwigoPrivacy\HttpException;
use Tester\Assert;

define('IMAGES_TABLE', 'piwigo_images');
define('IMG_3XLARGE_DEF', true);

// Add 3xlarge into ImageStdParams map for testing quality cap.
$p3xl = new DerivativeParams(SizingParams::classic(2232, 1674));
$p3xl->type = IMG_3XLARGE;
$p3xl->last_mod_time = 1700000000;
ImageStdParams::$type_map[IMG_3XLARGE] = $p3xl;

// Register a valid IMG_CUSTOM size.
$cu = new DerivativeParams(SizingParams::classic(320, 240));
$cuTokens = [];
$cu->add_url_tokens($cuTokens);
ImageStdParams::$custom[implode('_', $cuTokens)] = time();

$GLOBALS['db_updates'] = [];
function single_update(string $table, array $datas, array $where): void
{
    $GLOBALS['db_updates'][] = ['table' => $table, 'datas' => $datas, 'where' => $where];
}

class pwg_image
{
    public static int $stubRotationAngle = 270;
    public static array $ops = [];
    public string $library = 'gd';
    public int $w = 4000;
    public int $h = 3000;
    public int $writtenQuality = 95;
    public static int $instances = 0;

    public function __construct(public string $srcPath)
    {
        self::$instances++;
        self::$ops[] = ['open', $srcPath];
    }
    public static function get_rotation_angle(string $path): int { return self::$stubRotationAngle; }
    public static function get_rotation_code_from_angle(int $angle): int { return match ($angle) { 90 => 3, 180 => 2, 270 => 1, default => 0 }; }
    public static function get_rotation_angle_from_code(int|string $code): int { return match ((int) $code) { 1 => 270, 2 => 180, 3 => 90, default => 0 }; }
    public function get_width(): int { return $this->w; }
    public function get_height(): int { return $this->h; }
    public function rotate(int $r): bool { self::$ops[] = ['rotate', $r]; [$this->w, $this->h] = $r % 180 !== 0 ? [$this->h, $this->w] : [$this->w, $this->h]; return true; }
    public function crop(int $w, int $h, int $l, int $t): bool { self::$ops[] = ['crop', $w, $h, $l, $t]; $this->w = $w; $this->h = $h; return true; }
    public function resize(int $w, int $h): bool { self::$ops[] = ['resize', $w, $h]; $this->w = $w; $this->h = $h; return true; }
    public function sharpen(float|int $s): int { self::$ops[] = ['sharpen', $s]; return 1; }
    public function compose(object $wm, int $x, int $y, int $op): bool { self::$ops[] = ['compose', $x, $y, $op]; return true; }
    public function strip(): bool { self::$ops[] = ['strip']; return true; }
    public function set_compression_quality(int $q): bool { $this->writtenQuality = $q; self::$ops[] = ['quality', $q]; return true; }
    public function write(string $path): void
    {
        self::$ops[] = ['write', $path];
        file_put_contents($path, 'FAKEJPEG:' . $this->w . 'x' . $this->h . ':q' . $this->writtenQuality);
    }
    public function destroy(): void { self::$ops[] = ['destroy']; }
}

$cfg = Config::fromPiwigoConf([
    'piwigo_privacy_mode' => 'nginx',
    'show_exif' => false,
]);

// 1. Parsing tests: custom valid, custom unregistered 403, custom empty token cu_ -> 400 (no PHP 8 warning!).
$parsedCu = DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/img-cu_s320x240.jpg', $cfg);
Assert::same(IMG_CUSTOM, $parsedCu->type);
Assert::same('./upload/2026/01/img.jpg', $parsedCu->srcRelPath);

Assert::exception(
    static fn () => DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/img-cu_s999x999.jpg', $cfg),
    HttpException::class,
    null,
    403
);
Assert::exception(
    static fn () => DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/img-cu_.jpg', $cfg),
    HttpException::class,
    null,
    400
);

// Build temp gallery root.
$root = sys_get_temp_dir() . '/pwg_priv_dg_' . bin2hex(random_bytes(4));
mkdir($root . '/upload/2026/01/pwg_representative', 0755, true);
mkdir($root . '/_data/i/upload/2026/01', 0755, true);
mkdir($root . '/_data/tmp', 0755, true);

$srcImgPath = $root . '/upload/2026/01/photo.jpg';
$repImgPath = $root . '/upload/2026/01/pwg_representative/vid.jpg';
file_put_contents($srcImgPath, str_repeat('X', 100));
file_put_contents($repImgPath, str_repeat('V', 100));
touch($srcImgPath, 1700000005);
touch($repImgPath, 1700000005);

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

// 2. Generation test: 3XLARGE quality capped at 75, EXIF stripped when show_exif=false, atomic temp extension preserved (.jpg).
pwg_image::$ops = [];
pwg_image::$instances = 0;
$parsed3xl = DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/photo-3x.jpg', $cfg);
$res3xl = DerivativeGenerator::ensureGenerated(
    $parsed3xl,
    ['id' => 1, 'path' => './upload/2026/01/photo.jpg', 'width' => 4000, 'height' => 3000, 'rotation' => 0, 'coi' => null],
    $root . '/',
    $cfg
);
Assert::true($res3xl->generatedNow);
Assert::same('./_data/i/upload/2026/01/photo-3x.jpg', $res3xl->relPath);
Assert::true(is_file($root . '/_data/i/upload/2026/01/photo-3x.jpg'));

$writeOps = array_values(array_filter(pwg_image::$ops, static fn (array $o): bool => $o[0] === 'write'));
Assert::count(1, $writeOps);
Assert::contains('.tmp_', $writeOps[0][1]);
Assert::true(str_ends_with($writeOps[0][1], '.jpg')); // Extension preserved!
Assert::contains('strip', array_column(pwg_image::$ops, 0));
$qualityOps = array_values(array_filter(pwg_image::$ops, static fn (array $o): bool => $o[0] === 'quality'));
Assert::same(75, $qualityOps[0][1]); // 3XLARGE capped at 75!

// 3. Cache hit test: second call must NOT invoke pwg_image!
pwg_image::$ops = [];
pwg_image::$instances = 0;
$res3xlCached = DerivativeGenerator::ensureGenerated(
    $parsed3xl,
    ['id' => 1, 'path' => './upload/2026/01/photo.jpg', 'width' => 4000, 'height' => 3000, 'rotation' => 0, 'coi' => null],
    $root . '/',
    $cfg
);
Assert::false($res3xlCached->generatedNow);
Assert::same(0, pwg_image::$instances);

// 4. try_switch_source() test: generating medium cascades from cached 3xlarge instead of opening upload/2026/01/photo.jpg!
pwg_image::$ops = [];
$parsedMe = DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/photo-me.jpg', $cfg);
$resMe = DerivativeGenerator::ensureGenerated(
    $parsedMe,
    ['id' => 1, 'path' => './upload/2026/01/photo.jpg', 'width' => 4000, 'height' => 3000, 'rotation' => 0, 'coi' => null],
    $root . '/',
    $cfg
);
Assert::true($resMe->generatedNow);
$openOps = array_values(array_filter(pwg_image::$ops, static fn (array $o): bool => $o[0] === 'open'));
Assert::contains('photo-3x.jpg', $openOps[0][1]);

// 5. pwg_representative rotation=0 guard: never rotates poster or writes images.rotation to DB!
pwg_image::$ops = [];
$GLOBALS['db_updates'] = [];
pwg_image::$stubRotationAngle = 270;
$parsedRep = DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/pwg_representative/vid-th.jpg', $cfg);
Assert::true($parsedRep->isRepresentativeSource);
DerivativeGenerator::ensureGenerated(
    $parsedRep,
    ['id' => 9, 'path' => './upload/2026/01/vid.mp4', 'width' => 1920, 'height' => 1080, 'rotation' => null, 'coi' => null],
    $root . '/',
    $cfg
);
Assert::count(0, $GLOBALS['db_updates']);
Assert::notContains('rotate', array_column(pwg_image::$ops, 0));

// 6. Unchanged fallback ($changes === 0): image smaller than threshold with 0 rotation & 0 sharpen.
@unlink($root . '/_data/i/upload/2026/01/photo-3x.jpg');
@unlink($root . '/_data/i/upload/2026/01/photo-me.jpg');
pwg_image::$ops = [];
$tinyImgFactory = static fn (string $p): object => new class($p) extends pwg_image {
    public int $w = 100;
    public int $h = 80;
};
$parsedCuPhoto = DerivativeGenerator::parseRequestPath('./_data/i/upload/2026/01/photo-cu_s320x240.jpg', $cfg);
$resCuNoop = DerivativeGenerator::ensureGenerated(
    $parsedCuPhoto,
    ['id' => 1, 'path' => './upload/2026/01/photo.jpg', 'width' => 100, 'height' => 80, 'rotation' => 0, 'coi' => null],
    $root . '/',
    $cfg,
    $tinyImgFactory
);
Assert::true($resCuNoop->unchangedFallback);
Assert::false($resCuNoop->generatedNow);
Assert::same('./upload/2026/01/photo.jpg', $resCuNoop->relPath);
