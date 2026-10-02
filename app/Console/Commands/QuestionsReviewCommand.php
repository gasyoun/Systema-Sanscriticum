<?php

namespace App\Console\Commands;

use App\Models\SupportQuestionClassification;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H5709 — стратифицированное ревью точности primary-топика.
 *
 * Работает ЦЕЛИКОМ внутри доверенного окружения: лист ревью (id + текст +
 * предсказание) пишется в защищённый storage/app, наружу (stdout/отчёты/
 * git) уходят ТОЛЬКО агрегаты — точность, покрытие, счётчики расхождений.
 *
 * --sheet: пропорциональная стратификация по (популяция × primary-категория
 * ∪ unclassified), детерминированный порядок по id, N строк.
 * --import: принимает заполненный колонкой gold_label лист (A..I |
 * unclassified | not_question | other) и печатает вердикт гейта ≥93 %.
 * Если подходящих сообщений меньше N — gate_inconclusive, наблюдения не
 * фабрикуются.
 */
class QuestionsReviewCommand extends Command
{
    protected $signature = 'support:questions-review
        {--week= : ISO-дата понедельника недели (дефолт — последний снапшот)}
        {--sample=100 : размер стратифицированной выборки}
        {--sheet= : путь листа ревью (дефолт storage/app/support-questions/review-<week>.tsv)}
        {--import= : путь заполненного листа с колонкой gold_label — печатает только агрегаты}';

    protected $description = 'Стратифицированное ревью точности классификации недельных вопросов (только агрегаты наружу).';

    private const GOLD_LABELS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'unclassified', 'not_question', 'other'];

    public function handle(): int
    {
        $import = (string) ($this->option('import') ?? '');
        if ($import !== '') {
            return $this->importSheet($import);
        }

        return $this->writeSheet();
    }

    private function weekStart(): string
    {
        $week = (string) ($this->option('week') ?? '');
        if ($week !== '') {
            return CarbonImmutable::parse($week, 'Europe/Moscow')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        }

        $latest = DB::table('support_question_weekly_snapshots')
            ->orderByDesc('week_start')
            ->value('week_start');

        if ($latest === null) {
            throw new \RuntimeException('No snapshots yet — run support:questions-weekly first or pass --week.');
        }

        return CarbonImmutable::parse((string) $latest)->toDateString();
    }

    private function writeSheet(): int
    {
        $week = $this->weekStart();
        $sample = max(1, (int) $this->option('sample'));
        $path = (string) ($this->option('sheet') ?? '')
            ?: storage_path('app/support-questions/review-'.$week.'.tsv');

        $eligible = SupportQuestionClassification::query()
            ->where('classifier_version', QuestionMessageClassifier::VERSION)
            ->where('is_question', true)
            ->whereNull('exclusion_reason')
            ->whereIn('population', ['student', 'enquiry'])
            ->whereHas('message', fn ($m) => $m
                ->where('direction', 'incoming')
                ->where('sent_at', '>=', $this->localStart($week))
                ->where('sent_at', '<', $this->localStart(CarbonImmutable::parse($week)->addDays(7)->toDateString())))
            ->join('telegram_support_messages', 'telegram_support_messages.id', '=', 'support_question_classifications.telegram_support_message_id')
            ->get(['support_question_classifications.*', 'telegram_support_messages.text']);

        if ($eligible->count() < $sample) {
            $this->line(json_encode([
                'gate' => 'inconclusive',
                'week' => $week,
                'eligible' => $eligible->count(),
                'required' => $sample,
                'note' => 'fewer eligible messages than the requested sample; observations are not manufactured',
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Страты: популяция × primary (∪ unclassified); пропорционально,
        // минимум одна из непустых, добор по убыванию размера до N.
        $strata = [];
        foreach ($eligible as $row) {
            $key = $row->population.'|'.($row->primary_category ?? 'unclassified');
            $strata[$key][] = $row;
        }
        uksort($strata, static fn (string $a, string $b): int => strcmp($a, $b));
        $picked = [];
        $remaining = $sample;
        $totalEligible = $eligible->count();
        foreach ($strata as $key => $rows) {
            $take = (int) floor(count($rows) / $totalEligible * $sample);
            $take = min($take, $remaining, count($rows));
            foreach (array_slice($rows, 0, $take) as $row) {
                $picked[] = $row;
            }
            $remaining -= $take;
            if ($remaining <= 0) {
                break;
            }
        }
        if ($remaining > 0) {
            $pickedIds = array_map(static fn ($r): int => (int) $r->id, $picked);
            foreach ($strata as $rows) {
                foreach ($rows as $row) {
                    if ($remaining <= 0) {
                        break 2;
                    }
                    if (! in_array((int) $row->id, $pickedIds, true)) {
                        $picked[] = $row;
                        $pickedIds[] = (int) $row->id;
                        $remaining--;
                    }
                }
            }
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $fh = fopen($path, 'w');
        fwrite($fh, "id\tpopulation\tpredicted_primary\tgold_label\ttext\n");
        foreach ($picked as $row) {
            fwrite($fh, implode("\t", [
                $row->id,
                $row->population,
                $row->primary_category ?? 'unclassified',
                '', // gold_label — заполняет ревьюер
                str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], (string) $row->text),
            ])."\n");
        }
        fclose($fh);

        $this->line(json_encode([
            'sheet' => $path,
            'week' => $week,
            'sample' => count($picked),
            'eligible' => $totalEligible,
            'strata' => count($strata),
            'note' => 'fill the gold_label column inside the approved environment only',
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function importSheet(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Sheet not found: {$path}");

            return self::FAILURE;
        }

        $rows = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, "\t");
        $idx = array_flip($header ?: []);
        $needed = ['id', 'predicted_primary', 'gold_label'];
        foreach ($needed as $col) {
            if (! isset($idx[$col])) {
                $this->error("Sheet is missing the {$col} column.");

                return self::FAILURE;
            }
        }
        while (($line = fgetcsv($fh, 0, "\t")) !== false) {
            if (count($line) < 3) {
                continue;
            }
            $rows[] = [
                'id' => (int) $line[$idx['id']],
                'predicted' => trim((string) $line[$idx['predicted_primary']]),
                // Буквы A–I — вверх, слова (unclassified/…) — вниз:
                // единый регистр для сравнения со списком допустимых меток.
                'gold' => (static function (string $raw): string {
                    $raw = trim($raw);

                    return mb_strlen($raw) === 1 ? mb_strtoupper($raw) : mb_strtolower($raw);
                })((string) $line[$idx['gold_label']]),
            ];
        }
        fclose($fh);

        $labeled = array_values(array_filter($rows, static fn (array $r): bool => $r['gold'] !== ''));
        if ($labeled === []) {
            $this->error('No labeled rows found (gold_label column is empty).');

            return self::FAILURE;
        }

        $invalid = array_values(array_filter(
            $labeled,
            static fn (array $r): bool => ! in_array($r['gold'], self::GOLD_LABELS, true)
        ));
        if ($invalid !== []) {
            $this->error(sprintf('%d rows have gold labels outside %s.', count($invalid), implode('|', self::GOLD_LABELS)));

            return self::FAILURE;
        }

        $goldTopic = array_values(array_filter(
            $labeled,
            static fn (array $r): bool => in_array($r['gold'], ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], true)
        ));
        $classifiedGoldTopic = array_values(array_filter(
            $goldTopic,
            static fn (array $r): bool => $r['predicted'] !== 'unclassified'
        ));

        $matches = 0;
        $missesByCategory = [];
        foreach ($classifiedGoldTopic as $r) {
            if ($r['predicted'] === $r['gold']) {
                $matches++;
            } else {
                $missesByCategory[$r['gold']] = ($missesByCategory[$r['gold']] ?? 0) + 1;
            }
        }

        $precision = count($classifiedGoldTopic) > 0
            ? round($matches / count($classifiedGoldTopic), 4)
            : null;
        $coverage = count($goldTopic) > 0
            ? round(count($classifiedGoldTopic) / count($goldTopic), 4)
            : null;

        $verdict = [
            'n_labeled' => count($labeled),
            'n_gold_topic' => count($goldTopic),
            'n_classified' => count($classifiedGoldTopic),
            'matches' => $matches,
            'precision' => $precision,
            'coverage' => $coverage,
            'misses_by_gold_category' => $missesByCategory,
            'gate_min_precision' => 0.93,
            'gate' => $precision !== null && $precision >= 0.93 ? 'pass' : 'fail',
        ];

        $this->line(json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function localStart(string $date): string
    {
        return CarbonImmutable::parse($date, 'Europe/Moscow')
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
