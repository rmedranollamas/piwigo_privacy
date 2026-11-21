# `piwigo_privacy` (2026 / v2.0)

Privacy and access-control gatekeeper plugin for **Piwigo 16+**, **PHP 8.2–8.5**, and **Nginx (`X-Accel-Redirect`)**.

Enforces Piwigo user, group, album, privacy-level, HD resolution (`enabled_high`), `Piwigo-private_share`, `pwg_format/`, `piwigo_lounge`, and `X-PIWIGO-API` permissions on every media file—originals, `pwg_representative` posters, RAW/sidecar formats, and all `i.php` / `_data/i/` derivatives.

## Architecture Overview (v2.0)

`piwigo_privacy` runs in two modes:

1. **Transparent Nginx Gatekeeper (Recommended):**
   * Nginx intercepts `/upload/`, `/galleries/`, `/_data/i/`, `/i.php`, and `/i` using regex-immune `^~` prefix / script locations and forwards them to [get.php](get.php).
   * [get.php](get.php) authenticates the visitor (cookie session, `?auth=`, `?pshare=`, or Piwigo 16 `X-PIWIGO-API`), **closes the PHP session lock immediately** (avoiding HTTP/2 & HTTP/3 serialization), verifies permissions via indexed database lookups, generates missing/stale derivatives with `i.php` parity + advisory `flock` + atomic writes, and hands delivery back to Nginx via `X-Accel-Redirect: /_pwg_priv/...`.
   * Canonical Piwigo URLs stay untouched, making Web Services (`ws.php`), mobile apps, `piwigo-videojs`, `bootstrap_darkroom`, and **all** `$conf['derivative_url_style']` settings (`0` Auto, `1` Static, `2` `i.php`) work out of the box.
2. **Standalone PHP Mode (Fallback):**
   * When neither `piwigo_privacy_redirect_header` nor `piwigo_privacy_mode = 'nginx'` is set, URLs are rewritten idempotently to `plugins/piwigo_privacy/get.php?img_id=...&file=...` and served directly by PHP with RFC 9110 Byte-Range (`206`), `ETag`/`304`, `HEAD`, and modern 2026 MIME types.

---

## Installation (Nginx + Piwigo 16)

1. Place this plugin in `plugins/piwigo_privacy` (directory names such as `piwigo_privacy-master` are also supported automatically). No Composer dependencies are required at runtime.
2. Activate **piwigo_privacy** in *Admin -> Plugins -> Manage*. All `$conf['derivative_url_style']` values (`0`, `1`, `2`) are supported.
3. Configure `local/config/config.inc.php`:

```php
$conf['piwigo_privacy_redirect_header'] = 'X-Accel-Redirect';
```

4. Apply the hardened server configuration in [piwigo-nginx-site](piwigo-nginx-site) (adjusting `server_name`, `root`, `/_pwg_priv/*` aliases, TLS certificates, and `$php_fpm_upstream`).
5. Visit *Admin -> Plugins -> Piwigo Privacy* and click **Run Perimeter Check** to verify direct access to `/local/`, `/_data/logs/`, `/upload/`, and `/i.php` is locked down.

> [!NOTE]
> **Upgrading from v1.x:** Legacy `auth.php` has been removed due to security flaws. Replace old v1.x `location /upload { internal; }` Nginx blocks with the updated [piwigo-nginx-site](piwigo-nginx-site) rules (which use `/_pwg_priv/` `internal;` aliases and `^~` interception). Previously cached legacy `/<id>/upload/...` URLs continue to be accepted by `get.php`.

---

## Configuration Options (`local/config/config.inc.php`)

| Config Key | Default | Description |
| :--- | :--- | :--- |
| `$conf['piwigo_privacy_redirect_header']` | `null` | Set `'X-Accel-Redirect'` to enable Nginx zero-copy mode. |
| `$conf['piwigo_privacy_mode']` | Auto | Force `'nginx'` or `'php'`. |
| `$conf['piwigo_privacy_internal_prefix']` | `'/_pwg_priv/'` | Internal Nginx `location ^~ ... { internal; alias ...; }` prefix. |
| `$conf['piwigo_privacy_rewrite_urls']` | `false` in Nginx, `true` in PHP | Override URL rewriting behavior (transparent interception requires `false`). |
| `$conf['piwigo_privacy_base_path']` | Auto from `SCRIPT_NAME` | Explicit URL base path for custom subpath reverse proxies (e.g. `'/piwigo/'`). |
| `$conf['piwigo_privacy_cache_control']` | `'private, max-age=300, must-revalidate'` | `Cache-Control` response header (always uses `private`; never `public`). |
| `$conf['piwigo_privacy_corp']` | `'same-origin'` | `Cross-Origin-Resource-Policy` header (`'same-origin'`, `'same-site'`, etc.). |
| `$conf['piwigo_privacy_strip_metadata']` | `false` | Force stripping EXIF/IPTC metadata on **all** generated derivatives regardless of pixel threshold. |
| `$conf['piwigo_privacy_default_redirect']` | `false` | Redirect denied media requests (`302 Found`, no-store) to fallback image instead of `401`/`403`. |
| `$conf['piwigo_privacy_default_image']` | `default.png` | Custom fallback image URL when `piwigo_privacy_default_redirect` is enabled. |
| `$conf['piwigo_privacy_allow_whitespaces']` | `false` | Permit spaces in synchronized filenames while preserving `sync_chars_regex`. |
| `$conf['piwigo_privacy_allow_special_chars']` | `false` | Permit UTF-8 / special characters in filenames (traversal & control bytes stay unconditionally blocked). |
| `$conf['piwigo_privacy_log_denials']` | `false` | Log rejected media requests to Piwigo's `$logger->debug()`. |
| `$conf['piwigo_privacy_debug']` | `false` | Enable detailed internal denial logging. |

---

## Development & Automated Verification

Composer dev dependencies are strictly for local/CI verification and are not needed in production installs:

```bash
composer install
composer lint       # php -l across all PHP files
composer phpstan    # PHPStan level 8 static analysis
composer test       # Nette Tester suite (unit, security regression, subprocess E2E)
```
