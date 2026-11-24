<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
init_piwigo_derivative_models();

use PiwigoPrivacy\AccessVerifier;
use PiwigoPrivacy\Config;
use PiwigoPrivacy\DerivativeGenerator;
use PiwigoPrivacy\DerivativeResult;
use PiwigoPrivacy\HttpException;
use PiwigoPrivacy\RequestParser;
use PiwigoPrivacy\ResolvedMedia;
use Tester\Assert;

define('IMAGES_TABLE', 'piwigo_images');
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('IMAGE_FORMAT_TABLE', 'piwigo_image_format');
define('PSHARE_KEYS_TABLE', 'piwigo_pshare_keys');
define('ACCESS_GUEST', 0);

$conf = [
    'picture_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'file_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'tif', 'pdf', 'svg'],
    'enable_formats' => true,
];

final class SrcImage
{
    public int|string|null $id = null;
    public string $rel_path = '';
    public int $rotation = 0;
    private bool $orig;
    private int $w;
    private int $h;

    public function __construct(array $infos)
    {
        global $conf;
        $this->id = $infos['id'] ?? null;
        $ext = strtolower(pathinfo((string) $infos['path'], PATHINFO_EXTENSION));
        if (in_array($ext, $conf['picture_ext'], true)) {
            $this->orig = true;
            $this->rel_path = (string) $infos['path'];
        } else {
            $this->orig = false;
            $this->rel_path = original_to_representative((string) $infos['path'], (string) ($infos['representative_ext'] ?? 'jpg'));
        }
        $this->w = (int) ($infos['width'] ?? 4000);
        $this->h = (int) ($infos['height'] ?? 3000);
    }

    public function is_original(): bool { return $this->orig; }
    public function get_size(): array { return [$this->w, $this->h]; }
}

final class DerivativeImage
{
    public bool $isSame;
    public function __construct(public string $type, public SrcImage $src)
    {
        $p = ImageStdParams::get_by_type($type);
        $s = $src->get_size();
        $this->isSame = ($s[0] <= $p->max_width() && $s[1] <= $p->max_height());
    }
    public static function get_one(string $type, SrcImage $src): self { return new self($type, $src); }
    public function same_as_source(): bool { return $this->isSame; }
}

class MockDbResult
{
    private int $idx = 0;
    public function __construct(public array $rows) {}
    public function next(): array|false { return $this->rows[$this->idx++] ?? false; }
}

$GLOBALS['mock_state'] = [
    'is_admin' => false,
    'is_guest' => true,
    'user' => ['id' => 2, 'level' => 0, 'enabled_high' => 'false', 'forbidden_categories' => '10'],
    'images' => [
        101 => ['id' => 101, 'path' => './galleries/pub/README', 'level' => 0, 'width' => 100, 'height' => 100, 'representative_ext' => 'txt'],
        102 => ['id' => 102, 'path' => './galleries/priv/secret.jpg', 'level' => 0, 'width' => 4000, 'height' => 3000, 'representative_ext' => null],
        103 => ['id' => 103, 'path' => './upload/2026/11/vid.mp4', 'level' => 0, 'width' => 3840, 'height' => 2160, 'representative_ext' => 'jpg'],
        104 => ['id' => 104, 'path' => './upload/2026/11/vid.other.mp4', 'level' => 0, 'width' => 3840, 'height' => 2160, 'representative_ext' => 'jpg'],
        105 => ['id' => 105, 'path' => './upload/2026/11/scan.tif', 'level' => 0, 'width' => 5000, 'height' => 4000, 'representative_ext' => 'png'],
        106 => ['id' => 106, 'path' => './upload/2026/11/lounge_orphan.jpg', 'level' => 0, 'width' => 4000, 'height' => 3000, 'representative_ext' => null],
        107 => ['id' => 107, 'path' => './upload/2026/11/small_ok.jpg', 'level' => 0, 'width' => 800, 'height' => 600, 'representative_ext' => null],
        108 => ['id' => 108, 'path' => './upload/2026/11/photo_lvl4.jpg', 'level' => 4, 'width' => 1200, 'height' => 800, 'representative_ext' => null],
    ],
    'image_cats' => [
        101 => [1],
        102 => [10], // forbidden category 10
        103 => [1],
        104 => [10], // forbidden category 10
        105 => [1],
        // 106 intentionally has ZERO rows (lounge / orphan!)
        107 => [1],
        108 => [1],
    ],
    'formats' => [
        107 => [['format_id' => 9, 'image_id' => 107, 'ext' => 'dng']],
    ],
    'pshares' => [
        str_repeat('ShareKey12', 3) => ['image_id' => 102, 'expired' => false],
        str_repeat('OldKey1234', 3) => ['image_id' => 102, 'expired' => true],
    ],
];

function check_status(int $level): void {}
function is_admin(): bool { return (bool) $GLOBALS['mock_state']['is_admin']; }
function is_a_guest(): bool { return (bool) $GLOBALS['mock_state']['is_guest']; }
function pwg_db_real_escape_string(string $s): string { return addslashes($s); }
function get_sql_condition_FandF(array $fields, ?string $prefix = null): string
{
    $user = $GLOBALS['mock_state']['user'];
    $parts = [];
    if (!empty($user['forbidden_categories']) && isset($fields['forbidden_categories'])) {
        $parts[] = $fields['forbidden_categories'] . ' NOT IN (' . $user['forbidden_categories'] . ')';
    }
    if (isset($fields['forbidden_images']) && $fields['forbidden_images'] === 'i.id') {
        $parts[] = 'i.level <= ' . (int) $user['level'];
    }
    return $parts === [] ? '' : ($prefix ?? '') . ' (' . implode(' AND ', $parts) . ')';
}
function pwg_query(string $sql): MockDbResult
{
    $st = $GLOBALS['mock_state'];
    if (str_contains($sql, 'FROM `' . IMAGES_TABLE . '`') && str_contains($sql, 'WHERE path IN (')) {
        preg_match('/WHERE path IN \(([^)]+)\)/', $sql, $m);
        preg_match_all("/'((?:\\\\'|[^'])*)'/", $m[1], $vals);
        $targets = array_map('stripslashes', $vals[1]);
        $matched = [];
        foreach ($st['images'] as $row) {
            if (in_array($row['path'], $targets, true)) {
                $matched[] = $row;
            }
        }
        return new MockDbResult($matched);
    }
    if (str_contains($sql, 'FROM `' . IMAGES_TABLE . '`') && str_contains($sql, 'path LIKE')) {
        preg_match_all("/path LIKE '((?:\\\\'|[^'])*)%'/", $sql, $vals);
        $prefixes = array_map(static fn (string $p): string => str_replace(['\\%', '\\_'], ['%', '_'], stripslashes($p)), $vals[1]);
        $matched = [];
        foreach ($st['images'] as $row) {
            foreach ($prefixes as $pref) {
                if (str_starts_with($row['path'], $pref)) {
                    $matched[] = $row;
                    break;
                }
            }
        }
        return new MockDbResult($matched);
    }
    if (str_contains($sql, 'FROM `' . IMAGE_FORMAT_TABLE . '`')) {
        preg_match('/image_id = (\d+)/', $sql, $idM);
        preg_match("/LOWER\(ext\) = '([^']+)'/", $sql, $extM);
        $rows = [];
        foreach (($st['formats'][(int) $idM[1]] ?? []) as $fmt) {
            if (strtolower($fmt['ext']) === $extM[1]) {
                $rows[] = $fmt;
            }
        }
        return new MockDbResult($rows);
    }
    if (str_contains($sql, 'FROM `' . PSHARE_KEYS_TABLE . '`')) {
        preg_match("/uuid = '([^']+)' AND image_id = (\d+)/", $sql, $m);
        $share = $st['pshares'][$m[1]] ?? null;
        if ($share !== null && (int) $m[2] === $share['image_id'] && !$share['expired']) {
            return new MockDbResult([['1' => 1]]);
        }
        return new MockDbResult([]);
    }
    if (str_contains($sql, 'FROM `' . CATEGORIES_TABLE . '` AS c')) {
        preg_match('/ic\.image_id = (\d+)/', $sql, $m);
        $imgId = (int) $m[1];
        $row = $st['images'][$imgId] ?? null;
        if ($row === null) {
            return new MockDbResult([]);
        }
        $forbidden = array_map('intval', explode(',', (string) $st['user']['forbidden_categories']));
        $cats = $st['image_cats'][$imgId] ?? [];
        if ($row['level'] > $st['user']['level']) {
            return new MockDbResult([]);
        }
        foreach ($cats as $cid) {
            if (!in_array($cid, $forbidden, true)) {
                return new MockDbResult([['1' => 1]]);
            }
        }
        return new MockDbResult([]);
    }
    return new MockDbResult([]);
}
function pwg_db_fetch_assoc(MockDbResult $res): array|false { return $res->next(); }

$tmpRoot = sys_get_temp_dir() . '/pwg_priv_av_' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/themes/modus', 0755, true);
mkdir($tmpRoot . '/plugins/piwigo_privacy', 0755, true);
file_put_contents($tmpRoot . '/themes/modus/icon.png', 'pngdata');
file_put_contents($tmpRoot . '/plugins/piwigo_privacy/default.png', 'pngdata');

register_shutdown_function(static function () use ($tmpRoot): void {
    @unlink($tmpRoot . '/themes/modus/icon.png');
    @rmdir($tmpRoot . '/themes/modus');
    @rmdir($tmpRoot . '/themes');
    @unlink($tmpRoot . '/plugins/piwigo_privacy/default.png');
    @rmdir($tmpRoot . '/plugins/piwigo_privacy');
    @rmdir($tmpRoot . '/plugins');
    @rmdir($tmpRoot);
});

$cfg = Config::fromPiwigoConf($conf);

// SEC-03 #1: Dotless README in public gallery cannot grant access to private image or sibling files.
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_representative/secret.jpg'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    401
);

// SEC-03 #2: Prefix collision vid.other.jpg (forbidden image 104) MUST NOT match vid.mp4 (public 103).
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_representative/vid.other.jpg'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    401
);

// Public video pwg_representative poster (103) IS allowed for non-HD guests!
$repVid = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_representative/vid.jpg'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same(ResolvedMedia::KIND_REPRESENTATIVE, $repVid->kind);
Assert::same('./upload/2026/11/pwg_representative/vid.jpg', $repVid->serveRelPath);

// Section 5.1: Public 4K MP4 video playback IS allowed for non-HD users (enabled_high = false)!
$origVid = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/vid.mp4'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same(ResolvedMedia::KIND_ORIGINAL, $origVid->kind);

// SEC-03 #3: Full-resolution 5000x4000 TIFF representative PNG blocked for enabled_high = false!
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_representative/scan.png'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    401
);

// High-res original blocked for guest -> 401; for logged-in non-HD user -> 403!
$GLOBALS['mock_state']['is_guest'] = false;
$GLOBALS['mock_state']['user']['id'] = 5;
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_representative/scan.png'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    403
);

// Small original (800x600 <= XXLARGE 1656x1242) IS allowed for non-HD user!
$smallOrig = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/small_ok.jpg'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same('./upload/2026/11/small_ok.jpg', $smallOrig->serveRelPath);

// Explicit image privacy level 4 blocked for level-0 user -> 403.
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/photo_lvl4.jpg'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    403
);

// Multiple Formats (pwg_format/) blocked without enabled_high, allowed WITH enabled_high!
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_format/small_ok.dng'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    403
);
$GLOBALS['mock_state']['user']['enabled_high'] = 'true';
$fmtRes = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/pwg_format/small_ok.dng'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same(ResolvedMedia::KIND_FORMAT, $fmtRes->kind);
Assert::same('./upload/2026/11/pwg_format/small_ok.dng', $fmtRes->serveRelPath);

// ARCH-05: Lounge / Orphan (106) fails for non-admin, SUCCEEDS for Admin!
Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/lounge_orphan.jpg'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    403
);
$GLOBALS['mock_state']['is_admin'] = true;
$adminLounge = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/upload/2026/11/lounge_orphan.jpg'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same('./upload/2026/11/lounge_orphan.jpg', $adminLounge->serveRelPath);

// Private Share: guest can view IMG_MEDIUM of forbidden image 102 with valid key, NOT IMG_XXLARGE and NOT expired key!
$GLOBALS['mock_state']['is_admin'] = false;
$GLOBALS['mock_state']['is_guest'] = true;
$GLOBALS['mock_state']['user']['enabled_high'] = 'false';

$pshareMed = AccessVerifier::verify(
    RequestParser::parse([
        'REQUEST_URI' => '/i.php?/galleries/priv/secret-me.jpg&pshare=' . str_repeat('ShareKey12', 3),
    ], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::true($pshareMed->grantedViaPshare);

Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse([
            'REQUEST_URI' => '/i.php?/galleries/priv/secret-xx.jpg&pshare=' . str_repeat('ShareKey12', 3),
        ], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    401
);

Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse([
            'REQUEST_URI' => '/i.php?/galleries/priv/secret-me.jpg&pshare=' . str_repeat('OldKey1234', 3),
        ], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    401
);

// Derivative fallback protection: non-HD user cannot bypass HD limit via unchangedFallback to large source.
$mediaFallback = new ResolvedMedia(
    kind: ResolvedMedia::KIND_DERIVATIVE,
    serveRelPath: './_data/i/galleries/priv/secret-me.jpg',
    elementInfo: $GLOBALS['mock_state']['images'][102], // 4000x3000 > XXLARGE
    parsedDerivative: DerivativeGenerator::parseRequestPath('./_data/i/galleries/priv/secret-me.jpg', $cfg),
    enabledHigh: false,
);
Assert::exception(
    static fn () => AccessVerifier::assertDerivativeFallbackPermitted(
        $mediaFallback,
        new DerivativeResult('./galleries/priv/secret.jpg', false, unchangedFallback: true)
    ),
    HttpException::class,
    null,
    401
);

// Theme derivative succeeds without DB row, while piwigo_privacy plugin internal image derivative is blocked (403).
$themeMedia = AccessVerifier::verify(
    RequestParser::parse(['REQUEST_URI' => '/_data/i/themes/modus/icon-sq.png'], $cfg),
    $cfg,
    $GLOBALS['mock_state']['user'],
    $tmpRoot
);
Assert::same(ResolvedMedia::KIND_DERIVATIVE_THEME, $themeMedia->kind);

Assert::exception(
    static fn () => AccessVerifier::verify(
        RequestParser::parse(['REQUEST_URI' => '/_data/i/plugins/piwigo_privacy/default-sq.png'], $cfg),
        $cfg,
        $GLOBALS['mock_state']['user'],
        $tmpRoot
    ),
    HttpException::class,
    null,
    403
);
