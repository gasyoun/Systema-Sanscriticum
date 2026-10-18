<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SurveyEvent;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Support\FormulaGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Скелет табуляции результатов анкеты (H5824): читает survey_responses
 * одной волны и раскладывает ответы в отчёт для человека.
 *
 * На выходе storage/app/survey-tabulation/{slug}-{Ymd-Hi}/:
 *   report.md      — закрытые вопросы (radio/checkboxes/scale) таблицами,
 *                    сверху сводка воронки (приглашено / открыто / отвечено);
 *   free_text.csv  — свободные ответы (text/textarea) построчно, BOM + «;»
 *                    (тот же формат, что survey:audience — открывается в Excel);
 *   tabulation.json — то же самое машиной (только с --json).
 *
 * Команда только читает; ничего не отправляет и не меняет.
 *
 *   php artisan survey:tabulate churn-2026-10
 *   php artisan survey:tabulate churn-2026-10 --json
 */
class SurveyTabulateCommand extends Command
{
    protected $signature = 'survey:tabulate
        {slug : Ключ волны из config/surveys.php}
        {--json : Дополнительно записать tabulation.json}';

    protected $description = 'Табуляция ответов анкеты: отчёт + CSV свободных ответов (+ JSON по флагу)';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $definition = config("surveys.definitions.$slug");

        if (! is_array($definition)) {
            $this->error("Неизвестная волна опроса: {$slug}");

            return self::FAILURE;
        }

        $responses = SurveyResponse::query()
            ->where('survey_slug', $slug)
            ->orderBy('id')
            ->get();

        if ($responses->isEmpty()) {
            $this->warn('Ответов ещё нет — отчёт не создан.');

            return self::SUCCESS;
        }

        $questions = collect($definition['questions'] ?? []);
        $byId = $questions->keyBy('id');

        $closed = $this->tabulateClosed($responses, $questions);
        $freeText = $this->collectFreeText($responses, $byId);
        $funnel = $this->funnel($slug);

        $dir = storage_path('app/survey-tabulation/'.$slug.'-'.now()->format('Ymd-Hi'));
        @mkdir($dir, 0775, true);

        $this->writeReport($dir.'/report.md', $slug, $definition, $responses->count(), $closed, $funnel);
        $this->writeFreeTextCsv($dir.'/free_text.csv', $freeText);

        if ((bool) $this->option('json')) {
            file_put_contents(
                $dir.'/tabulation.json',
                json_encode([
                    'slug' => $slug,
                    'generated_at' => now()->toIso8601String(),
                    'responses' => $responses->count(),
                    'funnel' => $funnel,
                    'closed' => $closed,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        $this->info("Ответов: {$responses->count()}.");
        foreach ($closed as $row) {
            $this->line("  {$row['question_id']}: ответили {$row['answered']}".($row['other'] > 0 ? ", вне списка вариантов {$row['other']}" : ''));
        }
        $this->info($dir);

        return self::SUCCESS;
    }

    /** Распределения по закрытым вопросам (radio/checkboxes/scale). */
    private function tabulateClosed(Collection $responses, Collection $questions): array
    {
        $closed = [];

        foreach ($questions as $question) {
            $type = (string) ($question['type'] ?? 'radio');

            if (in_array($type, ['text', 'textarea'], true)) {
                continue;
            }

            $answers = $responses
                ->map(fn (SurveyResponse $r) => $r->answers[$question['id']] ?? null)
                ->filter(fn ($v) => $v !== null && $v !== '' && $v !== []);

            $row = [
                'question_id' => (string) $question['id'],
                'label' => (string) $question['label'],
                'type' => $type,
                'answered' => $answers->count(),
                'other' => 0,
            ];

            if ($type === 'checkboxes') {
                $options = array_fill_keys((array) ($question['options'] ?? []), 0);
                foreach ($answers as $chosen) {
                    foreach ((array) $chosen as $choice) {
                        if (array_key_exists($choice, $options)) {
                            $options[$choice]++;
                        } else {
                            $row['other']++;
                        }
                    }
                }
                $row['distribution'] = $options;
            } elseif ($type === 'scale') {
                $values = $answers->map(fn ($v) => (int) $v)->sort()->values();
                $row['distribution'] = $values->countBy()->sortKeys()->all();
                $row['min'] = $values->min();
                $row['max'] = $values->max();
                $row['mean'] = round((float) $values->avg(), 2);
            } else {
                $options = array_fill_keys((array) ($question['options'] ?? []), 0);
                foreach ($answers as $choice) {
                    if (array_key_exists($choice, $options)) {
                        $options[$choice]++;
                    } else {
                        $row['other']++;
                    }
                }
                $row['distribution'] = $options;
            }

            $closed[] = $row;
        }

        return $closed;
    }

    /** Свободные ответы одной волны построчно. */
    private function collectFreeText(Collection $responses, Collection $byId): array
    {
        $rows = [];

        foreach ($responses as $response) {
            foreach ((array) ($response->answers ?? []) as $questionId => $answer) {
                $question = $byId->get($questionId);
                $type = (string) ($question['type'] ?? '');

                if (! in_array($type, ['text', 'textarea'], true)) {
                    continue;
                }

                $text = trim((string) $answer);
                if ($text === '') {
                    continue;
                }

                $rows[] = [
                    'response_id' => $response->id,
                    'created_at' => optional($response->created_at)->toDateTimeString() ?? '',
                    'question_id' => $questionId,
                    'label' => (string) ($question['label'] ?? $questionId),
                    'answer' => $text,
                ];
            }
        }

        return $rows;
    }

    /** Сводка воронки волны: приглашено / открыто / начато / отвечено. */
    private function funnel(string $slug): array
    {
        $invited = SurveyInvitation::query()->where('survey_slug', $slug)->count();

        $events = SurveyEvent::query()
            ->where('survey_slug', $slug)
            ->get(['event']);

        $byEvent = $events->countBy('event');

        return [
            'invited' => $invited,
            'opened' => (int) ($byEvent['opened'] ?? 0),
            'started' => (int) ($byEvent['started'] ?? 0),
            'completed' => SurveyResponse::query()->where('survey_slug', $slug)->count(),
        ];
    }

    private function writeReport(string $path, string $slug, array $definition, int $count, array $closed, array $funnel): void
    {
        $lines = [
            '# Табуляция анкеты «'.(string) $definition['title'].'»',
            '',
            '- Волна: `'.$slug.'`',
            '- Сгенерировано: '.now()->format('Y-m-d H:i'),
            '- Ответов: '.$count,
            '- Воронка: приглашено '.$funnel['invited'].' · открыто '.$funnel['opened'].' · начато '.$funnel['started'].' · отвечено '.$funnel['completed'],
            '',
        ];

        foreach ($closed as $row) {
            $lines[] = '## '.$row['question_id'].' — '.$row['label'];
            $lines[] = '';
            $lines[] = 'Ответили: '.$row['answered'].'.'.($row['other'] > 0 ? ' Вне списка вариантов: '.$row['other'].'.' : '');
            $lines[] = '';
            $lines[] = '| Вариант | Ответов |';
            $lines[] = '| --- | ---: |';

            foreach ($row['distribution'] as $option => $n) {
                $lines[] = '| '.$option.' | '.$n.' |';
            }

            if ($row['type'] === 'scale') {
                $lines[] = '| min / среднее / max | '.$row['min'].' / '.$row['mean'].' / '.$row['max'].' |';
            }

            $lines[] = '';
        }

        $lines[] = 'Свободные ответы: `free_text.csv` рядом с отчётом.';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
    }

    private function writeFreeTextCsv(string $path, array $rows): void
    {
        $out = fopen($path, 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['response_id', 'created_at', 'question_id', 'label', 'answer'], ';');
        // H5086: ответы анкеты — гостевые строки, нейтрализуем формулы
        // (тот же guard, что в SurveyPageController@exportCsv).
        foreach ($rows as $row) {
            fputcsv($out, FormulaGuard::row($row), ';');
        }
        fclose($out);
    }
}
