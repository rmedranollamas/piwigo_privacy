<?php
declare(strict_types=1);

/**
 * Static-analysis stubs for Piwigo 16 core symbols, functions, and globals.
 * Scanned ONLY by PHPStan; never executed at runtime.
 */

define('PHPWG_ROOT_PATH', './');
define('PHPWG_VERSION', '16.0.0');
define('PWG_DERIVATIVE_DIR', '_data/i/');
define('ACCESS_GUEST', 0);
define('ACCESS_ADMINISTRATOR', 4);
define('EVENT_HANDLER_PRIORITY_NEUTRAL', 50);

define('IMAGES_TABLE', 'piwigo_images');
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('IMAGE_FORMAT_TABLE', 'piwigo_image_format');
define('PSHARE_KEYS_TABLE', 'piwigo_pshare_keys');

define('IMG_SQUARE', 'square');
define('IMG_THUMB', 'thumb');
define('IMG_XXSMALL', '2small');
define('IMG_XSMALL', 'xsmall');
define('IMG_SMALL', 'small');
define('IMG_MEDIUM', 'medium');
define('IMG_LARGE', 'large');
define('IMG_XLARGE', 'xlarge');
define('IMG_XXLARGE', 'xxlarge');
define('IMG_3XLARGE', '3xlarge');
define('IMG_4XLARGE', '4xlarge');
define('IMG_CUSTOM', 'custom');

class PluginMaintain
{
    /** @param string $id */
    public function __construct($id) {}
    /** @param string $plugin_version @param array<int, string> $errors */
    public function install($plugin_version, &$errors = []) {}
    /** @param string $plugin_version @param array<int, string> $errors */
    public function activate($plugin_version, &$errors = []) {}
    public function deactivate() {}
    public function uninstall() {}
    /** @param string $old_version @param string $new_version @param array<int, string> $errors */
    public function update($old_version, $new_version, &$errors = []) {}
}

final class ImageRect
{
    public int $l = 0;
    public int $t = 0;
    public int $r = 0;
    public int $b = 0;
    public function width(): int { return 0; }
    public function height(): int { return 0; }
}

final class SizingParams
{
    /** @var int[] */
    public array $ideal_size = [0, 0];
    public float $max_crop = 0.0;
    /** @var int[]|null */
    public ?array $min_size = null;
    /** @param int[] $ideal_size @param int[]|null $min_size */
    public function __construct(array $ideal_size, float $max_crop = 0.0, ?array $min_size = null) {}
    public static function classic(int $w, int $h): self { return new self([$w, $h]); }
    public static function square(int $w): self { return new self([$w, $w], 1.0, [$w, $w]); }
    /** @param int[] $in_size @param ?ImageRect $crop_rect @param ?int[] $scale_size */
    public function compute(array $in_size, ?string $coi, ?ImageRect &$crop_rect, ?array &$scale_size): void {}
}

final class DerivativeParams
{
    public SizingParams $sizing;
    public string $type = IMG_CUSTOM;
    public int $last_mod_time = 0;
    public bool $use_watermark = false;
    public float $sharpen = 0.0;
    public function __construct(SizingParams $sizing) { $this->sizing = $sizing; }
    /** @param string[] $tokens */
    public function add_url_tokens(array &$tokens): void {}
    /** @param int[] $in_size @return int[] */
    public function compute_final_size(array $in_size): array { return [0, 0]; }
    public function max_width(): int { return 0; }
    public function max_height(): int { return 0; }
    /** @param int[] $out_size */
    public function will_watermark(array $out_size): bool { return false; }
}

final class WatermarkParams
{
    public string $file = '';
    /** @var int[] */
    public array $min_size = [500, 500];
    public int $xpos = 50;
    public int $ypos = 50;
    public int $xrepeat = 0;
    public int $yrepeat = 0;
    public int $opacity = 100;
}

final class ImageStdParams
{
    /** @var array<string, int> */
    public static array $custom = [];
    public static int $quality = 95;
    /** @return array<string, DerivativeParams> */
    public static function get_defined_type_map(): array { return []; }
    public static function apply_global(DerivativeParams $params): void {}
    public static function get_watermark(): WatermarkParams { return new WatermarkParams(); }
}

final class SrcImage
{
    public int|string|null $id = null;
    public string $rel_path = '';
    public int $rotation = 0;
    /** @param array<string, mixed> $infos */
    public function __construct(array $infos) {}
    public function is_original(): int|bool { return true; }
}

final class DerivativeImage
{
    public static function get_one(string $type, mixed $src_image): ?self { return null; }
    public function same_as_source(): bool { return false; }
}

class pwg_image
{
    public string $library = 'gd';
    public function __construct(string $source_filepath, ?string $library = null) {}
    public static function get_library(?string $library = null, ?string $ext = null): string|false { return 'gd'; }
    public static function get_rotation_angle(string $source_filepath): ?int { return 0; }
    public static function get_rotation_code_from_angle(int $angle): int { return 0; }
    public static function get_rotation_angle_from_code(int|string $code): int { return 0; }
    public function get_width(): int { return 0; }
    public function get_height(): int { return 0; }
    public function set_compression_quality(int $quality): bool { return true; }
    public function crop(int $width, int $height, int $x, int $y): bool { return true; }
    public function strip(): bool { return true; }
    public function rotate(int $rotation): bool { return true; }
    public function resize(int $width, int $height): bool { return true; }
    public function sharpen(float|int $amount): int|bool { return 0; }
    public function compose(object $overlay, int $x, int $y, int $opacity): bool { return true; }
    public function write(string $destination_filepath): void {}
    public function destroy(): void {}
}

function check_status(int $level): void {}
function is_admin(): bool { return false; }
function is_a_guest(): bool { return true; }
function get_boolean(mixed $var): bool { return false; }
function get_root_url(): string { return './'; }
function get_absolute_root_url(bool $with_scheme = true): string { return 'https://example.com/'; }
/** @param array<string, string> $condition_fields */
function get_sql_condition_FandF(array $condition_fields, ?string $prefix_condition = null, bool $force_one_condition = false): string { return ''; }
function original_to_representative(string $path, string $representative_ext): string { return $path; }
function original_to_format(string $path, string $format_ext): string { return $path; }
function derivative_to_url(string $t): string { return substr($t, 0, 2); }
function char_to_fraction(string $c): float { return 0.0; }
function add_event_handler(string $event, callable|string $func, int $priority = 50, ?string $include_path = null): bool { return true; }
function trigger_notify(string $event, mixed ...$args): void {}
function pwg_query(string $query): mixed { return null; }
/** @return array<string, mixed>|false|null */
function pwg_db_fetch_assoc(mixed $result): array|false|null { return null; }
function pwg_db_real_escape_string(string $s): string { return $s; }
/** @param array<string, mixed> $datas @param array<string, mixed> $where */
function single_update(string $table, array $datas, array $where): void {}
function mkgetdir(string $dir): bool { return true; }
function auth_key_login(string $auth_key, bool $connection_by_header = false): bool { return false; }
/** @return array<string, mixed> */
function build_user(int $user_id, bool $use_cache = true): array { return []; }
function url_is_remote(string $url): bool { return false; }
