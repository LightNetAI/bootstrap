<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server (development and tests only).
 *
 *   BOOTSTRAP_WEBROOT=/path/to/webroot php -S 127.0.0.1:8100 -t "$BOOTSTRAP_WEBROOT" tests/router.php
 */

$root = getenv('BOOTSTRAP_WEBROOT') ?: dirname(__DIR__) . '/app';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file($root . $path)) {
    return false;
}

require $root . '/index.php';
