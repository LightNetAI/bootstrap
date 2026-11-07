<?php
declare(strict_types=1);

/**
 * Bootstrap frontend configuration.
 *
 * install.sh runs bin/configure.php, which writes config.php with these values
 * plus the absolute backend URL, data directory and install timestamp. Edit
 * config.php afterwards if you need to change something.
 */
return [
    // Hard-coded BootStrapServer endpoint. Must be https in production.
    'backend_url' => 'https://bootstrap.dark.net.au',
    'allow_insecure_backend' => false,

    // Written by the installer. Must be writable by PHP and inside open_basedir.
    'data_dir' => '',
    'install_id' => '',
    'installed_at' => '',

    // GitHub account that owns the projects.
    'github_owner' => 'LightNetAI',
    'github_owner_type' => 'auto',       // auto | user | org
    'github_api' => 'https://api.github.com',
    'github_token' => '',                // optional: list private repositories
    'git_base' => 'git@github.com:LightNetAI',
    'git_binary' => 'git',

    // Pinned github.com host keys (from https://api.github.com/meta).
    // expected_host_fingerprints makes a tampered config.php fail closed.
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

    'session_ttl' => 1800,       // seconds before the decrypted key is dropped
    'bind_session_to_ua' => true,
    'bind_session_to_ip' => false,

    'deploy' => [
        'branch' => '',                 // empty = repository default branch
        'timeout' => 300,
        'composer_install' => false,
        'composer_binary' => 'composer',
        'keep_git' => false,
        'require_index' => true,
        'opcache_reset_on_deploy' => false,
    ],
];
