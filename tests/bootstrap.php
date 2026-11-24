<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tester\Environment;

Environment::setup();

/**
 * Loads minimal Piwigo core derivative classes (SizingParams, DerivativeParams,
 * ImageStdParams, WatermarkParams) from lightweight test mirrors compatible with Piwigo 16.
 */
function init_piwigo_derivative_models(): void
{
    if (!defined('IMG_SQUARE')) {
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
    }

    if (!function_exists('derivative_to_url')) {
        function derivative_to_url(string $t): string
        {
            return substr($t, 0, 2);
        }
        function size_to_url(array $s): string
        {
            return $s[0] === $s[1] ? (string) $s[0] : $s[0] . 'x' . $s[1];
        }
        function char_to_fraction(string $c): float
        {
            return (ord($c) - ord('a')) / 25;
        }
        function fraction_to_char(float $f): string
        {
            return chr(ord('a') + (int) round($f * 25));
        }
        function original_to_representative(string $path, string $ext): string
        {
            $pos = strrpos($path, '/');
            $p = $pos !== false ? substr_replace($path, 'pwg_representative/', $pos + 1, 0) : 'pwg_representative/' . $path;
            $d = strrpos($p, '.');
            return $d !== false ? substr_replace($p, $ext, $d + 1) : $p . '.' . $ext;
        }
        function original_to_format(string $path, string $ext): string
        {
            $pos = strrpos($path, '/');
            $p = $pos !== false ? substr_replace($path, 'pwg_format/', $pos + 1, 0) : 'pwg_format/' . $path;
            $d = strrpos($p, '.');
            return $d !== false ? substr_replace($p, $ext, $d + 1) : $p . '.' . $ext;
        }
        function get_boolean(mixed $var): bool
        {
            if (is_bool($var)) {
                return $var;
            }
            return in_array(strtolower((string) $var), ['true', '1', 'y', 'yes'], true);
        }
    }

    if (!class_exists('ImageRect', false)) {
        final class ImageRect
        {
            public int $l = 0;
            public int $t = 0;
            public int $r = 0;
            public int $b = 0;
            public function __construct(array $l)
            {
                $this->r = (int) $l[0];
                $this->b = (int) $l[1];
            }
            public function width(): int { return $this->r - $this->l; }
            public function height(): int { return $this->b - $this->t; }
            public function crop_h(int|float $pixels, ?string $coi): void
            {
                if ($this->width() <= $pixels) return;
                $tlcrop = (int) floor($pixels / 2);
                $this->l += $tlcrop;
                $this->r -= (int) ($pixels - $tlcrop);
            }
            public function crop_v(int|float $pixels, ?string $coi): void
            {
                if ($this->height() <= $pixels) return;
                $tlcrop = (int) floor($pixels / 2);
                $this->t += $tlcrop;
                $this->b -= (int) ($pixels - $tlcrop);
            }
        }

        final class SizingParams
        {
            public function __construct(
                public array $ideal_size,
                public float $max_crop = 0.0,
                public ?array $min_size = null,
            ) {}
            public static function classic(int $w, int $h): self { return new self([$w, $h]); }
            public static function square(int $w): self { return new self([$w, $w], 1.0, [$w, $w]); }
            public function add_url_tokens(array &$tokens): void
            {
                if ($this->max_crop == 0.0) {
                    $tokens[] = 's' . size_to_url($this->ideal_size);
                } elseif ($this->max_crop == 1.0 && $this->min_size !== null && $this->ideal_size == $this->min_size) {
                    $tokens[] = 'e' . size_to_url($this->ideal_size);
                } else {
                    $tokens[] = size_to_url($this->ideal_size);
                    $tokens[] = fraction_to_char($this->max_crop);
                    $tokens[] = size_to_url($this->min_size ?? $this->ideal_size);
                }
            }
            public function compute(array $in_size, ?string $coi, ?ImageRect &$crop_rect, ?array &$scale_size): void
            {
                $destCrop = new ImageRect($in_size);
                if ($this->max_crop > 0 && $this->min_size !== null) {
                    $ratioW = $destCrop->width() / $this->ideal_size[0];
                    $ratioH = $destCrop->height() / $this->ideal_size[1];
                    if ($ratioW > 1 || $ratioH > 1) {
                        if ($ratioW > $ratioH) {
                            $h = $destCrop->height() / $ratioW;
                            if ($h < $this->min_size[1]) {
                                $idealCropPx = $destCrop->width() - floor($destCrop->height() * $this->ideal_size[0] / $this->min_size[1]);
                                $maxCropPx = round($this->max_crop * $destCrop->width());
                                $destCrop->crop_h(min($idealCropPx, $maxCropPx), $coi);
                            }
                        } else {
                            $w = $destCrop->width() / $ratioH;
                            if ($w < $this->min_size[0]) {
                                $idealCropPx = $destCrop->height() - floor($destCrop->width() * $this->ideal_size[1] / $this->min_size[0]);
                                $maxCropPx = round($this->max_crop * $destCrop->height());
                                $destCrop->crop_v(min($idealCropPx, $maxCropPx), $coi);
                            }
                        }
                    }
                }
                $scale_size = [$destCrop->width(), $destCrop->height()];
                $ratioW = $destCrop->width() / $this->ideal_size[0];
                $ratioH = $destCrop->height() / $this->ideal_size[1];
                if ($ratioW > 1 || $ratioH > 1) {
                    if ($ratioW > $ratioH) {
                        $scale_size[0] = $this->ideal_size[0];
                        $scale_size[1] = (int) floor(1e-6 + $scale_size[1] / $ratioW);
                    } else {
                        $scale_size[0] = (int) floor(1e-6 + $scale_size[0] / $ratioH);
                        $scale_size[1] = $this->ideal_size[1];
                    }
                } else {
                    $scale_size = null;
                }
                $crop_rect = ($destCrop->width() != $in_size[0] || $destCrop->height() != $in_size[1]) ? $destCrop : null;
            }
        }

        final class DerivativeParams
        {
            public string $type = IMG_CUSTOM;
            public int $last_mod_time = 0;
            public bool $use_watermark = false;
            public float $sharpen = 0.0;
            public function __construct(public SizingParams $sizing) {}
            public function add_url_tokens(array &$tokens): void { $this->sizing->add_url_tokens($tokens); }
            public function compute_final_size(array $in_size): array
            {
                $this->sizing->compute($in_size, null, $cropRect, $scaleSize);
                return $scaleSize ?? $in_size;
            }
            public function max_width(): int { return (int) $this->sizing->ideal_size[0]; }
            public function max_height(): int { return (int) $this->sizing->ideal_size[1]; }
            public function is_identity(array $in_size): bool
            {
                return $in_size[0] <= $this->sizing->ideal_size[0] && $in_size[1] <= $this->sizing->ideal_size[1];
            }
            public function will_watermark(array $out_size): bool
            {
                if (!$this->use_watermark) return false;
                $min = ImageStdParams::get_watermark()->min_size;
                return $min[0] <= $out_size[0] || $min[1] <= $out_size[1];
            }
        }

        final class WatermarkParams
        {
            public string $file = '';
            public array $min_size = [500, 500];
            public int $xpos = 50;
            public int $ypos = 50;
            public int $xrepeat = 0;
            public int $yrepeat = 0;
            public int $opacity = 100;
        }

        final class ImageStdParams
        {
            public static array $type_map = [];
            public static array $custom = [];
            public static int $quality = 95;
            public static ?WatermarkParams $watermark = null;
            public static function resetDefaults(): void
            {
                self::$watermark = new WatermarkParams();
                self::$quality = 95;
                self::$custom = [];
                self::$type_map = [
                    IMG_SQUARE  => new DerivativeParams(SizingParams::square(120)),
                    IMG_THUMB   => new DerivativeParams(SizingParams::classic(144, 144)),
                    IMG_XXSMALL => new DerivativeParams(SizingParams::classic(240, 240)),
                    IMG_XSMALL  => new DerivativeParams(SizingParams::classic(432, 324)),
                    IMG_SMALL   => new DerivativeParams(SizingParams::classic(576, 432)),
                    IMG_MEDIUM  => new DerivativeParams(SizingParams::classic(792, 594)),
                    IMG_LARGE   => new DerivativeParams(SizingParams::classic(1008, 756)),
                    IMG_XLARGE  => new DerivativeParams(SizingParams::classic(1224, 918)),
                    IMG_XXLARGE => new DerivativeParams(SizingParams::classic(1656, 1242)),
                ];
                foreach (self::$type_map as $type => $p) {
                    $p->type = $type;
                    $p->last_mod_time = 1700000000;
                }
            }
            public static function get_defined_type_map(): array { return self::$type_map; }
            public static function get_by_type(string $type): DerivativeParams
            {
                return self::$type_map[$type] ?? self::$type_map[IMG_XXLARGE];
            }
            public static function apply_global(DerivativeParams $params): void
            {
                $wm = self::get_watermark();
                $params->use_watermark = $wm->file !== ''
                    && ($wm->min_size[0] <= $params->sizing->ideal_size[0] || $wm->min_size[1] <= $params->sizing->ideal_size[1]);
            }
            public static function get_watermark(): WatermarkParams
            {
                return self::$watermark ??= new WatermarkParams();
            }
        }
        ImageStdParams::resetDefaults();
    }
}
