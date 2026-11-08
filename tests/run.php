<?php
declare(strict_types=1);

/**
 * Bootstrap frontend test suite.
 *
 *   php tests/run.php
 */

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/lib/bootstrap.php';

use Bootstrap\Config;
use Bootstrap\Crypto;
use Bootstrap\DeployException;
use Bootstrap\Deployer;
use Bootstrap\Files;
use Bootstrap\GitHub;
use Bootstrap\Process;
use Bootstrap\SshKey;

$root = tempDir('frontend');
$appSource = dirname(__DIR__) . '/app';

function copyTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create ' . $destination);
    }
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;
        if (is_dir($from)) {
            copyTree($from, $to);
        } else {
            copy($from, $to);
        }
    }
}

/**
 * Create a fake installed web root with config.php and a manifest.
 *
 * @param array<string, mixed> $overrides
 * @return array{webroot: string, data_dir: string, config: Config}
 */
function makeInstall(string $root, string $source, array $overrides = []): array
{
    $webroot = $root . '/site-' . bin2hex(random_bytes(3));
    copyTree($source, $webroot);

    $dataDir = $root . '/.bootstrap-data-' . bin2hex(random_bytes(3));
    mkdir($dataDir . '/sessions', 0700, true);

    $values = array_replace_recursive(Config::defaults(), [
        'backend_url' => 'https://bootstrap.example.test',
        'data_dir' => $dataDir,
        'git_base' => 'file://' . $root . '/repos',
        'deploy' => ['timeout' => 60],
    ], $overrides);

    file_put_contents(
        $webroot . '/config.php',
        "<?php\nreturn " . var_export($values, true) . ";\n"
    );

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($webroot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = ltrim(substr($file->getPathname(), strlen($webroot)), '/');
        }
    }
    file_put_contents(
        $webroot . '/.bootstrap-manifest.json',
        json_encode(['files' => $files], JSON_PRETTY_PRINT)
    );

    return [
        'webroot' => $webroot,
        'data_dir' => $dataDir,
        'config' => Config::load($webroot . '/config.php'),
    ];
}

/** @return array<string, string> */
function gitEnv(string $home): array
{
    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => $home,
        'GIT_CONFIG_NOSYSTEM' => '1',
        'LANG' => 'C',
    ];
}

function makeRepo(string $reposDir, string $root, string $name, array $files): void
{
    $work = $root . '/work-' . $name . '-' . bin2hex(random_bytes(3));
    mkdir($work, 0755, true);
    foreach ($files as $file => $contents) {
        $path = $work . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    $env = gitEnv($root);
    $commands = [
        ['git', 'init', '-q', '-b', 'main', $work],
        ['git', '-C', $work, 'add', '-A'],
        ['git', '-C', $work, '-c', 'user.name=Tests', '-c', 'user.email=tests@example.test', 'commit', '-q', '-m', 'fixture'],
        ['git', 'clone', '-q', '--bare', $work, $reposDir . '/' . $name . '.git'],
    ];
    foreach ($commands as $command) {
        $result = Process::run($command, $env, null, 60);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('git fixture failed: ' . implode(' ', $command) . ' :: ' . $result['stderr']);
        }
    }
    Files::rmrf($work);
}

function fakePrivateKey(): string
{
    // The key is never used against a real SSH host in these tests (git_base is file://).
    return "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n";
}

mkdir($root . '/repos', 0755, true);

fwrite(STDOUT, "Bootstrap frontend tests\n");

TestSuite::test('frontend opens a valid key envelope', function () use ($root): void {
    $keyDir = $root . '/keys-' . bin2hex(random_bytes(3));
    mkdir($keyDir, 0700, true);
    $result = Process::run(
        ['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', 'envelope@test', '-f', $keyDir . '/id'],
        gitEnv($root),
        null,
        30
    );
    TestSuite::assertSame(0, $result['exit'], 'ssh-keygen failed: ' . $result['stderr']);

    $private = (string) file_get_contents($keyDir . '/id');
    $public = trim((string) file_get_contents($keyDir . '/id.pub'));
    $fingerprint = Crypto::fingerprint($public);

    $password = 'CorrectHorseBattery9!';
    $salt = random_bytes(16);
    $iterations = 10000;
    $key = Crypto::deriveKey($password, $salt, $iterations);
    $aad = Crypto::transportAad('alice', $fingerprint);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($private, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);

    $envelope = [
        'v' => 1,
        'kdf' => ['alg' => 'pbkdf2-sha256', 'iterations' => $iterations, 'salt' => base64_encode($salt), 'dklen' => 32],
        'cipher' => ['alg' => 'aes-256-gcm', 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'aad' => $aad],
        'key' => ['name' => 'default', 'type' => 'ssh-ed25519', 'public' => $public, 'fingerprint' => $fingerprint],
        'sealed' => base64_encode((string) $ciphertext),
    ];

    $decrypted = Crypto::openEnvelope($password, $envelope, 'alice');
    TestSuite::assertContains('BEGIN OPENSSH PRIVATE KEY', $decrypted);
    TestSuite::assertSame(
        explode(' ', $public)[1],
        explode(' ', (string) SshKey::publicFromPrivate($decrypted))[1]
    );

    // Wrong password.
    TestSuite::expectException(fn () => Crypto::openEnvelope('wrong-password', $envelope, 'alice'), RuntimeException::class);

    // Username binding is checked before decryption.
    TestSuite::expectException(fn () => Crypto::openEnvelope($password, $envelope, 'bob'), RuntimeException::class);

    // A swapped public key must be rejected.
    $tampered = $envelope;
    $other = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    $tampered['key']['public'] = $other;
    $tampered['key']['fingerprint'] = Crypto::fingerprint($other);
    $tampered['cipher']['aad'] = Crypto::transportAad('alice', $tampered['key']['fingerprint']);
    TestSuite::expectException(fn () => Crypto::openEnvelope($password, $tampered, 'alice'), RuntimeException::class);

    Files::rmrf($keyDir);
});

TestSuite::test('config rejects plain-http backend unless explicitly allowed', function () use ($root): void {
    $file = $root . '/config-http.php';
    file_put_contents($file, "<?php\nreturn " . var_export([
        'backend_url' => 'http://insecure.example.test',
        'data_dir' => $root . '/tmp-data',
    ], true) . ";\n");
    TestSuite::expectException(fn () => Config::load($file), RuntimeException::class);

    file_put_contents($file, "<?php\nreturn " . var_export([
        'backend_url' => 'http://127.0.0.1:8099',
        'allow_insecure_backend' => true,
        'data_dir' => $root . '/tmp-data',
    ], true) . ";\n");
    $config = Config::load($file);
    TestSuite::assertSame('http://127.0.0.1:8099', $config->backendUrl());
});

TestSuite::test('process helper enforces a timeout', function () use ($root): void {
    TestSuite::expectException(
        fn () => Process::run([PHP_BINARY, '-r', 'sleep(5);'], gitEnv($root), null, 1),
        RuntimeException::class
    );
});

TestSuite::test('deploy swaps the web root and removes the installer', function () use ($root, $appSource): void {
    makeRepo($root . '/repos', $root, 'demoapp', [
        'index.php' => "<?php echo 'demo-application';\n",
        'README.md' => 'demo',
        'assets/x.txt' => 'x',
    ]);

    $install = makeInstall($root, $appSource);
    $deployer = new Deployer($install['config'], fakePrivateKey(), 'ssh-ed25519 AAAA', $install['webroot']);
    $result = $deployer->deploy('demoapp', false);

    TestSuite::assertSame(true, $result['ok']);
    TestSuite::assertContains('demo-application', (string) file_get_contents($install['webroot'] . '/index.php'));
    TestSuite::assertTrue(is_file($install['webroot'] . '/README.md'));
    TestSuite::assertTrue(!is_dir($install['webroot'] . '/lib'), 'bootstrap lib/ survived');
    TestSuite::assertTrue(!is_file($install['webroot'] . '/config.php'), 'bootstrap config survived');
    TestSuite::assertTrue(!is_dir($install['webroot'] . '/.git'), '.git should not be web accessible');

    $stage = (string) $result['staging'];
    $old = (string) $result['old'];
    TestSuite::assertTrue(is_dir($stage), 'staging dir should remain until cleanup');

    $deployer->cleanup();
    TestSuite::assertTrue(!is_dir($stage), 'staging dir not cleaned');
    TestSuite::assertTrue(!is_dir($old), 'old web root not cleaned');
    TestSuite::assertTrue(!is_dir($install['data_dir']), 'data dir not cleaned');
    TestSuite::assertTrue(is_file($install['webroot'] . '/index.php'), 'deployed site was removed by cleanup');
});

TestSuite::test('deploy rolls back when the project has no index file', function () use ($root, $appSource): void {
    makeRepo($root . '/repos', $root, 'noindex', ['README.md' => 'no index here']);

    $install = makeInstall($root, $appSource);
    $original = (string) file_get_contents($install['webroot'] . '/index.php');

    $deployer = new Deployer($install['config'], fakePrivateKey(), 'ssh-ed25519 AAAA', $install['webroot']);
    TestSuite::expectException(fn () => $deployer->deploy('noindex', false), DeployException::class);

    TestSuite::assertSame($original, (string) file_get_contents($install['webroot'] . '/index.php'), 'rollback did not restore index.php');
    TestSuite::assertTrue(is_dir($install['webroot'] . '/lib'), 'rollback lost bootstrap files');
    TestSuite::assertTrue(is_file($install['webroot'] . '/config.php'), 'rollback lost config.php');
});

TestSuite::test('deploy refuses unknown web root files without force', function () use ($root, $appSource): void {
    makeRepo($root . '/repos', $root, 'demoapp2', ['index.php' => "<?php echo 'two';\n"]);

    $install = makeInstall($root, $appSource);
    file_put_contents($install['webroot'] . '/customer-notes.txt', 'do not delete');

    $deployer = new Deployer($install['config'], fakePrivateKey(), 'ssh-ed25519 AAAA', $install['webroot']);
    try {
        $deployer->deploy('demoapp2', false);
        TestSuite::fail('deploy should have refused unknown files');
    } catch (DeployException $exception) {
        TestSuite::assertContains('not installed by bootstrap', $exception->getMessage());
    }
    TestSuite::assertTrue(is_file($install['webroot'] . '/customer-notes.txt'), 'file deleted without force');

    $deployer = new Deployer($install['config'], fakePrivateKey(), 'ssh-ed25519 AAAA', $install['webroot']);
    $result = $deployer->deploy('demoapp2', true);
    TestSuite::assertSame(true, $result['ok']);
    TestSuite::assertTrue(!is_file($install['webroot'] . '/customer-notes.txt'), 'force did not delete the file');

    $deployer->cleanup();
});

TestSuite::test('deploy rejects path traversal in repository names', function () use ($root, $appSource): void {
    $install = makeInstall($root, $appSource);
    $deployer = new Deployer($install['config'], fakePrivateKey(), 'ssh-ed25519 AAAA', $install['webroot']);

    foreach (['../etc/passwd', '..', 'a/b', 'repo space', ''] as $name) {
        TestSuite::expectException(fn () => $deployer->deploy($name, true), DeployException::class);
    }
});

TestSuite::test('install.php installs from a local source and refuses to overwrite', function () use ($root, $appSource): void {
    $webroot = $root . '/installer-' . bin2hex(random_bytes(3));
    $command = [
        PHP_BINARY,
        dirname(__DIR__) . '/install.php',
        '--source', $appSource,
        '--webroot', $webroot,
        '--backend', 'https://bootstrap.example.test',
        '--skip-health',
    ];

    $result = Process::run($command, gitEnv($root), $root, 120);
    TestSuite::assertSame(0, $result['exit'], $result['stderr']);
    TestSuite::assertTrue(is_file($webroot . '/config.php'), 'config.php missing');
    TestSuite::assertTrue(is_file($webroot . '/.bootstrap-manifest.json'), 'manifest missing');
    TestSuite::assertTrue(is_executable($webroot . '/bin/configure.php'), 'configure.php not executable');
    TestSuite::assertSame('0600', substr(sprintf('%o', fileperms($webroot . '/config.php')), -4));

    $again = Process::run($command, gitEnv($root), $root, 120);
    TestSuite::assertTrue($again['exit'] !== 0, 'second install should fail without --force');
    TestSuite::assertContains('already contains a Bootstrap installation', $again['stderr']);

    $forced = Process::run(array_merge($command, ['--force']), gitEnv($root), $root, 120);
    TestSuite::assertSame(0, $forced['exit'], $forced['stderr']);

    Files::rmrf($webroot);
    foreach (glob($root . '/.bootstrap-data-*') ?: [] as $dataDir) {
        Files::rmrf($dataDir);
    }
});

TestSuite::test('install.php extracts a release archive', function () use ($root, $appSource): void {
    $build = $root . '/build-' . bin2hex(random_bytes(3));
    mkdir($build . '/bootstrap-main', 0755, true);
    copyTree($appSource, $build . '/bootstrap-main/app');

    $archive = $build . '/release.tar.gz';
    $tar = Process::run(
        ['tar', '-czf', $archive, '-C', $build, 'bootstrap-main'],
        gitEnv($root),
        null,
        60
    );
    TestSuite::assertSame(0, $tar['exit'], 'could not build the test archive: ' . $tar['stderr']);

    $webroot = $root . '/installer-archive-' . bin2hex(random_bytes(3));
    $result = Process::run([
        PHP_BINARY,
        dirname(__DIR__) . '/install.php',
        '--archive', $archive,
        '--webroot', $webroot,
        '--backend', 'https://bootstrap.example.test',
        '--skip-health',
    ], gitEnv($root), $root, 120);

    TestSuite::assertSame(0, $result['exit'], $result['stderr']);
    TestSuite::assertTrue(is_file($webroot . '/index.php'), 'index.php missing');
    TestSuite::assertTrue(is_file($webroot . '/lib/bootstrap.php'), 'lib missing');
    TestSuite::assertTrue(is_file($webroot . '/config.php'), 'config.php missing');

    Files::rmrf($webroot);
    Files::rmrf($build);
    foreach (glob($root . '/.bootstrap-data-*') ?: [] as $dataDir) {
        Files::rmrf($dataDir);
    }
});

TestSuite::test('token listing includes private repositories and falls back on rejection', function () use ($root, $appSource): void {
    $port = random_int(20000, 45000);
    $logFile = $root . '/github-server.log';
    $args = array_values(array_filter(
        preg_split('/\s+/', trim((string) getenv('TEST_PHP_ARGS'))) ?: [],
        static fn (string $arg): bool => $arg !== ''
    ));
    $command = array_merge(
        [PHP_BINARY],
        $args,
        ['-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/tests/github-router.php']
    );

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        null,
        array_merge(getenv(), ['HOME' => $root])
    );
    TestSuite::assertTrue(is_resource($process), 'could not start the fake GitHub server');
    fclose($pipes[0]);

    try {
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            usleep(100000);
            [$status] = httpCall('GET', 'http://127.0.0.1:' . $port . '/users/LightNetAI/repos');
            if ($status === 200) {
                $ready = true;
                break;
            }
        }
        TestSuite::assertTrue(
            $ready,
            'fake GitHub did not become ready: ' . substr((string) @file_get_contents($logFile), 0, 400)
        );

        $api = 'http://127.0.0.1:' . $port;
        $install = makeInstall($root, $appSource, [
            'github_api' => $api,
            'github_token' => 'good-token',
        ]);
        $result = (new GitHub($install['config']))->listRepos(true);
        $names = array_column($result['repos'], 'name');
        TestSuite::assertTrue(in_array('private-one', $names, true), 'private repository missing');
        TestSuite::assertTrue(in_array('public-one', $names, true), 'public repository missing');
        TestSuite::assertTrue(!in_array('someone-else', $names, true), 'repository of another owner leaked in');

        $rejected = makeInstall($root, $appSource, [
            'github_api' => $api,
            'github_token' => 'bad-token',
        ]);
        $fallback = (new GitHub($rejected['config']))->listRepos(true);
        $fallbackNames = array_column($fallback['repos'], 'name');
        TestSuite::assertSame(['public-one'], $fallbackNames, 'public fallback');
        TestSuite::assertContains('rejected', $fallback['message']);
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});

TestSuite::test('frontend page renders and rejects a POST without CSRF', function () use ($root, $appSource): void {
    $install = makeInstall($root, $appSource);
    $port = random_int(20000, 45000);
    $args = array_values(array_filter(
        preg_split('/\s+/', trim((string) getenv('TEST_PHP_ARGS'))) ?: [],
        static fn (string $arg): bool => $arg !== ''
    ));
    $command = array_merge(
        [PHP_BINARY],
        $args,
        ['-S', '127.0.0.1:' . $port, '-t', $install['webroot'], dirname(__DIR__) . '/tests/router.php']
    );

    $logFile = $install['data_dir'] . '/server.log';
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        null,
        array_merge(getenv(), [
            'HOME' => $root,
            'BOOTSTRAP_WEBROOT' => $install['webroot'],
        ])
    );
    TestSuite::assertTrue(is_resource($process), 'could not start the frontend server');
    fclose($pipes[0]);

    try {
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            usleep(100000);
            [$status] = httpCall('GET', 'http://127.0.0.1:' . $port . '/');
            if ($status === 200) {
                $ready = true;
                break;
            }
        }
        TestSuite::assertTrue(
            $ready,
            'frontend did not become ready: ' . substr((string) @file_get_contents($logFile), 0, 600)
        );

        [$status, $body, $headers] = httpCall('GET', 'http://127.0.0.1:' . $port . '/');
        TestSuite::assertSame(200, $status);
        TestSuite::assertContains('login-form', $body);
        TestSuite::assertContains('csrf-token', $body);
        TestSuite::assertContains('Content-Security-Policy', implode("\n", $headers));

        [$status] = httpCall(
            'POST',
            'http://127.0.0.1:' . $port . '/?a=login',
            json_encode(['username' => 'a', 'password' => 'b']),
            ['Content-Type: application/json']
        );
        TestSuite::assertSame(419, $status, 'CSRF protection missing');
    } finally {
        proc_terminate($process, 9);
        proc_close($process);
    }

    Files::rmrf($install['webroot']);
    Files::rmrf($install['data_dir']);
});

$exit = TestSuite::summary('Bootstrap frontend');
rmTree($root);
exit($exit);
