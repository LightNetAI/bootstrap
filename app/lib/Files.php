<?php
declare(strict_types=1);

namespace Bootstrap;

final class Files
{
    public static function ensureDir(string $dir, int $mode = 0700): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
        @chmod($dir, $mode);
    }

    public static function rmrf(string $path): bool
    {
        if ($path === '' || $path === '/') {
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
            $ok = self::rmrf($path . '/' . $entry) && $ok;
        }

        return @rmdir($path) && $ok;
    }
}
