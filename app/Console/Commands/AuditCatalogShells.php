<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CatalogShellAudit;
use Illuminate\Console\Command;

/**
 * Отчёт о пустых курсах и группах-оболочках (H3122).
 *
 * НИЧЕГО НЕ УДАЛЯЕТ. Рулинг MG 19-08-2026: «сперва аудит» — и, поверх него,
 * «главное, чтобы не потерялись никакие записи ни при каких обстоятельствах».
 * Удаление, когда до него дойдёт дело, будет отдельной командой, читающей этот
 * же вердикт.
 */
class AuditCatalogShells extends Command
{
    protected $signature = 'catalog:audit-shells {--json : Машиночитаемый вывод}';

    protected $description = 'Найти пустые курсы и группы-оболочки (кандидаты на удаление). Только отчёт, ничего не удаляет.';

    public function handle(CatalogShellAudit $audit): int
    {
        $report = $audit->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->section('Курсы-оболочки', $report['courses'], function (array $row): array {
            return [
                $row['id'],
                mb_substr((string) $row['title'], 0, 40),
                $row['format'],
                $row['visible'] ? 'да' : 'нет',
                $row['enrolled'],
                $row['safe'] ? '✅ можно удалять' : '⛔ '.implode('; ', $row['blockers']),
            ];
        }, ['id', 'Курс', 'Формат', 'Виден', 'Записано', 'Вердикт']);

        $this->section('Пустые группы', $report['groups'], function (array $row): array {
            return [
                $row['id'],
                mb_substr((string) $row['name'], 0, 46),
                $row['status'],
                $row['safe'] ? '✅ можно удалять' : '⛔ '.implode('; ', $row['blockers']),
            ];
        }, ['id', 'Группа', 'Статус', 'Вердикт']);

        $safeCourses = count(array_filter($report['courses'], fn ($r) => $r['safe']));
        $safeGroups = count(array_filter($report['groups'], fn ($r) => $r['safe']));

        $this->newLine();
        $this->info(sprintf(
            'Итого: курсов-оболочек %d (безопасных %d), пустых групп %d (безопасных %d). Ничего не удалено.',
            count($report['courses']),
            $safeCourses,
            count($report['groups']),
            $safeGroups,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function section(string $title, array $rows, callable $map, array $headers): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");

        if ($rows === []) {
            $this->line('  — нет');

            return;
        }

        $this->table($headers, array_map($map, $rows));
    }
}
