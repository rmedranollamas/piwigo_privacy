<?php
declare(strict_types=1);

namespace PiwigoPrivacy;

/**
 * Handles session cache-limiter neutralization, Piwigo 16 stateless X-PIWIGO-API
 * authentication, and immediate session lock release before heavy media work.
 */
final class Authenticator
{
    /**
     * Must be invoked before including Piwigo's include/common.inc.php so PHP's
     * session module never emits "Cache-Control: public, max-age=10800".
     */
    public static function prepareSessionHeaders(): void
    {
        if (PHP_SAPI !== 'cli' || session_status() === PHP_SESSION_NONE) {
            @session_cache_limiter('');
        }
    }

    /**
     * Completes authentication after common.inc.php runs, supporting Piwigo 16
     * HTTP_X_PIWIGO_API keys even outside ws.php, and closes the PHP session lock
     * immediately so HTTP/2 concurrent thumbnail/video requests never serialize.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $userRef Synchronized with authenticated global $user
     * @throws HttpException if an invalid X-PIWIGO-API header is supplied
     */
    public static function authenticateAndReleaseLock(array $server, array &$userRef = []): void
    {
        global $user;

        $apiHeader = isset($server['HTTP_X_PIWIGO_API']) && is_string($server['HTTP_X_PIWIGO_API'])
            ? trim($server['HTTP_X_PIWIGO_API'])
            : '';

        if ($apiHeader !== '') {
            if (!function_exists('auth_key_login') || !function_exists('build_user')) {
                self::releaseSessionLock(true);
                throw new HttpException(401, 'API key authentication unavailable');
            }

            $escapedHeader = function_exists('pwg_db_real_escape_string')
                ? pwg_db_real_escape_string($apiHeader)
                : $apiHeader;

            $authenticated = auth_key_login($escapedHeader, true);
            if (!$authenticated) {
                self::releaseSessionLock(true);
                throw new HttpException(401, 'Invalid api_key');
            }

            $userId = is_array($user) && isset($user['id']) ? (int) $user['id'] : 0;
            $user = build_user($userId, true);
            if (function_exists('trigger_notify')) {
                trigger_notify('user_init', $user);
            }
            if (func_num_args() >= 2) {
                $userRef = $user;
            }

            self::releaseSessionLock(true);
            return;
        }

        self::releaseSessionLock(false);
    }

    public static function releaseSessionLock(bool $stateless = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($stateless) {
                @session_abort();
                $sessName = session_name();
                if ($sessName !== false && !isset($_COOKIE[$sessName]) && !headers_sent() && function_exists('header_remove')) {
                    @header_remove('Set-Cookie');
                }
            } else {
                @session_write_close();
            }
        }
    }
}
