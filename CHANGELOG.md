# Changelog

## 2.0.0 — 2026 Modernization & Security Release

### Security Fixes
* **Fixed SQL injection & dead numeric ID validation (`SEC-01`):** Replaced broken `!is_numeric($img_id) && $img_id == (int)$img_id` condition and raw SQL interpolation with strict request routing, regex integer checks, and indexed escaping via `pwg_db_real_escape_string()`.
* **Fixed CDN / shared-cache media poisoning (`SEC-02`):** Replaced `session_cache_limiter('public')` with empty session cache limiter and explicit `Cache-Control: private` + `Vary: Cookie, X-PIWIGO-API` headers (including hardening core `action.php` via `loc_action_before_http_headers`).
* **Fixed `pwg_representative` arbitrary file read & HD bypass (`SEC-03`):** Eliminated dotless filename `.` collision, sibling prefix matching (`vid.other.jpg`), and unauthenticated/non-HD access to full-resolution TIFF/RAW/HEIC representatives.
* **Hardened Nginx sample perimeter (`SEC-04`):** Replaced regex-bypassable `location /upload { internal; }` blocks with `location ^~` interception + scoped `internal;` `/_pwg_priv/*` aliases, locked down `/local/`, `/install/`, `/include/`, and `/_data/logs/`, preserved `/.well-known/acme-challenge/`, and set `client_max_body_size 128m`.
* **Removed obsolete `auth.php` (`SEC-05`):** Deleted legacy `auth.php` (eliminating `?1` HD bypass, broken physical-gallery regexes, and `LIKE '%...%'` table scans).
* **Hardened error, redirect, XSS & EXIF behavior (`SEC-06`):** Fixed `piwigo_privacy_debug = false` truthiness trap, changed denial redirects from permanent `301` to root-anchored `302` with `no-store`, added `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy`, and `Content-Security-Policy: sandbox` on SVG/PDF/HTML/XML, and stripped EXIF when `show_exif = false` or `piwigo_privacy_strip_metadata = true`.

### Architecture & 2026 Ecosystem Support
* **First-class `i.php` support:** Removed `$conf['derivative_url_style'] === 1` lockout; added full Nginx & PHP routing for `i.php?...`, `i.php/...`, `i?...`, `i/...`, `?ajaxload=true`, and `&b=` COI crop previews.
* **Removed `SrcImageProxy` & fixed URL rewriting (`ARCH-01`–`ARCH-03`):** Adopted Transparent Nginx Gatekeeper by default (keeping canonical URLs for `ws.php` mobile apps, `piwigo-videojs`, and `bootstrap_darkroom`), and made optional PHP-mode `UrlRewriter` strictly idempotent at priority `30`.
* **Piwigo 16+ compatibility (`ARCH-04`, `ARCH-05`):** Added stateless `X-PIWIGO-API` header authentication, Admin access to `piwigo_lounge` & orphan photos, `pwg_format/` sidecars, `Piwigo-private_share` `?pshare=` token propagation, theme/plugin derivatives, and `Version: auto`.
* **Upgraded derivative engine (`ARCH-06`, `ARCH-07`):** Synced with Piwigo 16 `i.php` (`try_switch_source()`, `IMG_3XLARGE`/`IMG_4XLARGE` quality caps, `pwg_representative` rotation=0 guard, `$changes === 0` skip, GD PHP 8.5 safety), added immediate `session_write_close()`, per-derivative advisory `flock`, and atomic extension-preserving temp writes.
* **Modern media streaming & tooling:** Added 2026 MIME table (WebP, AVIF, JXL, HEIC, SVG, MOV, MKV, MP3, FLAC), RFC 9110 suffix Byte-Range / `If-Range` / `ETag` / `304` / `HEAD` handling, Admin Diagnostics UI (`admin.php`), PHPStan Level 8, Nette Tester suite, and GitHub Actions CI.

## 1.0.1
* Initial Yoni Jah implementation (`get.php`, `SrcImageProxy`, Naive & Advanced Nginx modes).
