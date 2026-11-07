<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

final class Http
{
    public static function wantsJson(): bool
    {
        return str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public static function requestIsHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        self::emitJson($payload, $status);
        exit;
    }

    /** Same as json() but returns so the caller can keep working after the response. */
    public static function emitJson(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<string, mixed> $payload */
    public static function jsonError(string $message, int $status = 400, string $code = 'error', array $extra = []): never
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]] + $extra, $status);
    }

    public static function securityHeaders(bool $https): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: no-store');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    /**
     * Flush the response to the client and keep executing (FPM), so destructive
     * deployment cleanup can finish after the browser already has its answer.
     */
    public static function finish(): void
    {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::jsonError('POST required.', 405, 'method_not_allowed');
        }
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function userAgentHash(): string
    {
        return substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 32);
    }

    public static function currentUrl(): string
    {
        $scheme = self::requestIsHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . '/';
    }
}
