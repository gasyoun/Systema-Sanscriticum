<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionReviewItem;
use App\Models\SupportQuestionReviewLabel;
use App\Models\SupportQuestionReviewSample;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * H5773 — защищённый workflow gold-ревью недельных вопросов.
 *
 * Заморозка выборки переиспользует существующую стратификацию
 * support:questions-review (H5709/H5768) — сервис только вызывает команду и
 * импортирует её лист в замороженные таблицы, не дублируя ни выборку, ни
 * математику точности. Вердикт гейта тоже не считается здесь: единственный
 * источник — (исправленный H5768) импортёр support:questions-review --import.
 *
 * Наружу (stdout/логи/уведомления) уходят ТОЛЬКО агрегаты — счётчики и
 * вердикт импортёра; тексты сообщений остаются в доверенном окружении.
 */
class GoldReviewService
{
    /** @var list<string> */
    public const GOLD_LABELS = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I',
        'unclassified', 'not_question', 'other',
    ];

    /** Минимум для гейта недели (H5768); сэмпл меньше — гейт inconclusive. */
    public const REQUIRED_FOR_GATE = 100;

    /**
     * Заморозка из листа команды support:questions-review: окно и версия —
     * из ответа самой команды, состав — из листа. Возвращает агрегаты
     * (без текстов); frozen=false, если подходящих сообщений не хватило.
     *
     * @param  array<string, string|null>  $window  ['from' => ?string, 'to' => ?string]
     * @return array<string, mixed>
     */
    public function freezeFromCommand(array $window, int $size, ?int $userId): array
    {
        $temp = storage_path('app/support-questions/gold-freeze-'.uniqid('', false).'.tsv');
        $args = ['--sample' => $size, '--sheet' => $temp];
        if (! empty($window['from']) && ! empty($window['to'])) {
            $args['--from'] = $window['from'];
            $args['--to'] = $window['to'];
        }

        Artisan::call('support:questions-review', $args);
        $stdout = trim((string) Artisan::output());
        $meta = json_decode($stdout, true) ?: [];

        if (($meta['gate'] ?? null) === 'inconclusive') {
            return ['frozen' => false] + $meta;
        }

        if (! is_file($temp)) {
            return [
                'frozen' => false,
                'error' => 'review sheet was not written',
                'command_output' => $meta,
            ];
        }

        try {
            $sample = $this->freezeFromSheet(
                $temp,
                (string) ($meta['window'] ?? ''),
                $userId,
            );
        } finally {
            @unlink($temp);
        }

        return [
            'frozen' => true,
            'sample_id' => $sample->id,
            'window' => $sample->windowLabel(),
            'sample' => $sample->sample_size,
            'fingerprint' => $sample->fingerprint,
            'eligible' => $meta['eligible'] ?? null,
            'strata' => $meta['strata'] ?? null,
        ];
    }

    /**
     * Импорт свежесгенерированного листа (id/population/predicted_primary/
     * gold_label/text) в замороженные таблицы. Лист с предзаполненной
     * gold-меткой отвергается: предсказания никогда не становятся золотом.
     *
     * @param  string  $window  "from..to" (эксклюзивно) — формат команды
     */
    public function freezeFromSheet(string $path, string $window, ?int $userId): SupportQuestionReviewSample
    {
        if (! is_file($path)) {
            throw new GoldReviewException("Sheet not found: {$path}");
        }
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $window, $m)) {
            throw new GoldReviewException("Malformed window: {$window}");
        }
        [, $from, $to] = $m;

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, "\t");
        $idx = array_flip($header ?: []);
        foreach (['id', 'population', 'predicted_primary', 'gold_label'] as $col) {
            if (! isset($idx[$col])) {
                fclose($fh);
                throw new GoldReviewException("Sheet is missing the {$col} column.");
            }
        }

        $rows = [];
        while (($line = fgetcsv($fh, 0, "\t")) !== false) {
            if (count($line) < 3) {
                continue;
            }
            $gold = trim((string) $line[$idx['gold_label']]);
            if ($gold !== '') {
                fclose($fh);
                throw new GoldReviewException(
                    'Sheet has pre-filled gold labels — predictions must never be imported as gold.'
                );
            }
            $rows[] = [
                'classification_id' => (int) $line[$idx['id']],
                'population' => (string) $line[$idx['population']],
                'predicted' => (string) $line[$idx['predicted_primary']],
            ];
        }
        fclose($fh);

        if ($rows === []) {
            throw new GoldReviewException('Sheet has no rows.');
        }

        $ids = array_column($rows, 'classification_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new GoldReviewException('Sheet has duplicate classification ids.');
        }

        // Сверка с живыми classification-строками: версия классификатора и
        // предсказание на момент заморозки должны совпадать с листом.
        $byId = SupportQuestionClassification::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $version = QuestionMessageClassifier::VERSION;
        foreach ($rows as $row) {
            $classification = $byId->get($row['classification_id']);
            if ($classification === null) {
                throw new GoldReviewException(
                    "Classification {$row['classification_id']} not found."
                );
            }
            if ($classification->classifier_version !== $version) {
                throw new GoldReviewException(
                    "Classification {$row['classification_id']} has version "
                    .$classification->classifier_version.", expected {$version}."
                );
            }
            if (($classification->primary_category ?? 'unclassified') !== $row['predicted']) {
                throw new GoldReviewException(
                    "Classification {$row['classification_id']} predicted label drifted."
                );
            }
        }

        // Fingerprint — состав и предсказания, независимо от порядка строк:
        // тот же сэмпл дважды не замораживается (идемпотентность).
        $lines = array_map(
            static fn (array $r): string => $r['classification_id']."\t".$r['population']."\t".$r['predicted'],
            $rows
        );
        sort($lines);
        $fingerprint = hash('sha256', implode("\n", $lines));

        $existing = SupportQuestionReviewSample::query()->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return $existing;
        }

        return \DB::transaction(function () use ($rows, $from, $to, $version, $fingerprint, $userId): SupportQuestionReviewSample {
            $sample = SupportQuestionReviewSample::create([
                'window_from' => $from,
                'window_to' => $to,
                'classifier_version' => $version,
                'sample_size' => count($rows),
                'fingerprint' => $fingerprint,
                'status' => SupportQuestionReviewSample::STATUS_OPEN,
                'created_by' => $userId,
            ]);
            foreach ($rows as $i => $row) {
                SupportQuestionReviewItem::create([
                    'sample_id' => $sample->id,
                    'classification_id' => $row['classification_id'],
                    'population' => $row['population'],
                    'predicted_primary' => $row['predicted'],
                    'position' => $i + 1,
                ]);
            }

            return $sample->refresh();
        });
    }

    /**
     * Гард перед любой записью метки: сэмпл открыт и не устарел.
     */
    public function assertLabelable(SupportQuestionReviewSample $sample): void
    {
        if ($sample->status !== SupportQuestionReviewSample::STATUS_OPEN) {
            throw new GoldReviewException('Sample is already completed — labels are read-only.');
        }
        if ($sample->classifier_version !== QuestionMessageClassifier::VERSION) {
            throw new StaleGoldSampleException(
                'Sample was frozen for classifier '.$sample->classifier_version
                .', current is '.QuestionMessageClassifier::VERSION
                .' — freeze a new sample instead of mixing versions.'
            );
        }
    }

    /**
     * Записать gold-метку (или обновить уже записанную — item один, метка
     * одна). Чужой/несуществующий item и некорректная метка отвергаются.
     */
    public function setGoldLabel(
        SupportQuestionReviewSample $sample,
        int $itemId,
        User $reviewer,
        string $gold
    ): SupportQuestionReviewLabel {
        $this->assertLabelable($sample);

        $item = $sample->items()->whereKey($itemId)->first();
        if ($item === null) {
            throw new GoldReviewException('Item does not belong to this sample.');
        }

        $gold = mb_strlen(trim($gold)) === 1 ? mb_strtoupper(trim($gold)) : mb_strtolower(trim($gold));
        if (! in_array($gold, self::GOLD_LABELS, true)) {
            throw new GoldReviewException(
                'Invalid gold label: allowed '.implode('|', self::GOLD_LABELS).'.'
            );
        }

        return \DB::transaction(function () use ($sample, $item, $reviewer, $gold): SupportQuestionReviewLabel {
            $label = SupportQuestionReviewLabel::updateOrCreate(
                ['item_id' => $item->id],
                [
                    'sample_id' => $sample->id,
                    'user_id' => $reviewer->id,
                    'gold_label' => $gold,
                ]
            );

            $labeled = $sample->labels()->count();
            $total = $sample->items()->count();
            if ($labeled === $total && $total > 0) {
                $sample->forceFill([
                    'status' => SupportQuestionReviewSample::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();
            }

            return $label;
        });
    }

    /**
     * @return array{total: int, labeled: int, remaining: int, complete: bool, first_unlabeled: ?int}
     */
    public function progress(SupportQuestionReviewSample $sample): array
    {
        $total = $sample->items()->count();
        $labeledPositions = $sample->items()
            ->join('support_question_review_labels', 'support_question_review_labels.item_id', '=', 'support_question_review_items.id')
            ->pluck('position')
            ->all();
        $labeled = count($labeledPositions);
        $firstUnlabeled = null;
        for ($p = 1; $p <= $total; $p++) {
            if (! in_array($p, $labeledPositions, true)) {
                $firstUnlabeled = $p;
                break;
            }
        }

        return [
            'total' => $total,
            'labeled' => $labeled,
            'remaining' => max(0, $total - $labeled),
            'complete' => $total > 0 && $labeled === $total,
            'first_unlabeled' => $firstUnlabeled,
        ];
    }

    /**
     * Экспорт заполненного gold-листа для импортёра support:questions-review.
     * Только id/метки — колонки текста нет вовсе, лист не покидает
     * защищённый storage/app.
     */
    public function exportGoldSheet(SupportQuestionReviewSample $sample): string
    {
        $progress = $this->progress($sample);
        if (! $progress['complete']) {
            throw new GoldReviewException(
                "Sample is not complete ({$progress['labeled']}/{$progress['total']}) — export refused."
            );
        }

        $path = storage_path('app/support-questions/gold-'.$sample->windowLabel().'-sample'.$sample->id.'.tsv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $fh = fopen($path, 'w');
        fwrite($fh, "id\tpopulation\tpredicted_primary\tgold_label\n");
        $sample->items()
            ->orderBy('position')
            ->with('label')
            ->get()
            ->each(function (SupportQuestionReviewItem $item) use ($fh): void {
                fwrite($fh, implode("\t", [
                    $item->classification_id,
                    $item->population,
                    $item->predicted_primary,
                    $item->label->gold_label,
                ])."\n");
            });
        fclose($fh);

        return $path;
    }

    /**
     * Вердикт гейта — ТОЛЬКО через (исправленный H5768) импортёр: экспорт
     * листа + Artisan-вызов, наружу агрегаты импортёра. Здесь никакая
     * точность не считается — единственный источник гейта импортёр.
     *
     * @return array{sheet: string, verdict: array<string, mixed>|null}
     */
    public function verdictViaImporter(SupportQuestionReviewSample $sample): array
    {
        $sheet = $this->exportGoldSheet($sample);
        Artisan::call('support:questions-review', ['--import' => $sheet]);
        $raw = trim((string) Artisan::output());
        $verdict = json_decode($raw, true);

        return ['sheet' => $sheet, 'verdict' => is_array($verdict) ? $verdict : ['raw' => $raw]];
    }

    /**
     * Агрегаты по готовой выборке для экрана/отчёта: только счётчики.
     *
     * @return array<string, int|string|null>
     */
    public function aggregates(SupportQuestionReviewSample $sample): array
    {
        $progress = $this->progress($sample);

        return [
            'sample_id' => $sample->id,
            'window' => $sample->windowLabel(),
            'classifier_version' => $sample->classifier_version,
            'total' => $progress['total'],
            'labeled' => $progress['labeled'],
            'remaining' => $progress['remaining'],
            'complete' => $progress['complete'] ? 1 : 0,
            'required_for_gate' => self::REQUIRED_FOR_GATE,
            'gate_ready' => $progress['complete'] && $progress['total'] >= self::REQUIRED_FOR_GATE ? 1 : 0,
        ];
    }
}
