<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;
use Throwable;

/**
 * Deploys a git repository over the running bootstrap application.
 *
 * The web root is never left half-written:
 *   1. clone into <webroot>/.bs-stage-XXXX/repo  (same filesystem)
 *   2. optionally run composer install
 *   3. move the current web root contents into <webroot>/.bs-old-XXXX
 *   4. move the cloned tree into place
 *   5. verify an index file exists, otherwise roll back
 *   6. answer the HTTP request, then delete the old tree and the bootstrap files
 *
 * Steps 3-5 are renames on the same filesystem, so the swap is near instant.
 * Deleting the old tree also deletes this very script - on Linux the open
 * inode stays valid until the request ends, which is why the JSON response is
 * flushed before cleanup runs.
 */
final class Deployer
{
    private string $webroot;
    private string $stageDir = '';
    private string $oldDir = '';
    private string $repoDir = '';
    /** @var list<string> */
    private array $steps = [];

    public function __construct(
        private Config $config,
        private string $privateKey,
        private string $publicKey,
        ?string $webroot = null,
    ) {
        $this->webroot = $webroot ?? Paths::webroot();
    }

    /**
     * @return array{
     *     webroot: string,
     *     writable: bool,
     *     git: bool,
     *     git_version: string,
     *     proc_open: bool,
     *     open_basedir: string,
     *     unknown: list<string>,
     *     deployable: bool,
     *     warnings: list<string>
     * }
     */
    public function inspect(): array
    {
        $warnings = [];
        $gitVersion = $this->gitVersion();
        $procOpen = function_exists('proc_open');
        $writable = is_dir($this->webroot) && is_writable($this->webroot);

        if (!$writable) {
            $warnings[] = 'The web root is not writable by PHP.';
        }
        if ($gitVersion === null) {
            $warnings[] = 'git is not available to PHP (check disable_functions and PATH).';
        }
        if (!$procOpen) {
            $warnings[] = 'proc_open() is disabled by the host.';
        }
        $openBasedir = trim((string) ini_get('open_basedir'));
        if ($openBasedir !== '' && !Paths::allowedByOpenBaseDir($this->webroot)) {
            $warnings[] = 'The web root is outside open_basedir.';
        }

        $unknown = $this->unknownEntries();
        if ($unknown !== []) {
            $warnings[] = 'The web root contains files that the installer did not create: '
                . implode(', ', $unknown) . '. They will be deleted on deploy.';
        }

        return [
            'webroot' => $this->webroot,
            'writable' => $writable,
            'git' => $gitVersion !== null,
            'git_version' => $gitVersion ?? '',
            'proc_open' => $procOpen,
            'open_basedir' => $openBasedir === '' ? 'not set' : $openBasedir,
            'unknown' => $unknown,
            'deployable' => $writable && $gitVersion !== null && $procOpen,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deploy(string $repo, bool $forceUnknown): array
    {
        $this->assertRepoName($repo);

        $lock = $this->acquireLock();
        try {
            $inspect = $this->inspect();
            if (!$inspect['deployable']) {
                throw new DeployException(implode(' ', $inspect['warnings']));
            }
            if ($inspect['unknown'] !== [] && !$forceUnknown) {
                throw new DeployException(
                    'The web root contains files that were not installed by bootstrap: '
                    . implode(', ', $inspect['unknown'])
                    . '. Confirm that they can be deleted and try again.'
                );
            }

            $this->stageDir = $this->webroot . '/.bs-stage-' . bin2hex(random_bytes(5));
            $this->oldDir = $this->webroot . '/.bs-old-' . bin2hex(random_bytes(5));
            $this->repoDir = $this->stageDir . '/repo';

            $this->mkdir($this->stageDir);
            $this->steps[] = 'Prepared a staging directory inside the web root.';

            $ssh = new GitSsh($this->config, $this->privateKey, $this->stageDir);
            $ssh->prepare();
            $this->steps[] = 'Installed the SSH identity and pinned github.com host keys.';

            $this->cloneRepository($ssh, $repo);
            $this->verifyCheckout();

            if (!$this->config->deployOption('keep_git', false)) {
                $this->rmrf($this->repoDir . '/.git');
                $this->steps[] = 'Removed .git so the source history is not web-accessible.';
            }

            $this->maybeComposerInstall();

            $branch = $this->gitBranch();
            $fileCount = $this->countFiles($this->repoDir);

            $this->swap();
            $this->steps[] = sprintf('Moved %d file(s) into the web root and deleted the bootstrap files.', $fileCount);

            return [
                'ok' => true,
                'repo' => $repo,
                'branch' => $branch,
                'url' => Http::currentUrl(),
                'files' => $fileCount,
                'steps' => $this->steps,
                'staging' => $this->stageDir,
                'old' => $this->oldDir,
            ];
        } catch (Throwable $exception) {
            $this->cleanupStagingOnly();
            throw $exception;
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * Removes the previous web root, the staging directory and this
     * application's data directory. Called after the HTTP response is flushed.
     */
    public function cleanup(): void
    {
        foreach ([$this->oldDir, $this->stageDir] as $directory) {
            if ($directory === '') {
                continue;
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                if ($this->rmrf($directory)) {
                    break;
                }
                usleep(150000);
            }
        }

        $dataDir = $this->config->dataDir();
        if ($this->canRemoveDataDir($dataDir)) {
            $this->rmrf($dataDir);
        }
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }

    private function cloneRepository(GitSsh $ssh, string $repo): void
    {
        $git = $this->config->getString('git_binary', 'git');
        $branch = trim($this->config->getString('deploy.branch'));
        $timeout = max(30, $this->config->getInt('deploy.timeout', 300));

        $command = [$git, 'clone', '--depth', '1', '--no-tags', '--single-branch'];
        if ($branch !== '') {
            $command[] = '--branch';
            $command[] = $branch;
        }
        $command[] = $ssh->cloneUrl($repo);
        $command[] = $this->repoDir;

        $result = Process::run($command, $ssh->env(), $this->stageDir, $timeout);
        $this->steps[] = sprintf('Cloned %s (exit %d).', $ssh->cloneUrl($repo), $result['exit']);

        if ($result['exit'] !== 0) {
            throw new DeployException('git clone failed: ' . $this->sanitize($result['stderr'] ?: $result['stdout']));
        }
    }

    private function verifyCheckout(): void
    {
        if (!is_dir($this->repoDir . '/.git')) {
            throw new DeployException('The clone did not produce a git checkout.');
        }

        $git = $this->config->getString('git_binary', 'git');
        $result = Process::run(
            [$git, '-C', $this->repoDir, 'rev-parse', '--verify', 'HEAD'],
            [],
            $this->stageDir,
            30
        );
        if ($result['exit'] !== 0) {
            throw new DeployException('The repository is empty or unreadable.');
        }
    }

    private function maybeComposerInstall(): void
    {
        if (!$this->config->deployOption('composer_install', false)) {
            return;
        }
        if (!is_file($this->repoDir . '/composer.json')) {
            return;
        }

        $composer = $this->config->getString('deploy.composer_binary', 'composer');
        $result = Process::run(
            [$composer, 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'],
            $this->gitEnvironment(),
            $this->repoDir,
            max(60, $this->config->getInt('deploy.timeout', 300))
        );
        $this->steps[] = 'composer install exit ' . $result['exit'] . '.';

        if ($result['exit'] !== 0) {
            throw new DeployException('composer install failed: ' . $this->sanitize($result['stderr'] ?: $result['stdout']));
        }
    }

    /** @return array<string, string> */
    private function gitEnvironment(): array
    {
        $path = getenv('PATH');

        return [
            'PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->stageDir . '/home',
            'TMPDIR' => $this->stageDir . '/tmp',
            'COMPOSER_NO_INTERACTION' => '1',
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ];
    }

    private function swap(): void
    {
        $this->mkdir($this->oldDir);

        $movedOut = [];
        $movedIn = [];

        try {
            foreach ($this->entries($this->webroot) as $entry) {
                if (str_starts_with($entry, '.bs-stage-') || str_starts_with($entry, '.bs-old-')) {
                    continue;
                }
                if (!@rename($this->webroot . '/' . $entry, $this->oldDir . '/' . $entry)) {
                    throw new DeployException('Unable to move "' . $entry . '" out of the web root.');
                }
                $movedOut[] = $entry;
            }

            foreach ($this->entries($this->repoDir) as $entry) {
                if (!@rename($this->repoDir . '/' . $entry, $this->webroot . '/' . $entry)) {
                    throw new DeployException('Unable to move "' . $entry . '" into the web root.');
                }
                $movedIn[] = $entry;
            }

            $requireIndex = (bool) $this->config->deployOption('require_index', true);
            if ($requireIndex
                && !is_file($this->webroot . '/index.php')
                && !is_file($this->webroot . '/index.html')
                && !is_file($this->webroot . '/public/index.php')) {
                throw new DeployException('The project has no index.php/index.html at its root, so the site would break.');
            }

            if ($this->config->deployOption('keep_git', false) && is_dir($this->webroot . '/.git')) {
                @file_put_contents(
                    $this->webroot . '/.git/.htaccess',
                    "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
                );
            }
        } catch (Throwable $exception) {
            foreach ($movedIn as $entry) {
                @rename($this->webroot . '/' . $entry, $this->repoDir . '/' . $entry);
            }
            foreach (array_reverse($movedOut) as $entry) {
                @rename($this->oldDir . '/' . $entry, $this->webroot . '/' . $entry);
            }
            @rmdir($this->oldDir);

            throw new DeployException('Deployment rolled back: ' . $exception->getMessage(), 0, $exception);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->webroot . '/index.php', true);
        }
        if (function_exists('opcache_reset') && $this->config->getBool('opcache_reset_on_deploy')) {
            @opcache_reset();
        }
    }

    private function cleanupStagingOnly(): void
    {
        if ($this->stageDir !== '' && is_dir($this->stageDir)) {
            $this->rmrf($this->stageDir);
        }
    }

    /** @return list<string> */
    private function unknownEntries(): array
    {
        $managed = $this->managedEntries();
        $unknown = [];
        foreach ($this->entries($this->webroot) as $entry) {
            if (str_starts_with($entry, '.bs-stage-') || str_starts_with($entry, '.bs-old-')) {
                continue;
            }
            if (!in_array($entry, $managed, true)) {
                $unknown[] = $entry;
            }
        }

        return $unknown;
    }

    /** @return list<string> */
    private function managedEntries(): array
    {
        $managed = ['.htaccess', 'config.php', '.bootstrap-manifest.json'];
        $manifest = $this->webroot . '/.bootstrap-manifest.json';
        if (is_file($manifest)) {
            $data = json_decode((string) @file_get_contents($manifest), true);
            if (is_array($data) && isset($data['files']) && is_array($data['files'])) {
                foreach ($data['files'] as $file) {
                    if (!is_string($file) || $file === '') {
                        continue;
                    }
                    $top = explode('/', ltrim(str_replace('\\', '/', $file), '/'))[0];
                    if ($top !== '' && !in_array($top, $managed, true)) {
                        $managed[] = $top;
                    }
                }
            }
        }

        // The data directory may sit inside the web root on restricted hosts.
        $dataDir = $this->config->dataDir();
        if ($dataDir !== '' && Paths::isWithin($dataDir, $this->webroot)) {
            $managed[] = basename($dataDir);
        }

        return $managed;
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new DeployException('Unable to read ' . $directory);
        }

        return array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
    }

    private function gitVersion(): ?string
    {
        try {
            $result = Process::run([$this->config->getString('git_binary', 'git'), '--version'], [], null, 10);
            if ($result['exit'] === 0) {
                return trim($result['stdout']);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function gitBranch(): string
    {
        $configured = trim($this->config->getString('deploy.branch'));
        if ($configured !== '') {
            return $configured;
        }

        $this->steps[] = 'Used the repository default branch.';

        return 'default';
    }

    private function countFiles(string $directory): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $dir = $this->config->dataDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($dir . '/deploy.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            throw new DeployException('Another deployment is already running.');
        }

        return $handle;
    }

    private function assertRepoName(string $repo): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $repo) !== 1 || str_contains($repo, '..')) {
            throw new DeployException('Invalid repository name.');
        }
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DeployException('Unable to create ' . basename($directory));
        }
        @chmod($directory, 0700);
    }

    private function canRemoveDataDir(string $dataDir): bool
    {
        if ($dataDir === '' || $dataDir === '/' || $dataDir === $this->webroot) {
            return false;
        }
        if (Paths::isWithin($this->webroot, $dataDir)) {
            // dataDir is an ancestor of the web root - never delete it.
            return false;
        }
        if (Paths::isWithin($dataDir, $this->webroot)) {
            return true;
        }

        return str_starts_with(basename($dataDir), '.bootstrap-data');
    }

    private function sanitize(string $text): string
    {
        $text = str_replace([$this->stageDir, $this->oldDir, $this->webroot, $this->config->dataDir()], ['<stage>', '<old>', '<webroot>', '<data>'], $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim(substr($text, 0, 600));
    }

    private function rmrf(string $path): bool
    {
        if ($path === '' || $path === '/' || $path === $this->webroot) {
            return false;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }

        $ok = true;
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $ok = $this->rmrf($path . '/' . $entry) && $ok;
        }

        return @rmdir($path) && $ok;
    }
}
