<?php
declare(strict_types=1);

/**
 * Frontend bootstrap. Every class is required eagerly (no autoloader) because
 * the deploy step deletes this directory while the request is still running -
 * a lazy autoloader would try to read files that no longer exist.
 */

define('BOOTSTRAP_VERSION', '1.0.0');

if (!defined('BOOTSTRAP_APP')) {
    define('BOOTSTRAP_APP', dirname(__DIR__));
}

require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/SshKey.php';
require_once __DIR__ . '/Process.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/AuthException.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/BackendClient.php';
require_once __DIR__ . '/RateLimitException.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/GitSsh.php';
require_once __DIR__ . '/DeployException.php';
require_once __DIR__ . '/Deployer.php';
require_once __DIR__ . '/View.php';

mb_internal_encoding('UTF-8');

/** HTML escape helper used by the views. */
function e(mixed $value): string
{
    return \Bootstrap\View::e($value);
}

/** Safe asset URL with a cache-busting version. */
function asset(string $path): string
{
    return 'assets/' . ltrim($path, '/') . '?v=' . BOOTSTRAP_VERSION;
}
