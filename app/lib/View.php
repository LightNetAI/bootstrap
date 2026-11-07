<?php
declare(strict_types=1);

namespace Bootstrap;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data): void
    {
        $file = Paths::app() . '/views/' . $template . '.php';
        if (!is_file($file)) {
            Http::jsonError('View not found.', 500, 'view_missing');
        }

        extract($data, EXTR_SKIP);
        $csrf = Session::csrfToken();
        $version = BOOTSTRAP_VERSION;
        $templateFile = $file;

        require Paths::app() . '/views/layout.php';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
