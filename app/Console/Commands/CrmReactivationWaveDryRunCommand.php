<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReactivationWaveTemplate;
use App\Services\Crm\Reactivation\ReactivationWaveCensus;
use App\Services\Crm\Reactivation\ReactivationWaveCohort;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * H5288 — сухой прогон волны реактивации A.
 *
 *   php artisan crm:reactivation-wave-dry-run
 *   php artisan crm:reactivation-wave-dry-run --json
 *   php artisan crm:reactivation-wave-dry-run --write=storage/app/reports/reactivation_wave_a.md
 *
 * НИЧЕГО НЕ ОТПРАВЛЯЕТ и не пишет в базу: у команды намеренно НЕТ флага
 * `--apply` и ни одного вызова отправщика. Единственный побочный эффект —
 * файл отчёта на диске при `--write`.
 */
final class CrmReactivationWaveDryRunCommand extends Command
{
    protected $signature = 'crm:reactivation-wave-dry-run
                            {--json : Машиночитаемый JSON вместо таблиц}
                            {--write= : Путь для markdown-отчёта (относительный = от корня проекта)}
                            {--templates : Показать оба текста волны целиком}';

    protected $description = 'Волна реактивации A: сухой список адресатов, каналы и исключения (ничего не отправляет)';

    public function handle(ReactivationWaveCohort $cohort): int
    {
        $census = $cohort->evaluate();
        $payload = $census->toArray();

        $path = $this->option('write');
        if (is_string($path) && $path !== '') {
            $written = $this->writeReport($census, $path);
            $payload['report'] = $written;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Сухой прогон — отправлено сообщений: 0');
        $this->line('кандидатов: '.$payload['candidates'].' · в списке: '.$payload['send_list'].' · исключено: '.$payload['excluded']);

        $this->newLine();
        $this->line('<comment>Каналы</comment>');
        $this->table(['канал', 'адресатов'], $this->rows($census->channelCounts()));

        $this->line('<comment>Сегменты</comment>');
        $this->table(['сегмент', 'адресатов'], $this->rows($census->segmentCounts()));

        $this->line('<comment>Исключения</comment>');
        $this->table(['причина', 'карточек'], $this->rows($census->exclusionCounts()));

        if ($this->option('templates')) {
            foreach (ReactivationWaveTemplate::cases() as $template) {
                $this->newLine();
                $this->line('<comment>'.$template->value.' — '.$template->label().'</comment>');
                $this->line($template->subject());
                $this->newLine();
                $this->line($template->body());
            }
        }

        if (isset($payload['report'])) {
            $this->newLine();
            $this->info('Отчёт: '.$payload['report']);
        }

        return self::SUCCESS;
    }

    /** Markdown-отчёт: тот же счёт, что в JSON, плюс обе копии текстов. */
    public function writeReport(ReactivationWaveCensus $census, string $path): string
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $this->renderReport($census));

        return $absolute;
    }

    public function renderReport(ReactivationWaveCensus $census): string
    {
        $today = Carbon::now()->format('d-m-Y');
        $lines = [];
        $lines[] = '_Created: '.$today.' · Last updated: '.$today.'_';
        $lines[] = '';
        $lines[] = '# Волна реактивации A — сухой прогон (отправлено 0)';
        $lines[] = '';
        $lines[] = 'Сгенерировано `php artisan crm:reactivation-wave-dry-run --write=…` (H5288), '.$census->generatedAt.'.';
        $lines[] = 'Команда только читает: отправок нет, в базу не пишет.';
        $lines[] = '';
        $lines[] = '## Счёт';
        $lines[] = '';
        $lines[] = '| Показатель | Значение |';
        $lines[] = '|---|---:|';
        $lines[] = '| Кандидатов (есть оплаченная история) | '.$census->candidateCount.' |';
        $lines[] = '| В списке рассылки | '.count($census->sendList).' |';
        $lines[] = '| Исключено | '.count($census->excluded).' |';
        $lines[] = '| **Отправлено сообщений** | **0** |';
        $lines[] = '';
        $lines[] = '## Каналы';
        $lines[] = '';
        $lines[] = $this->mdTable(['Канал', 'Адресатов'], $census->channelCounts());
        $lines[] = '## Сегменты и шаблоны';
        $lines[] = '';
        $lines[] = $this->mdTable(['Сегмент', 'Адресатов'], $census->segmentCounts());
        $lines[] = $this->mdTable(['Шаблон', 'Адресатов'], $census->templateCounts());
        $lines[] = '## Исключения';
        $lines[] = '';
        $lines[] = $this->mdTable(['Причина', 'Карточек'], $census->exclusionCounts());
        $lines[] = '## Тексты волны';
        $lines[] = '';
        foreach (ReactivationWaveTemplate::cases() as $template) {
            $lines[] = '### `'.$template->value.'` — '.$template->label();
            $lines[] = '';
            $lines[] = '**Тема:** '.$template->subject();
            $lines[] = '';
            $lines[] = '```text';
            $lines[] = $template->body();
            $lines[] = '```';
            $lines[] = '';
        }
        $lines[] = '_Гасунс_';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, int>  $counts
     */
    private function mdTable(array $headers, array $counts): string
    {
        $out = ['| '.$headers[0].' | '.$headers[1].' |', '|---|---:|'];
        if ($counts === []) {
            $out[] = '| — | 0 |';
        }
        foreach ($counts as $key => $value) {
            $out[] = '| `'.$key.'` | '.$value.' |';
        }
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function rows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $value) {
            $rows[] = [(string) $key, $value];
        }

        return $rows === [] ? [['—', 0]] : $rows;
    }
}
