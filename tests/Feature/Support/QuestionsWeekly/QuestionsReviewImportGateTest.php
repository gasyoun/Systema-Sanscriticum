<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H5768 — контрпримеры гейта точности импорта gold-листа.
 *
 * Прежний знаменатель (строки с gold A–I) прятал ложные срабатывания:
 * 1 верное предсказание + 99 ложных давали precision=1.0 и gate=pass.
 * Знаменатель теперь — ВСЕ предсказания A–I; любое несовпадение gold
 * (вкл. not_question/other/unclassified) — ошибка. PASS требует ≥100
 * уникальных полностью размеченных строк и валидные предсказания;
 * ноль предсказаний — inconclusive. Наблюдения не фабрикуются.
 */
class QuestionsReviewImportGateTest extends TestCase
{
    use RefreshDatabase;

    private string $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = storage_path('app/support-questions/test-import-gate-sheet.tsv');
        if (! is_dir(dirname($this->sheet))) {
            mkdir(dirname($this->sheet), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->sheet);
        parent::tearDown();
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $rows  [id, predicted, gold]
     */
    private function writeSheet(array $rows): void
    {
        $lines = ["id\tpopulation\tpredicted_primary\tgold_label\ttext"];
        foreach ($rows as [$id, $predicted, $gold]) {
            $lines[] = implode("\t", [$id, 'enquiry', $predicted, $gold, 'текст скрыт']);
        }
        file_put_contents($this->sheet, implode("\n", $lines)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function import(): array
    {
        $exit = Artisan::call('support:questions-review', ['--import' => $this->sheet]);
        $output = Artisan::output();

        return ['exit' => $exit, 'output' => $output, 'json' => json_decode($output, true) ?: []];
    }

    public function test_99_false_positives_cannot_pass(): void
    {
        // Контрпример верификатора: 1 верный A/A + 99 ложных D/not_question.
        $rows = [[1, 'A', 'A']];
        foreach (range(2, 100) as $i) {
            $rows[] = [$i, 'D', 'not_question'];
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(100, $result['json']['n_predictions']);
        $this->assertSame(1, $result['json']['matches']);
        // Реальная точность 1/100 = 0.01, не 1.0.
        $this->assertSame(0.01, $result['json']['precision']);
        $this->assertSame('fail', $result['json']['gate']);
        // Все 99 ложных срабатываний видны как ошибки по gold-метке.
        $this->assertSame(99, $result['json']['errors_by_gold_label']['not_question']);
    }

    public function test_insufficient_rows_are_inconclusive_not_pass(): void
    {
        // «Однометочная» выборка из 5 строк не может PASS: гейт требует 100.
        $this->writeSheet([
            [1, 'D', 'D'], [2, 'D', 'D'], [3, 'D', 'D'], [4, 'D', 'D'], [5, 'D', 'D'],
        ]);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('inconclusive', $result['json']['gate']);
        $this->assertSame('insufficient_labeled_rows', $result['json']['gate_reason']);
        $this->assertSame(100, $result['json']['required_labeled_rows']);
        // Идеальная точность на 5 строках всё равно не PASS (json: 1.0 → 1).
        $this->assertSame(1, $result['json']['precision']);
    }

    public function test_partially_labeled_sheet_cannot_pass(): void
    {
        // 103 строки, 100 размеченных с идеальной точностью, 3 пустые:
        // размеченных достаточно, но частичная разметка — не PASS.
        $rows = [];
        foreach (range(1, 100) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        foreach (range(101, 103) as $i) {
            $rows[] = [$i, 'D', ''];
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('inconclusive', $result['json']['gate']);
        $this->assertSame('partially_labeled_sheet', $result['json']['gate_reason']);
        $this->assertSame(3, $result['json']['n_unlabeled']);
    }

    public function test_duplicate_ids_refuse_import(): void
    {
        $rows = [];
        foreach (range(1, 100) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        $rows[] = [42, 'D', 'D']; // дубль id
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('duplicate', $result['output']);
    }

    public function test_invalid_predicted_labels_refuse_import(): void
    {
        $rows = [];
        foreach (range(1, 100) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        $rows[49][1] = 'Z'; // невалидная предсказанная категория
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('predicted labels outside', $result['output']);
    }

    public function test_zero_predictions_is_inconclusive(): void
    {
        // Ни одного предсказания A–I (все unclassified) — точность
        // неопределима, не «pass» и не «fail».
        $rows = [];
        foreach (range(1, 100) as $i) {
            $rows[] = [$i, 'unclassified', $i % 2 === 0 ? 'D' : 'unclassified'];
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('inconclusive', $result['json']['gate']);
        $this->assertSame('zero_predictions', $result['json']['gate_reason']);
        $this->assertNull($result['json']['precision']);
        // Coverage публикуется отдельно: 50 gold-D, все предсказаны
        // unclassified → покрытие 0.
        $this->assertSame(0, $result['json']['coverage']);
    }

    public function test_genuine_pass_with_recall_and_coverage_published(): void
    {
        // 95/100 точных + 5 ошибок: precision 0.95 ≥ 0.93 → pass; recall и
        // coverage публикуются отдельно и в гейт не входят.
        $rows = [];
        foreach (range(1, 95) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        foreach (range(96, 100) as $i) {
            $rows[] = [$i, 'C', 'D']; // предсказан C, gold D — ошибка
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('pass', $result['json']['gate']);
        $this->assertSame(0.95, $result['json']['precision']);
        // Recall: 95 из 100 gold-D угаданы точно (5 ушли в C).
        $this->assertSame(0.95, $result['json']['recall']);
        // json-классификация: 1.0 печатается как int 1.
        $this->assertSame(1, $result['json']['coverage']);
        $this->assertSame(5, $result['json']['errors_by_gold_label']['D']);
    }

    public function test_exact_threshold_passes(): void
    {
        $rows = [];
        foreach (range(1, 93) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        foreach (range(94, 100) as $i) {
            $rows[] = [$i, 'D', 'not_question'];
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(0.93, $result['json']['precision']);
        $this->assertSame('pass', $result['json']['gate']);
    }

    public function test_predicted_unclassified_against_gold_topic_is_error(): void
    {
        // Пропуск (предсказано unclassified при gold D) не входит в
        // знаменатель точности, но честно виден в coverage — отдельная метрика.
        $rows = [];
        foreach (range(1, 90) as $i) {
            $rows[] = [$i, 'D', 'D'];
        }
        foreach (range(91, 100) as $i) {
            $rows[] = [$i, 'unclassified', 'D'];
        }
        $this->writeSheet($rows);

        $result = $this->import();

        $this->assertSame(0, $result['exit'], $result['output']);
        // json-классификация: 1.0 печатается как int 1.
        $this->assertSame(1, $result['json']['precision']);
        $this->assertSame(0.9, $result['json']['coverage']);
        $this->assertSame(0.9, $result['json']['recall']);
        // Гейт держится только на precision — но coverage виден рядом.
        $this->assertSame('pass', $result['json']['gate']);
    }
}
