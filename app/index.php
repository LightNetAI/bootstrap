<?php
declare(strict_types=1);

/**
 * Bootstrap frontend controller.
 *
 * GET  /            login page or dashboard
 * POST ?a=login     verify credentials with BootStrapServer, decrypt the SSH key
 * GET  ?a=repos     list LightNetAI repositories
 * POST ?a=deploy    clone + deploy a repository over this installer
 * POST ?a=logout
 */

require __DIR__ . '/lib/bootstrap.php';

use Bootstrap\AuthException;
use Bootstrap\BackendClient;
use Bootstrap\Config;
use Bootstrap\Crypto;
use Bootstrap\DeployException;
use Bootstrap\Deployer;
use Bootstrap\Files;
use Bootstrap\GitHub;
use Bootstrap\GitSsh;
use Bootstrap\Http;
use Bootstrap\Session;
use Bootstrap\View;

$https = Http::requestIsHttps();
Http::securityHeaders($https);

try {
    $config = Config::load();
} catch (\Throwable $exception) {
    renderFatal(
        'Bootstrap is not configured',
        $exception->getMessage(),
        'Run the installer again: curl -fsSL https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.php | php -- --webroot /path/to/public_html'
    );
}

try {
    Session::start($config);
} catch (\Throwable $exception) {
    renderFatal('Session unavailable', $exception->getMessage(), 'Check that the data directory is writable by PHP.');
}

$action = (string) ($_GET['a'] ?? '');

try {
    switch ($action) {
        case '':
            page($config);
            break;
        case 'login':
            doLogin($config);
            break;
        case 'logout':
            doLogout($config);
            break;
        case 'repos':
            doRepos($config);
            break;
        case 'deploy':
            doDeploy($config);
            break;
        default:
            Http::jsonError('Unknown action.', 404, 'not_found');
    }
} catch (AuthException $exception) {
    Http::jsonError($exception->getMessage(), 401, 'unauthenticated');
} catch (DeployException $exception) {
    Http::jsonError($exception->getMessage(), 422, 'deploy_failed');
} catch (\Throwable $exception) {
    error_log('bootstrap: ' . $exception::class . ': ' . $exception->getMessage());
    Http::jsonError(
        $config->isDebug() ? $exception->getMessage() : 'Unexpected server error.',
        500,
        'server_error'
    );
}

function page(Config $config): void
{
    if (Session::isAuthenticated($config)) {
        $auth = Session::auth($config);
        View::render('dashboard', [
            'username' => (string) $auth['username'],
            'fingerprint' => (string) ($auth['fingerprint'] ?? ''),
            'github' => (array) ($auth['github'] ?? []),
            'owner' => $config->githubOwner(),
            'backend_host' => (string) parse_url($config->backendUrl(), PHP_URL_HOST),
            'token_configured' => $config->getString('github_token') !== '',
        ]);

        return;
    }

    View::render('login', [
        'backend_host' => (string) parse_url($config->backendUrl(), PHP_URL_HOST),
        'owner' => $config->githubOwner(),
    ]);
}

function doLogin(Config $config): never
{
    Http::requirePost();
    requireCsrf();

    $payload = readJsonBody();
    $username = trim((string) ($payload['username'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($username === '' || $password === '') {
        Http::jsonError('Enter your username and password.', 400, 'missing_credentials');
    }
    if (strlen($username) > 64 || strlen($password) > 1024) {
        Http::jsonError('Username or password is too long.', 400, 'invalid_credentials');
    }

    $client = new BackendClient($config);
    $result = $client->login($username, $password);

    if (!$result['ok']) {
        $status = in_array($result['status'], [401, 429, 409], true) ? $result['status'] : 502;
        Http::json([
            'ok' => false,
            'error' => [
                'code' => $result['code'] !== '' ? $result['code'] : 'login_failed',
                'message' => $result['error'],
                'retry_after' => $result['retry_after'],
            ],
        ], $status);
    }

    $envelope = $result['data']['key'] ?? null;
    if (!is_array($envelope)) {
        Http::jsonError('The server response did not contain a key envelope.', 502, 'invalid_backend_response');
    }

    try {
        $privateKey = Crypto::openEnvelope($password, $envelope, $username);
    } catch (\Throwable $exception) {
        Http::jsonError(
            'The SSH key could not be verified: ' . $exception->getMessage(),
            502,
            'envelope_invalid'
        );
    }

    $keyInfo = is_array($envelope['key'] ?? null) ? $envelope['key'] : [];
    $publicKey = (string) ($keyInfo['public'] ?? '');
    $fingerprint = (string) ($keyInfo['fingerprint'] ?? '');

    $github = testGithubAccess($config, $privateKey);

    Session::establish([
        'username' => (string) $result['data']['user']['username'],
        'private_key' => $privateKey,
        'public_key' => $publicKey,
        'fingerprint' => $fingerprint,
        'key_name' => (string) ($keyInfo['name'] ?? 'default'),
        'backend' => (string) parse_url($config->backendUrl(), PHP_URL_HOST),
        'github' => $github,
    ], $config);

    Http::json([
        'ok' => true,
        'username' => (string) $result['data']['user']['username'],
        'fingerprint' => $fingerprint,
        'github' => $github,
    ]);
}

function doLogout(Config $config): never
{
    Http::requirePost();
    requireCsrf();
    Session::destroy();
    Http::json(['ok' => true]);
}

function doRepos(Config $config): never
{
    $auth = Session::auth($config);

    $github = new GitHub($config);
    $listing = $github->listRepos(isset($_GET['refresh']));

    $deployer = new Deployer($config, (string) $auth['private_key'], (string) $auth['public_key']);
    $inspect = $deployer->inspect();
    unset($inspect['webroot']);

    Http::json([
        'ok' => true,
        'repos' => $listing['repos'],
        'message' => $listing['message'],
        'source' => $listing['source'],
        'rate_limited' => $listing['rate_limited'],
        'github' => (array) ($auth['github'] ?? []),
        'deploy' => $inspect,
        'token_configured' => $config->getString('github_token') !== '',
        'owner' => $config->githubOwner(),
    ]);
}

function doDeploy(Config $config): never
{
    Http::requirePost();
    requireCsrf();

    $auth = Session::auth($config);
    $payload = readJsonBody();
    $repo = trim((string) ($payload['repo'] ?? ''));
    $force = (bool) ($payload['force'] ?? false);

    if ($repo === '') {
        Http::jsonError('Choose a project to deploy.', 400, 'missing_repo');
    }

    ignore_user_abort(true);
    set_time_limit(0);

    $deployer = new Deployer($config, (string) $auth['private_key'], (string) $auth['public_key']);

    try {
        $result = $deployer->deploy($repo, $force);
    } catch (DeployException $exception) {
        Http::jsonError($exception->getMessage(), 422, 'deploy_failed');
    }

    unset($result['staging'], $result['old']);

    Http::emitJson($result);
    Http::finish();

    // The web root now belongs to the deployed project. Remove the old tree
    // (which contains this script) and the bootstrap data directory.
    try {
        $deployer->cleanup();
    } catch (\Throwable $exception) {
        error_log('bootstrap: cleanup failed: ' . $exception->getMessage());
    }

    exit;
}

/** @return array{ok: bool, account: string, message: string} */
function testGithubAccess(Config $config, string $privateKey): array
{
    $directory = $config->dataDir() . '/tmp/ssh-' . bin2hex(random_bytes(6));
    try {
        Files::ensureDir($directory, 0700);
        $ssh = new GitSsh($config, $privateKey, $directory);
        $ssh->prepare();

        return $ssh->testConnection();
    } catch (\Throwable $exception) {
        return ['ok' => false, 'account' => '', 'message' => $exception->getMessage()];
    } finally {
        Files::rmrf($directory);
    }
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Session::verifyCsrf(is_string($token) ? $token : '')) {
        Http::jsonError('Your session token is invalid. Reload the page and try again.', 419, 'csrf_failed');
    }
}

/** @return array<string, mixed> */
function readJsonBody(int $maxBytes = 16384): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        Http::jsonError('Unable to read the request body.', 400, 'invalid_body');
    }
    if (strlen($raw) > $maxBytes) {
        Http::jsonError('Request body is too large.', 413, 'payload_too_large');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        Http::jsonError('Request body must be JSON.', 400, 'invalid_json');
    }

    return $decoded;
}

function renderFatal(string $title, string $message, string $hint = ''): never
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($title) . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0b1020;color:#e6e9f2;'
        . 'display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}'
        . 'main{max-width:640px;background:#141a2f;border:1px solid #26304d;border-radius:16px;padding:32px}'
        . 'h1{font-size:1.3rem;margin:0 0 12px}p{line-height:1.6;color:#a8b0c8}code{color:#9fb4ff}'
        . '.hint{margin-top:16px;padding:12px 14px;background:#1b2440;border-radius:10px;font-size:.9rem}</style></head><body><main>'
        . '<h1>' . $e($title) . '</h1><p>' . $e($message) . '</p>'
        . ($hint !== '' ? '<div class="hint">' . $e($hint) . '</div>' : '')
        . '</main></body></html>';
    exit;
}
