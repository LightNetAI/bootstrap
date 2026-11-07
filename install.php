#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bootstrap installer for LightNetAI projects.
 *
 *   curl -fsSL https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.php \
 *     | php -- --webroot "$HOME/public_html"
 *
 * Downloads the release archive, copies the frontend into the document root and
 * writes config.php with the hard-coded backend URL
 * (https://bootstrap.dark.net.au by default).
 *
 * This file is intentionally compatible with PHP 7.4 so that an old runtime can
 * print "PHP 8.1 or newer is required" instead of a parse error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BS_OWNER = 'LightNetAI';
const BS_REPO = 'bootstrap';
const BS_DEFAULT_BACKEND = 'https://bootstrap.dark.net.au';

const BS_VALUE_OPTIONS = [
    'webroot', 'backend', 'data-dir', 'token', 'owner', 'git-base',
    'archive', 'source', 'branch',
];

const BS_FLAG_OPTIONS = [
    'force', 'skip-health', 'allow-insecure-backend', 'no-color', 'help',
];

const BS_KNOWN_OPTIONS = [
    'webroot', 'backend', 'data-dir', 'token', 'owner', 'git-base',
    'archive', 'source', 'branch', 'force', 'skip-health',
    'allow-insecure-backend', 'no-color', 'help',
];

try {
    exit(bs_main($argv));
} catch (Throwable $exception) {
    bs_fail($exception->getMessage());
}

/**
 * @param list<string> $argv
 */
function bs_main(array $argv): int
{
    [$options, $error] = bs_parse_arguments($argv);
    if ($error !== '') {
        fwrite(STDERR, 'FAIL ' . $error . PHP_EOL . PHP_EOL);
        bs_usage(STDERR);

        return 2;
    }

    if (($options['help'] ?? false) === true) {
        bs_usage(STDOUT);

        return 0;
    }

    if (PHP_VERSION_ID < 80100) {
        fwrite(STDERR, 'FAIL PHP 8.1 or newer is required (this is PHP ' . PHP_VERSION . ').' . PHP_EOL);
        fwrite(STDERR, '     Ask your host for a newer PHP, or run the installer with a newer binary:' . PHP_EOL);
        fwrite(STDERR, '       curl -fsSL <installer-url> | php8.3 -- --webroot /path/to/public_html' . PHP_EOL);

        return 1;
    }

    foreach (['curl', 'openssl', 'session', 'json', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) {
            bs_fail('The PHP extension "' . $extension . '" is required by Bootstrap but is not loaded.');
        }
    }
    if (!function_exists('proc_open')) {
        bs_fail('proc_open() is disabled in this PHP. Bootstrap needs it to run git.');
    }

    $backend = rtrim((string) ($options['backend'] ?? BS_DEFAULT_BACKEND), '/');
    $scheme = strtolower((string) parse_url($backend, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || parse_url($backend, PHP_URL_HOST) === null) {
        bs_fail('Invalid backend URL: ' . $backend);
    }
    if ($scheme !== 'https' && ($options['allow-insecure-backend'] ?? false) !== true) {
        bs_fail('The backend URL must use https (add --allow-insecure-backend for local tests).');
    }

    $webroot = (string) ($options['webroot'] ?? '');
    if ($webroot === '') {
        $webroot = (string) getcwd();
    }
    if (rtrim($webroot, '/') === '') {
        bs_fail('Refusing to install into /.');
    }
    if (!is_dir($webroot) && !@mkdir($webroot, 0755, true) && !is_dir($webroot)) {
        bs_fail('Cannot create ' . $webroot);
    }
    $real = realpath($webroot);
    if ($real === false) {
        bs_fail('Cannot resolve ' . $webroot);
    }
    $webroot = $real;
    if (!is_writable($webroot)) {
        bs_fail('The web root is not writable: ' . $webroot);
    }

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        bs_dim('Warning: running as root. Prefer the site owner account so file ownership is correct.');
    }

    $force = ($options['force'] ?? false) === true;
    if (!$force) {
        if (is_file($webroot . '/config.php')) {
            bs_fail($webroot . ' already contains a Bootstrap installation. Re-run with --force to reinstall.');
        }
        $entries = array_values(array_diff(scandir($webroot) ?: [], ['.', '..']));
        if (count($entries) > 0 && (is_file($webroot . '/index.php') || is_file($webroot . '/index.html'))) {
            bs_fail($webroot . ' already contains a site. Re-run with --force if you really want to replace it.');
        }
    }

    $work = bs_temp_dir($webroot);
    try {
        $appDir = null;

        if (isset($options['source'])) {
            $appDir = bs_resolve_app_dir((string) $options['source'], $webroot);
            bs_info('Using local source ' . $appDir);
        } elseif (isset($options['archive'])) {
            $archive = (string) $options['archive'];
            if (!is_file($archive)) {
                bs_fail('Archive not found: ' . $archive);
            }
            bs_info('Using local archive ' . $archive);
            $appDir = bs_extract_app($archive, $work, $webroot);
        } else {
            $local = bs_local_app_dir();
            if ($local !== null) {
                $appDir = $local;
                bs_info('Using the application next to this installer');
            } else {
                $branch = (string) ($options['branch'] ?? 'main');
                $archive = $work . '/bootstrap.tar.gz';
                bs_info('Downloading ' . BS_OWNER . '/' . BS_REPO . ' (' . $branch . ')');
                $primary = 'https://codeload.github.com/' . BS_OWNER . '/' . BS_REPO . '/tar.gz/refs/heads/' . rawurlencode($branch);
                $fallback = 'https://github.com/' . BS_OWNER . '/' . BS_REPO . '/archive/refs/heads/' . rawurlencode($branch) . '.tar.gz';
                if (!bs_download($primary, $archive) && !bs_download($fallback, $archive)) {
                    bs_fail('Unable to download the Bootstrap archive. Check the network, or pass --archive <file>.');
                }
                if (!is_file($archive) || filesize($archive) < 1) {
                    bs_fail('The downloaded archive is empty.');
                }
                $appDir = bs_extract_app($archive, $work, $webroot);
            }
        }

        bs_info('Installing into ' . $webroot);
        $copied = bs_copy_app($appDir, $webroot);
        $files = 0;
        foreach ($copied as $relative) {
            if (is_file($webroot . '/' . $relative)) {
                $files++;
            }
        }
        bs_ok('Copied ' . $files . ' application file(s)');

        $arguments = array_merge(
            [PHP_BINARY, $webroot . '/bin/configure.php', '--webroot', $webroot, '--backend', $backend],
            bs_configure_arguments($options, $force)
        );

        bs_info('Configuring');
        $exit = bs_run($arguments);
        if ($exit !== 0) {
            bs_fail('Configuration/self-test failed. Fix the errors above and re-run.');
        }

        fwrite(STDOUT, PHP_EOL);
        bs_ok('Bootstrap is installed in ' . $webroot);
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, 'Open the site in your browser and sign in with the credentials from BootStrapServer.' . PHP_EOL);
        fwrite(STDOUT, 'If you installed into a subdirectory, adjust the URL accordingly.' . PHP_EOL . PHP_EOL);
        bs_dim('The Deploy button replaces every file in ' . $webroot . ' (including this installer).');

        return 0;
    } finally {
        bs_remove_tree($work);
    }
}

/**
 * @param list<string> $argv
 * @return array{0: array<string, mixed>, 1: string}
 */
function bs_parse_arguments(array $argv): array
{
    $options = [
        'backend' => getenv('BOOTSTRAP_BACKEND') ?: BS_DEFAULT_BACKEND,
        'token' => getenv('BOOTSTRAP_TOKEN') ?: null,
    ];
    $count = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $argument = (string) $argv[$i];
        if ($argument === '--') {
            break;
        }
        if ($argument === '-h' || $argument === '--help') {
            $options['help'] = true;
            continue;
        }
        if (strpos($argument, '--') !== 0) {
            return [$options, 'Unexpected argument: ' . $argument];
        }

        $body = substr($argument, 2);
        if ($body === '') {
            return [$options, 'Unexpected argument: --'];
        }

        $value = null;
        if (strpos($body, '=') !== false) {
            [$body, $value] = explode('=', $body, 2);
        }

        if (!in_array($body, BS_KNOWN_OPTIONS, true)) {
            return [$options, 'Unknown option: --' . $body];
        }

        if (in_array($body, BS_FLAG_OPTIONS, true)) {
            if ($value !== null) {
                return [$options, '--' . $body . ' does not take a value'];
            }
            $options[$body] = true;
            continue;
        }

        if ($value === null) {
            $next = $argv[$i + 1] ?? null;
            if ($next === null || strpos((string) $next, '--') === 0) {
                return [$options, '--' . $body . ' needs a value'];
            }
            $value = (string) $next;
            $i++;
        }
        if ($value === '') {
            return [$options, '--' . $body . ' needs a value'];
        }
        $options[$body] = $value;
    }

    return [$options, ''];
}

/**
 * @param array<string, mixed> $options
 * @return list<string>
 */
function bs_configure_arguments(array $options, bool $force): array
{
    $arguments = [];
    foreach (['data-dir', 'token', 'owner', 'git-base'] as $name) {
        if (isset($options[$name]) && $options[$name] !== '') {
            $arguments[] = '--' . $name;
            $arguments[] = (string) $options[$name];
        }
    }
    if ($force) {
        $arguments[] = '--force';
    }
    if (($options['skip-health'] ?? false) === true) {
        $arguments[] = '--skip-health';
    }
    if (($options['allow-insecure-backend'] ?? false) === true) {
        $arguments[] = '--allow-insecure-backend';
    }

    return $arguments;
}

function bs_resolve_app_dir(string $path, string $webroot): string
{
    $candidate = $path;
    if (!preg_match('~^([A-Za-z]:[\\\\/]|/)~', $candidate)) {
        $candidate = (string) getcwd() . '/' . $candidate;
    }
    $real = realpath($candidate);
    if ($real === false || !is_dir($real)) {
        bs_fail('Source directory not found: ' . $path);
    }
    if (is_file($real . '/app/lib/bootstrap.php')) {
        return $real . '/app';
    }
    if (is_file($real . '/lib/bootstrap.php')) {
        return $real;
    }

    bs_fail('Not a Bootstrap source directory: ' . $path);
}

function bs_local_app_dir(): ?string
{
    if (__FILE__ === 'Standard input code' || __FILE__ === '-') {
        return null;
    }
    $self = realpath((string) __FILE__);
    if ($self === false || !is_file($self)) {
        return null;
    }
    $directory = dirname($self);
    if (is_file($directory . '/app/lib/bootstrap.php')) {
        return $directory . '/app';
    }

    return null;
}

function bs_temp_dir(string $webroot): string
{
    $bases = [sys_get_temp_dir(), $webroot];
    foreach ($bases as $base) {
        if (!@is_dir($base) || !@is_writable($base)) {
            continue;
        }
        $candidate = rtrim($base, '/') . '/bootstrap-install-' . bin2hex(random_bytes(6));
        if (@mkdir($candidate, 0700, true)) {
            return $candidate;
        }
    }

    bs_fail('No writable temporary directory (check open_basedir and TMPDIR).');
}

function bs_download(string $url, string $destination): bool
{
    if (function_exists('curl_init')) {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $handle = @fopen($destination, 'wb');
            if ($handle === false) {
                return false;
            }
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'bootstrap-installer/1.0',
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($handle);
            if ($ok !== false && $status >= 200 && $status < 300 && @filesize($destination) > 0) {
                return true;
            }
            if ($attempt < 3) {
                sleep(1);
            }
        }

        return false;
    }

    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'user_agent' => 'bootstrap-installer/1.0',
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data !== false && $data !== '') {
            return file_put_contents($destination, $data) !== false;
        }
    }

    return false;
}

function bs_extract_app(string $archive, string $work, string $webroot): string
{
    $target = $work . '/extract';
    if (!@mkdir($target, 0700, true) && !is_dir($target)) {
        bs_fail('Unable to create the extraction directory.');
    }

    $extracted = false;
    if (class_exists('PharData')) {
        try {
            $phar = new PharData($archive);
            $phar->extractTo($target, null, true);
            $extracted = true;
        } catch (Throwable $exception) {
            $extracted = false;
        }
    }

    $app = bs_find_app_dir($target);
    if ($app !== null) {
        return $app;
    }

    $tar = bs_find_binary('tar');
    if ($tar === null) {
        bs_fail('Unable to extract the archive: neither the phar extension nor the tar binary is available.');
    }
    $exit = bs_run([$tar, '-xzf', $archive, '-C', $target]);
    if ($exit !== 0) {
        bs_fail('The tar command failed to extract the archive.');
    }

    $app = bs_find_app_dir($target);
    if ($app === null) {
        bs_fail('The archive does not contain the expected app/ directory.');
    }

    return $app;
}

function bs_find_app_dir(string $base): ?string
{
    $candidates = glob($base . '/' . BS_REPO . '-*', GLOB_ONLYDIR) ?: [];
    $candidates[] = $base . '/' . BS_REPO;
    $candidates[] = $base;
    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/app/lib/bootstrap.php')) {
            return $candidate . '/app';
        }
        if (is_file($candidate . '/lib/bootstrap.php')) {
            return $candidate;
        }
    }

    return null;
}

function bs_find_binary(string $name): ?string
{
    $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, '/') . '/' . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return list<string> relative paths that were copied
 */
function bs_copy_app(string $appDir, string $webroot): array
{
    $copied = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $relative = substr($item->getPathname(), strlen($appDir) + 1);
        if ($relative === 'config.php' || strpos($relative, '.bootstrap-data') === 0) {
            continue;
        }
        $target = $webroot . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                bs_fail('Unable to create ' . $target);
            }
            $copied[] = $relative;
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            bs_fail('Unable to create ' . $parent);
        }
        if (!@copy($item->getPathname(), $target)) {
            bs_fail('Unable to copy ' . $relative . ' into the web root.');
        }
        @chmod($target, 0644);
        $copied[] = $relative;
    }

    @chmod($webroot, 0755);
    if (is_file($webroot . '/bin/configure.php')) {
        @chmod($webroot . '/bin/configure.php', 0755);
    }

    return $copied;
}

/**
 * @param list<string> $command
 */
function bs_run(array $command): int
{
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        bs_fail('Unable to start: ' . implode(' ', $command));
    }

    return proc_close($process);
}

function bs_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        bs_remove_tree($path . '/' . $entry);
    }
    @rmdir($path);
}

function bs_supports_color(): bool
{
    if (getenv('NO_COLOR') !== false) {
        return false;
    }
    if (!function_exists('stream_isatty')) {
        return false;
    }

    return @stream_isatty(STDOUT);
}

function bs_color(string $text, string $code): string
{
    if (!bs_supports_color()) {
        return $text;
    }

    return "\033[" . $code . 'm' . $text . "\033[0m";
}

function bs_info(string $message): void
{
    fwrite(STDOUT, bs_color('==>', '0;36') . ' ' . $message . PHP_EOL);
}

function bs_ok(string $message): void
{
    fwrite(STDOUT, bs_color('  ok', '0;32') . ' ' . $message . PHP_EOL);
}

function bs_dim(string $message): void
{
    fwrite(STDOUT, bs_color($message, '2') . PHP_EOL);
}

function bs_fail(string $message): void
{
    fwrite(STDERR, bs_color('FAIL', '0;31') . ' ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @param resource $stream
 */
function bs_usage($stream): void
{
    $text = <<<'TXT'
Bootstrap installer

Usage:
  install.php --webroot <path> [options]

Options:
  --webroot <path>           Document root to install into (default: current directory)
  --backend <url>            BootStrapServer base URL (default: https://bootstrap.dark.net.au)
  --data-dir <path>          Runtime data directory (default: outside the web root)
  --token <token>            Optional GitHub token so private repos are listed
  --owner <name>             GitHub owner (default: LightNetAI)
  --git-base <url>           Git base URL (default: git@github.com:<owner>)
  --archive <file>           Use a local .tar.gz instead of downloading (offline installs)
  --source <dir>             Install from a local checkout instead of downloading
  --branch <name>            Git branch of this installer (default: main)
  --force                    Overwrite an existing install (keeps working config)
  --skip-health              Do not probe the backend during the self-test
  --allow-insecure-backend   Allow an http:// backend URL (local testing only)
  --no-color                 Disable coloured output
  -h, --help                 Show this help

Examples:
  curl -fsSL https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.php \
    | php -- --webroot "$HOME/public_html"

  php install.php --webroot /home/site/public_html --token ghp_xxx
TXT;

    fwrite($stream, $text . PHP_EOL);
}
