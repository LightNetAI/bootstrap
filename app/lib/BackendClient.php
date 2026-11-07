<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * HTTPS client for BootStrapServer. The backend URL comes from config.php and
 * is never user supplied, so credentials cannot be redirected elsewhere.
 */
final class BackendClient
{
    public function __construct(private Config $config)
    {
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>, error: string, code: string, retry_after: int} */
    public function login(string $username, string $password): array
    {
        return $this->post('/api/v1/session', [
            'username' => $username,
            'password' => $password,
            'client' => 'bootstrap-frontend/' . BOOTSTRAP_VERSION,
        ]);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>, error: string} */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string, code: string, retry_after: int}
     */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string, code: string, retry_after: int}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required on the frontend host.');
        }

        $url = $this->config->backendUrl() . $path;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !$this->config->getBool('allow_insecure_backend')) {
            return $this->failure(0, 'backend_url must be https.', 'insecure_backend');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
        ];

        $protocols = CURLPROTO_HTTPS;
        if ($this->config->getBool('allow_insecure_backend')) {
            $protocols |= CURLPROTO_HTTP;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'bootstrap-frontend/' . BOOTSTRAP_VERSION,
            CURLOPT_ENCODING => '',
        ];

        $caBundle = $this->config->getString('ca_bundle');
        if ($caBundle !== '' && is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        if ($method === 'POST') {
            $body = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                return $this->failure(0, 'Unable to encode the request.', 'encoding_error');
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false || $errno !== 0) {
            return $this->failure(0, $this->describeCurlError($errno, $error), 'network_error');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $message = 'BootStrapServer returned an invalid response.';
            if ($status >= 400) {
                $message = sprintf(
                    'BootStrapServer answered HTTP %d with a non-JSON response. The backend host is not executing Bootstrap: check its document root (public/), the PHP handler and that .htaccess is readable by the web user.',
                    $status
                );
            }

            return $this->failure($status, $message, 'invalid_response');
        }

        if ($status === 200 && ($decoded['ok'] ?? false) === true) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => $decoded,
                'error' => '',
                'code' => '',
                'retry_after' => 0,
            ];
        }

        $errorBlock = $decoded['error'] ?? [];
        $message = is_array($errorBlock) ? (string) ($errorBlock['message'] ?? 'Request failed.') : 'Request failed.';
        $code = is_array($errorBlock) ? (string) ($errorBlock['code'] ?? 'error') : 'error';
        $retryAfter = is_array($errorBlock) && isset($errorBlock['retry_after']) ? (int) $errorBlock['retry_after'] : 0;

        return $this->failure($status, $message, $code, $retryAfter);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>, error: string, code: string, retry_after: int} */
    private function failure(int $status, string $message, string $code, int $retryAfter = 0): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'data' => [],
            'error' => $message,
            'code' => $code,
            'retry_after' => $retryAfter,
        ];
    }

    private function describeCurlError(int $errno, string $error): string
    {
        $hint = match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'The backend host could not be resolved.',
            CURLE_COULDNT_CONNECT => 'The backend refused the connection.',
            CURLE_OPERATION_TIMEDOUT => 'The backend did not answer in time.',
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_PEER_FAILED_VERIFICATION => 'TLS verification failed for the backend.',
            default => 'Could not reach the backend.',
        };

        return $hint . ($this->config->isDebug() ? ' (' . $error . ')' : '');
    }
}
