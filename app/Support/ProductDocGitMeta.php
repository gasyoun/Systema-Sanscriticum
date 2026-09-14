<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Дата последнего коммита файла каталога. Только для супер-админа на странице.
 * Аргументы Process массивом, не shell. Нет git — null, не 500.
 */
final class ProductDocGitMeta
{
    public static function lastCommitDate(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $result = Process::path(base_path())
            ->timeout(5)
            ->run(['git', 'log', '-1', '--format=%cs', '--', $relative]);

        if (! $result->successful()) {
            return null;
        }

        $date = trim($result->output());

        return $date === '' ? null : $date;
    }
}
