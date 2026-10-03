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
 *
 * Семантика гейта (H5768): знаменатель точности — ВСЕ предсказания A–I;
 * любое несовпадение gold (вкл. not_question/other/unclassified) — ошибка.
 * PASS требует ≥100 уникальных полностью размеченных строк, ноль
 * неразмеченных и валидные предсказанные метки; ноль предсказаний —
 * inconclusive. Recall и покрытие публикуются отдельно, в гейт не входят.
 */
class QuestionsReviewCommand extends Command
{
    protected $signature = 'support:questions-review
        {--week= : ISO-дата понедельника недели (дефолт — последний снапшот)}
        {--from= : ISO-дата начала окна выборки (вместе с --to; стратификация по всему бэкфиллу)}
        {--to= : ISO-дата конца окна выборки (эксклюзивно)}
        {--sample=100 : размер стратифицированной выборки}
        {--sheet= : путь листа ревью (дефолт storage/app/support-questions/review-<week>.tsv)}
        {--import= : путь заполненного листа с колонкой gold_label — печатает только агрегаты}';

    protected $description = 'Стратифицированное ревью точности классификации недельных вопросов (только агрегаты наружу).';

    private const GOLD_LABELS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'unclassified', 'not_question', 'other'];

    private const TOPIC_LABELS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

    public function handle(): int
    {
        $import = (string) ($this->option('import') ?? '');
        if ($import !== '') {
            return $this->importSheet($import);
        }

        return $this->writeSheet();
    }

    /**
     * Границы окна выборки. Дефолт — неделя последнего снапшота; --week задаёт
     * конкретную неделю; --from/--to (вместе) — произвольное окно: гейт H5709
     * требует 100 подходящих сообщений, а в одной неделе их ~60-80, поэтому
     * ревью стратифицируется по всему бэкфилл-корпусу.
     *
     * @return array{0: string, 1: string} [from-date, to-date-exclusive]
     */
    private function windowBounds(): array
    {
        $fromRaw = (string) ($this->option('from') ?? '');
        $toRaw = (string) ($this->option('to') ?? '');
        if ($fromRaw !== '' && $toRaw !== '') {
            return [
                CarbonImmutable::parse($fromRaw, 'Europe/Moscow')->toDateString(),
                CarbonImmutable::parse($toRaw, 'Europe/Moscow')->toDateString(),
            ];
        }

        $week = (string) ($this->option('week') ?? '');
        if ($week !== '') {
            $monday = CarbonImmutable::parse($week, 'Europe/Moscow')->startOfWeek(CarbonImmutable::MONDAY);

            return [$monday->toDateString(), $monday->addDays(7)->toDateString()];
        }

        $latest = DB::table('support_question_weekly_snapshots')
            ->orderByDesc('week_start')
            ->value('week_start');

        if ($latest === null) {
            throw new \RuntimeException('No snapshots yet — run support:questions-weekly first or pass --week.');
        }

        $monday = CarbonImmutable::parse((string) $latest, 'Europe/Moscow');

        return [$monday->toDateString(), $monday->addDays(7)->toDateString()];
    }

    private function writeSheet(): int
    {
        [$from, $to] = $this->windowBounds();
        $week = $from.'..'.$to;
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
                ->where('sent_at', '>=', $this->localStart($from))
                ->where('sent_at', '<', $this->localStart($to)))
            ->join('telegram_support_messages', 'telegram_support_messages.id', '=', 'support_question_classifications.telegram_support_message_id')
            ->get(['support_question_classifications.*', 'telegram_support_messages.text']);

        if ($eligible->count() < $sample) {
            $this->line(json_encode([
                'gate' => 'inconclusive',
                'window' => $week,
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
            'window' => $week,
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
                // Буквы A–I — вверх, слова (unclassified/…) — вниз:
                // единый регистр для сравнения со списком допустимых меток.
                'predicted' => (static function (string $raw): string {
                    $raw = trim($raw);

                    return mb_strlen($raw) === 1 ? mb_strtoupper($raw) : mb_strtolower($raw);
                })((string) $line[$idx['predicted_primary']]),
                'gold' => (static function (string $raw): string {
                    $raw = trim($raw);

                    return mb_strlen($raw) === 1 ? mb_strtoupper($raw) : mb_strtolower($raw);
                })((string) $line[$idx['gold_label']]),
            ];
        }
        fclose($fh);

        // Структурная валидация (H5768): лист с дубликатами id или
        // невалидными предсказаниями не может дать PASS ни при каких
        // метриках — импорт отказывает сразу.
        $ids = array_map(static fn (array $r): int => $r['id'], $rows);
        if (count($ids) !== count(array_unique($ids))) {
            $duplicates = array_values(array_unique(array_diff_key($ids, array_unique($ids))));
            $this->error(sprintf('Sheet has duplicate row ids (e.g. %s) — a review with duplicate IDs cannot PASS.', implode(', ', array_slice($duplicates, 0, 5))));

            return self::FAILURE;
        }
        if (array_filter($ids, static fn (int $id): bool => $id <= 0) !== []) {
            $this->error('Sheet has non-positive row ids — every reviewed row must carry a valid message id.');

            return self::FAILURE;
        }
        $validPredicted = array_merge(self::TOPIC_LABELS, ['unclassified']);
        $invalidPredicted = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ! in_array($r['predicted'], $validPredicted, true)
        ));
        if ($invalidPredicted !== []) {
            $this->error(sprintf(
                '%d rows have predicted labels outside %s — predicted labels must be validated before any verdict.',
                count($invalidPredicted),
                implode('|', $validPredicted),
            ));

            return self::FAILURE;
        }

        $unlabeled = array_values(array_filter($rows, static fn (array $r): bool => $r['gold'] === ''));
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

        // Знаменатель точности (H5768) = ВСЕ строки с предсказанием A–I.
        // Любое несовпадение gold — ошибка, ВКЛЮЧАЯ not_question/other/
        // unclassified: прежний знаменатель (строки с gold A–I) прятал до
        // 99 ложных срабатываний из 100 за precision=1.0.
        $predictedTopic = array_values(array_filter(
            $labeled,
            static fn (array $r): bool => in_array($r['predicted'], self::TOPIC_LABELS, true)
        ));

        $matches = 0;
        $errorsByGoldLabel = [];
        foreach ($predictedTopic as $r) {
            if ($r['predicted'] === $r['gold']) {
                $matches++;
            } else {
                $key = $r['gold'] === '' ? '(empty)' : $r['gold'];
                $errorsByGoldLabel[$key] = ($errorsByGoldLabel[$key] ?? 0) + 1;
            }
        }

        // Recall и покрытие публикуются ОТДЕЛЬНО и в гейт не входят.
        $goldTopic = array_values(array_filter(
            $labeled,
            static fn (array $r): bool => in_array($r['gold'], self::TOPIC_LABELS, true)
        ));
        $goldTopicMatched = array_values(array_filter(
            $goldTopic,
            static fn (array $r): bool => $r['predicted'] === $r['gold']
        ));
        $goldTopicClassified = array_values(array_filter(
            $goldTopic,
            static fn (array $r): bool => $r['predicted'] !== 'unclassified'
        ));

        $precision = count($predictedTopic) > 0
            ? round($matches / count($predictedTopic), 4)
            : null;
        $recall = count($goldTopic) > 0
            ? round(count($goldTopicMatched) / count($goldTopic), 4)
            : null;
        $coverage = count($goldTopic) > 0
            ? round(count($goldTopicClassified) / count($goldTopic), 4)
            : null;

        // Гейт: минимум 100 УНИКАЛЬНЫХ полностью размеченных строк, ноль
        // неразмеченных, хотя бы одно предсказание — иначе inconclusive
        // (наблюдения не фабрикуются); при достаточной выборке и precision
        // < 0.93 — честный fail.
        $required = max(1, (int) ($this->option('sample') ?: 100));
        $gateReason = null;
        if (count($labeled) < $required) {
            $gateReason = 'insufficient_labeled_rows';
        } elseif ($unlabeled !== []) {
            $gateReason = 'partially_labeled_sheet';
        } elseif ($predictedTopic === []) {
            $gateReason = 'zero_predictions';
        }

        $verdict = [
            'n_rows' => count($rows),
            'n_labeled' => count($labeled),
            'n_unlabeled' => count($unlabeled),
            'required_labeled_rows' => $required,
            'n_predictions' => count($predictedTopic),
            'matches' => $matches,
            'n_gold_topic' => count($goldTopic),
            'n_classified' => count($goldTopicClassified),
            'precision' => $precision,
            'recall' => $recall,
            'coverage' => $coverage,
            'errors_by_gold_label' => $errorsByGoldLabel,
            'gate_min_precision' => 0.93,
            'gate' => $gateReason !== null
                ? 'inconclusive'
                : ($precision !== null && $precision >= 0.93 ? 'pass' : 'fail'),
            'gate_reason' => $gateReason,
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
