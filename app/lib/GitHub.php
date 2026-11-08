<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * Lists repositories with the GitHub REST API.
 *
 * SSH alone cannot enumerate repositories, so the API provides the catalogue
 * and the SSH key proves deploy access. Set `github_token` to include private
 * repositories (the token is never sent to the browser).
 */
final class GitHub
{
    private const CACHE_TTL = 60;

    private string $warning = '';

    public function __construct(private Config $config, private string $token = '')
    {
    }

    /**
     * The token from the login envelope wins; config.php is only a fallback
     * for local installs and tests.
     */
    private function token(): string
    {
        $token = trim($this->token);

        return $token !== '' ? $token : trim($this->config->getString('github_token'));
    }

    /**
     * @return array{ok: bool, repos: list<array<string, mixed>>, message: string, source: string, rate_limited: bool}
     */
    public function listRepos(bool $refresh = false): array
    {
        $token = $this->token();
        $cacheFile = $this->config->dataDir() . '/cache/repos-'
            . ($token === '' ? 'anon' : substr(hash('sha256', $token), 0, 12)) . '.json';
        $cached = $this->readCache($cacheFile);
        if (!$refresh && $cached !== null && (time() - (int) $cached['fetched_at']) < self::CACHE_TTL) {
            return [
                'ok' => true,
                'repos' => $cached['repos'],
                'message' => 'Cached listing.',
                'source' => 'cache',
                'rate_limited' => false,
            ];
        }

        try {
            $repos = $this->fetchAll();
            $this->writeCache($cacheFile, $repos);

            return [
                'ok' => true,
                'repos' => $this->sortRepos($repos),
                'message' => $this->warning,
                'source' => 'api',
                'rate_limited' => false,
            ];
        } catch (RateLimitException $exception) {
            if ($cached !== null) {
                return [
                    'ok' => true,
                    'repos' => $cached['repos'],
                    'message' => 'GitHub rate limit reached; showing the last cached list.',
                    'source' => 'cache-stale',
                    'rate_limited' => true,
                ];
            }

            return [
                'ok' => false,
                'repos' => [],
                'message' => $exception->getMessage(),
                'source' => 'none',
                'rate_limited' => true,
            ];
        } catch (\Throwable $exception) {
            if ($cached !== null) {
                return [
                    'ok' => true,
                    'repos' => $cached['repos'],
                    'message' => 'GitHub is unavailable; showing the last cached list.',
                    'source' => 'cache-stale',
                    'rate_limited' => false,
                ];
            }

            return [
                'ok' => false,
                'repos' => [],
                'message' => $exception->getMessage(),
                'source' => 'none',
                'rate_limited' => false,
            ];
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required on the frontend host.');
        }

        $owner = $this->config->githubOwner();
        $token = $this->token();

        // /users/{owner}/repos only ever returns public repositories, even with
        // a token. /user/repos is the authenticated listing and includes the
        // private ones, so try it first and filter to the configured owner.
        if ($token !== '') {
            $authenticated = $this->fetchForToken($owner);
            if ($authenticated !== []) {
                return $this->dedupe($authenticated);
            }
        }

        $type = $this->config->getString('github_owner_type', 'auto');
        $bases = match ($type) {
            'user' => ['/users/' . rawurlencode($owner) . '/repos'],
            'org' => ['/orgs/' . rawurlencode($owner) . '/repos'],
            default => [
                '/users/' . rawurlencode($owner) . '/repos',
                '/orgs/' . rawurlencode($owner) . '/repos',
            ],
        };

        $lastError = null;
        foreach ($bases as $base) {
            $page = 1;
            $collected = [];
            $worked = false;

            while ($page <= 5) {
                $url = $this->config->getString('github_api', 'https://api.github.com')
                    . $base . '?per_page=100&page=' . $page . '&sort=pushed&direction=desc';
                [$status, $body, $headers] = $this->request($url);

                if ($status === 404) {
                    break;
                }
                if ($status === 403 || $status === 429) {
                    $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
                    if (stripos($message, 'rate limit') !== false || ($headers['x-ratelimit-remaining'] ?? '') === '0') {
                        throw new RateLimitException(
                            'GitHub API rate limit reached. Add a github_token to config.php for a higher limit.'
                        );
                    }
                    throw new RuntimeException('GitHub API rejected the request (HTTP ' . $status . ').');
                }
                if ($status !== 200 || !is_array($body)) {
                    throw new RuntimeException('GitHub API request failed (HTTP ' . $status . ').');
                }

                $worked = true;
                foreach ($body as $repo) {
                    if (!is_array($repo) || !isset($repo['name'])) {
                        continue;
                    }
                    $collected[] = $this->normalise($repo);
                }

                if (count($body) < 100 || !isset($headers['link']) || !str_contains($headers['link'], 'rel="next"')) {
                    break;
                }
                $page++;
            }

            if ($worked) {
                return $this->dedupe($collected);
            }
            $lastError = 'No repositories found for ' . $owner . '.';
        }

        throw new RuntimeException($lastError ?? 'Unable to list repositories.');
    }

    /**
     * Repositories visible to the configured token, filtered to the owner.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchForToken(string $owner): array
    {
        $page = 1;
        $collected = [];
        $sawAny = false;

        while ($page <= 10) {
            $url = $this->config->getString('github_api', 'https://api.github.com')
                . '/user/repos?per_page=100&page=' . $page
                . '&sort=pushed&direction=desc'
                . '&affiliation=owner,collaborator,organization_member&visibility=all';
            [$status, $body, $headers] = $this->request($url);

            if ($status === 401) {
                $this->warning = 'github_token was rejected by GitHub; showing public repositories only.';

                return [];
            }
            if ($status === 403 || $status === 429) {
                $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
                if (stripos($message, 'rate limit') !== false || ($headers['x-ratelimit-remaining'] ?? '') === '0') {
                    throw new RateLimitException(
                        'GitHub API rate limit reached. Add a github_token to config.php for a higher limit.'
                    );
                }

                $this->warning = 'github_token could not list private repositories; showing public repositories only.';

                return [];
            }
            if ($status !== 200 || !is_array($body)) {
                return [];
            }

            foreach ($body as $repo) {
                if (!is_array($repo) || !isset($repo['name'])) {
                    continue;
                }
                $sawAny = true;
                if (strcasecmp((string) ($repo['owner']['login'] ?? ''), $owner) !== 0) {
                    continue;
                }
                $collected[] = $this->normalise($repo);
            }

            if (count($body) < 100 || !isset($headers['link']) || !str_contains($headers['link'], 'rel="next"')) {
                break;
            }
            $page++;
        }

        if ($collected === [] && $sawAny) {
            $this->warning = 'github_token cannot see repositories owned by ' . $owner
                . '; showing public repositories only.';
        }

        return $collected;
    }

    /** @param array<string, mixed> $repo @return array<string, mixed> */
    private function normalise(array $repo): array
    {
        return [
            'name' => (string) $repo['name'],
            'full_name' => (string) ($repo['full_name'] ?? ''),
            'description' => (string) ($repo['description'] ?? ''),
            'private' => (bool) ($repo['private'] ?? false),
            'archived' => (bool) ($repo['archived'] ?? false),
            'fork' => (bool) ($repo['fork'] ?? false),
            'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
            'html_url' => (string) ($repo['html_url'] ?? ''),
            'pushed_at' => (string) ($repo['pushed_at'] ?? ''),
            'language' => (string) ($repo['language'] ?? ''),
            'size' => (int) ($repo['size'] ?? 0),
            'clone_url' => 'git@github.com:' . ($repo['full_name'] ?? '') . '.git',
        ];
    }

    /** @param list<array<string, mixed>> $repos @return list<array<string, mixed>> */
    private function dedupe(array $repos): array
    {
        $seen = [];
        $result = [];
        foreach ($repos as $repo) {
            if (isset($seen[$repo['name']])) {
                continue;
            }
            $seen[$repo['name']] = true;
            $result[] = $repo;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $repos @return list<array<string, mixed>> */
    private function sortRepos(array $repos): array
    {
        usort($repos, static function (array $a, array $b): int {
            if ($a['fork'] !== $b['fork']) {
                return $a['fork'] ? 1 : -1;
            }

            return strcmp((string) $b['pushed_at'], (string) $a['pushed_at']);
        });

        return $repos;
    }

    /**
     * @return array{0: int, 1: mixed, 2: array<string, string>}
     */
    private function request(string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: bootstrap-frontend/' . BOOTSTRAP_VERSION,
        ];
        $token = $this->token();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ]);

        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('GitHub API request failed: ' . $error);
        }

        return [$status, json_decode((string) $body, true), $responseHeaders];
    }

    /** @return array{fetched_at: int, repos: list<array<string, mixed>>}|null */
    private function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['fetched_at'], $data['repos']) || !is_array($data['repos'])) {
            return null;
        }

        return ['fetched_at' => (int) $data['fetched_at'], 'repos' => $data['repos']];
    }

    /** @param list<array<string, mixed>> $repos */
    private function writeCache(string $file, array $repos): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents(
            $file,
            json_encode(['fetched_at' => time(), 'repos' => $repos], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($file, 0600);
    }
}
