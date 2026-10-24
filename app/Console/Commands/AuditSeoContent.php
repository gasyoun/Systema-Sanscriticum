<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seo\CourseContentAudit;
use Illuminate\Console\Command;

/**
 * Детектор дублей и пустых описаний на страницах `/k/` (H7094, 19-10-2026).
 *
 * Читает только БД и ничего не меняет. `--strict` даёт exit 1 при находках —
 * это и есть гейт для CI/расписания: список не должен отрастать заново.
 */
class AuditSeoContent extends Command
{
    protected $signature = 'catalog:audit-seo-content
        {--json : машинный вывод}
        {--strict : exit 1, если есть находки}';

    protected $description = 'Аудит описаний курсов: пустые, короткие, побайтово одинаковые';

    public function handle(CourseContentAudit $audit): int
    {
        $findings = $audit->findings();
        $hasFindings = $findings['empty'] !== []
            || $findings['short'] !== []
            || $findings['identical'] !== [];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->line(sprintf('Проверено курсов в sitemap-скоупе: %d', $findings['checked']));

            if ($findings['empty'] !== []) {
                $this->newLine();
                $this->warn(sprintf('Пустое описание: %d', count($findings['empty'])));
                $this->table(['Курс'], array_map(static fn (string $slug): array => [$slug], $findings['empty']));
            }

            if ($findings['short'] !== []) {
                $this->newLine();
                $this->warn(sprintf('Короче %d символов: %d', CourseContentAudit::SHORT_DESCRIPTION, count($findings['short'])));
                $this->table(['Курс'], array_map(static fn (string $slug): array => [$slug], $findings['short']));
            }

            if ($findings['identical'] !== []) {
                $this->newLine();
                $this->warn(sprintf('Побайтово одинаковые описания: %d групп', count($findings['identical'])));
                $this->table(
                    ['Группа', 'Курсы'],
                    array_map(
                        static fn (array $group, int $i): array => [$i + 1, implode(', ', $group)],
                        $findings['identical'],
                        array_keys($findings['identical']),
                    ),
                );
            }

            $this->newLine();
            $this->line(sprintf(
                'Итог: пустых %d, коротких %d, одинаковых групп %d.',
                count($findings['empty']),
                count($findings['short']),
                count($findings['identical']),
            ));
        }

        if ((bool) $this->option('strict') && $hasFindings) {
            $this->error('Находки есть — strict-гейт не пройден.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
