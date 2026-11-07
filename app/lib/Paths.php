<?php
declare(strict_types=1);

namespace Bootstrap;

final class Paths
{
    /**
     * Runtime application root, which is also the web root: the installer
     * copies app/* into the document root, so index.php sits next to lib/.
     */
    public static function app(): string
    {
        return dirname(__DIR__);
    }

    /** The document root the installer wrote into. */
    public static function webroot(): string
    {
        return self::app();
    }

    public static function configFile(): string
    {
        return self::app() . '/config.php';
    }

    public static function isWithin(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = rtrim(self::normalize($root), '/');

        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    public static function normalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, '/');
        }

        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1;
        $parts = [];
        foreach (preg_split('~[\\\\/]+~', $path) ?: [] as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return ($absolute ? '/' : '') . implode('/', $parts);
    }

    public static function allowedByOpenBaseDir(string $path): bool
    {
        $openBasedir = trim((string) ini_get('open_basedir'));
        if ($openBasedir === '') {
            return true;
        }

        $candidate = self::normalize($path);
        foreach (preg_split('~[:;]~', $openBasedir) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $entry = rtrim(self::normalize($entry), '/');
            if ($candidate === $entry || str_starts_with($candidate . '/', $entry . '/')) {
                return true;
            }
        }

        return false;
    }
}
