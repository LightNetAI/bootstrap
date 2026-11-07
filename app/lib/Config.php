<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(?string $file = null): self
    {
        $file ??= Paths::configFile();
        if (!is_file($file)) {
            throw new RuntimeException('config.php is missing. Re-run the installer.');
        }

        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new RuntimeException('config.php must return an array.');
        }

        $config = new self(self::merge(self::defaults(), $loaded));
        $config->validate();

        return $config;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'backend_url' => 'https://bootstrap.dark.net.au',
            'allow_insecure_backend' => false,
            'data_dir' => '',
            'install_id' => '',
            'installed_at' => '',

            'github_owner' => 'LightNetAI',
            'github_owner_type' => 'auto', // auto | user | org
            'github_api' => 'https://api.github.com',
            'github_token' => '',          // optional: enables private repo listing
            'git_base' => 'git@github.com:LightNetAI',
            'git_binary' => 'git',
            'known_hosts' => [
                'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl',
                'github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=',
                'github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk=',
            ],
            'expected_host_fingerprints' => [
                'github.com' => [
                    'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU',
                    'SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM',
                    'SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s',
                ],
            ],

            'session_ttl' => 1800,
            'bind_session_to_ua' => true,
            'bind_session_to_ip' => false,

            'deploy' => [
                'branch' => '',
                'timeout' => 300,
                'composer_install' => false,
                'composer_binary' => 'composer',
                'keep_git' => false,
                'require_index' => true,
                'opcache_reset_on_deploy' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private function validate(): void
    {
        $backend = $this->backendUrl();
        $scheme = strtolower((string) parse_url($backend, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !$this->getBool('allow_insecure_backend')) {
            throw new RuntimeException('backend_url must use https (or set allow_insecure_backend for local tests).');
        }
        if (parse_url($backend, PHP_URL_HOST) === null) {
            throw new RuntimeException('backend_url is not a valid URL.');
        }
        if ($this->dataDir() === '') {
            throw new RuntimeException('data_dir is not configured. Re-run the installer.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /** @return list<string> */
    public function getList(string $key): array
    {
        $value = $this->get($key, []);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }

    public function backendUrl(): string
    {
        return rtrim($this->getString('backend_url'), '/');
    }

    public function dataDir(): string
    {
        return rtrim($this->getString('data_dir'), '/');
    }

    public function webroot(): string
    {
        return Paths::webroot();
    }

    public function githubOwner(): string
    {
        return $this->getString('github_owner', 'LightNetAI');
    }

    public function gitBase(): string
    {
        return rtrim($this->getString('git_base', 'git@github.com:LightNetAI'), '/');
    }

    public function sessionTtl(): int
    {
        return max(300, $this->getInt('session_ttl', 1800));
    }

    public function deployOption(string $key, mixed $default = null): mixed
    {
        return $this->get('deploy.' . $key, $default);
    }

    public function isDebug(): bool
    {
        return $this->getBool('debug');
    }
}
