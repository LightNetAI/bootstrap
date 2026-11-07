<?php
declare(strict_types=1);

/**
 * Tiny dependency-free test harness (shared hosting rarely has PHPUnit).
 */

final class AssertionFailure extends RuntimeException
{
}

final class TestSuite
{
    public static int $passed = 0;
    public static int $failed = 0;

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$passed++;
            fwrite(STDOUT, "  ok   {$name}\n");
        } catch (AssertionFailure $exception) {
            self::$failed++;
            fwrite(STDERR, "  FAIL {$name}: {$exception->getMessage()}\n");
        } catch (Throwable $exception) {
            self::$failed++;
            fwrite(STDERR, '  FAIL ' . $name . ': ' . $exception::class . ': ' . $exception->getMessage() . "\n");
        }
    }

    public static function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        if (!$condition) {
            self::fail($message);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            self::fail(($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            self::fail(($message !== '' ? $message . ': ' : '') . 'missing "' . $needle . '"');
        }
    }

    public static function expectException(callable $fn, string $class, string $message = ''): void
    {
        try {
            $fn();
        } catch (Throwable $exception) {
            if (!$exception instanceof $class) {
                self::fail('expected ' . $class . ', got ' . $exception::class);
            }

            return;
        }
        self::fail('expected ' . $class . ' to be thrown' . ($message !== '' ? ' (' . $message . ')' : ''));
    }

    public static function fail(string $message): never
    {
        throw new AssertionFailure($message);
    }

    public static function summary(string $suite): int
    {
        fwrite(STDOUT, "\n{$suite}: " . self::$passed . ' passed, ' . self::$failed . " failed\n");

        return self::$failed === 0 ? 0 : 1;
    }
}

function tempDir(string $prefix): string
{
    $base = __DIR__ . '/tmp/' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create ' . $base);
    }

    return $base;
}

function rmTree(string $path): void
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
        rmTree($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * @return array{0: int, 1: string, 2: list<string>}
 */
function httpCall(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $headerLines = $headers;
    if ($body !== null) {
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return [$status, (string) $response, $responseHeaders];
}
