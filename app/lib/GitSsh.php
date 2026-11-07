<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * Prepares an isolated SSH identity for git operations.
 *
 * The private key is written to a 0600 file inside the throw-away staging
 * directory and deleted with it. github.com host keys are pinned, so a DNS or
 * proxy attack cannot silently capture the key.
 */
final class GitSsh
{
    private string $identityPath;
    private string $knownHostsPath;

    public function __construct(
        private Config $config,
        private string $privateKey,
        private string $workDir,
    ) {
        $this->identityPath = $workDir . '/.ssh/id_bootstrap';
        $this->knownHostsPath = $workDir . '/.ssh/known_hosts';
    }

    public function prepare(): void
    {
        $sshDir = $this->workDir . '/.ssh';
        $this->ensureDir($sshDir, 0700);
        $this->ensureDir($this->workDir . '/home', 0700);
        $this->ensureDir($this->workDir . '/tmp', 0700);

        $this->writeFile($this->identityPath, rtrim($this->privateKey, "\n") . "\n");
        $this->writeFile($this->knownHostsPath, $this->buildKnownHosts());
    }

    /** @return array<string, string> */
    public function env(): array
    {
        $path = getenv('PATH');

        return [
            'PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->workDir . '/home',
            'TMPDIR' => $this->workDir . '/tmp',
            'GIT_SSH_COMMAND' => $this->sshCommand(),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/true',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->workDir . '/home/.gitconfig',
            'GIT_AUTHOR_NAME' => 'bootstrap',
            'GIT_AUTHOR_EMAIL' => 'bootstrap@localhost',
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ];
    }

    public function sshCommand(): string
    {
        $arguments = [
            'ssh',
            // Ignore the host ssh_config (deterministic behaviour on panels with odd perms).
            '-F', '/dev/null',
            '-i', $this->identityPath,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $this->knownHostsPath,
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'PasswordAuthentication=no',
            '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'PreferredAuthentications=publickey',
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=3',
        ];

        return implode(' ', array_map('escapeshellarg', $arguments));
    }

    public function cloneUrl(string $repo): string
    {
        return $this->config->gitBase() . '/' . $repo . '.git';
    }

    /**
     * @return array{ok: bool, account: string, message: string}
     */
    public function testConnection(): array
    {
        $command = $this->sshCommand() . ' -T ' . escapeshellarg('git@github.com');
        // ssh exits 1 for a successful authentication against GitHub.
        $result = Process::runShell($command, $this->env(), $this->workDir, 20);
        $output = $result['stdout'] . $result['stderr'];

        if (preg_match('~Hi ([A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?)!~', $output, $match) === 1) {
            return ['ok' => true, 'account' => $match[1], 'message' => 'Authenticated with GitHub.'];
        }

        $reason = trim((string) preg_replace('/\s+/', ' ', $output));
        if (str_contains($output, 'Permission denied (publickey)')) {
            $reason = 'GitHub rejected the key. Add the public key to the repository account (or as a deploy key).';
        } elseif (str_contains($output, 'Host key verification failed')) {
            $reason = 'GitHub host key verification failed; check known_hosts in config.php.';
        } elseif ($reason === '') {
            $reason = 'SSH connection to GitHub failed.';
        }

        return ['ok' => false, 'account' => '', 'message' => $reason];
    }

    public function identityPath(): string
    {
        return $this->identityPath;
    }

    private function buildKnownHosts(): string
    {
        $lines = $this->config->getList('known_hosts');
        if ($lines === []) {
            throw new RuntimeException('known_hosts is empty in config.php; refusing to connect without host key pinning.');
        }

        $expected = $this->config->get('expected_host_fingerprints');
        $expected = is_array($expected) ? $expected : [];
        $accepted = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 3) {
                throw new RuntimeException('Malformed known_hosts entry in config.php.');
            }
            $host = $parts[0];
            $fingerprint = Crypto::fingerprint($parts[0] . ' ' . $parts[2]);
            $allowedForHost = $expected[$host] ?? [];
            if (is_array($allowedForHost) && $allowedForHost !== [] && !in_array($fingerprint, $allowedForHost, true)) {
                throw new RuntimeException(sprintf(
                    'Host key for %s has fingerprint %s which is not in expected_host_fingerprints.',
                    $host,
                    $fingerprint
                ));
            }
            $accepted[] = trim($line);
        }

        return implode("\n", $accepted) . "\n";
    }

    private function ensureDir(string $dir, int $mode): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create ' . $dir);
        }
        @chmod($dir, $mode);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write ' . basename($path));
        }
        @chmod($path, 0600);
    }
}
