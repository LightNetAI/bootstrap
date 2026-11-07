<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * Server-side session holding the decrypted SSH key for the lifetime of the
 * login. Session files live in data_dir/sessions (0700) and never touch the
 * browser.
 */
final class Session
{
    private const COOKIE = 'bootstrap_session';
    private const CSRF_KEY = '_csrf';

    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $dir = $config->dataDir() . '/sessions';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the session directory. Check data_dir permissions.');
        }
        @chmod($dir, 0700);

        ini_set('session.save_path', $dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', Http::requestIsHttps() ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) max(3600, $config->sessionTtl() + 600));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');

        session_name(self::COOKIE);
        if (!@session_start()) {
            throw new RuntimeException('Unable to start a session.');
        }

        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public static function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /** @param array<string, mixed> $payload */
    public static function establish(array $payload, Config $config): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = $payload + [
            'logged_in_at' => time(),
            'expires_at' => time() + $config->sessionTtl(),
            'ua_hash' => Http::userAgentHash(),
            'ip' => Http::clientIp(),
        ];
    }

    /** @return array<string, mixed> */
    public static function auth(Config $config): array
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || !isset($auth['private_key'], $auth['username'])) {
            throw new AuthException('Not signed in.');
        }

        if ((int) ($auth['expires_at'] ?? 0) < time()) {
            self::destroy();
            throw new AuthException('Session expired. Sign in again.');
        }

        if ($config->getBool('bind_session_to_ua', true)
            && !hash_equals((string) ($auth['ua_hash'] ?? ''), Http::userAgentHash())) {
            self::destroy();
            throw new AuthException('Session is bound to a different browser.');
        }

        if ($config->getBool('bind_session_to_ip', false)
            && !hash_equals((string) ($auth['ip'] ?? ''), Http::clientIp())) {
            self::destroy();
            throw new AuthException('Session is bound to a different IP address.');
        }

        return $auth;
    }

    public static function isAuthenticated(Config $config): bool
    {
        try {
            self::auth($config);

            return true;
        } catch (AuthException) {
            return false;
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public static function regenerateCsrf(): void
    {
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }
}
