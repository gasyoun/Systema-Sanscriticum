<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3394: precision-проход классификатора на реальном корпусе входящих
 * rusamskrtam-лейна (приватные чаты, PII замаскирован).
 *
 * Классификация теперь управляет АВТООТПРАВКАМИ (H3380), поэтому мисроут —
 * это неправильный шаблон студенту, а не просто черновик. Корпус живёт в
 * tests/Fixtures/Support/classifier_corpus_2026_08.json; пополнять при
 * каждой ревизии канреплаев.
 */
class ClassifierPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{t: string, cat: ?string}> */
    private function corpus(): array
    {
        $raw = file_get_contents(__DIR__.'/../../fixtures/Support/classifier_corpus_2026_08.json');
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['cases'];
    }

    public function test_corpus_precision_meets_threshold_and_regressions_hold(): void
    {
        $suggester = app(SupportAnswerSuggester::class);
        $corpus = $this->corpus();

        $perCat = [];
        $correct = 0;

        foreach ($corpus as $case) {
            $got = $suggester->categorize($case['t']);
            $expected = $case['cat'];

            if ($got === $expected) {
                $correct++;
            }

            foreach (array_unique([$got ?? 'none', $expected ?? 'none']) as $cat) {
                $perCat[$cat] ??= ['tp' => 0, 'pred' => 0, 'exp' => 0];
            }
            if ($got === $expected) {
                $perCat[$got ?? 'none']['tp']++;
            }
            if ($got !== null) {
                $perCat[$got]['pred']++;
            }
            if ($expected !== null) {
                $perCat[$expected]['exp']++;
            }
        }

        $total = count($corpus);
        $accuracy = $correct / $total;

        $table = '';
        foreach ($perCat as $cat => $s) {
            $precision = $s['pred'] > 0 ? round(100 * $s['tp'] / $s['pred']).'%' : '—';
            $recall = $s['exp'] > 0 ? round(100 * $s['tp'] / $s['exp']).'%' : '—';
            $table .= sprintf("%s: precision %s (%d/%d), recall %s (%d/%d)\n", $cat, $precision, $s['tp'], $s['pred'], $recall, $s['tp'], $s['exp']);
        }

        $this->assertGreaterThanOrEqual(
            0.93,
            $accuracy,
            'Classifier accuracy '.round(100 * $accuracy)."% below 93% on corpus of {$total}.\n{$table}",
        );

        // Именованные регрессии первого смоука/ревизии.
        $regressions = [
            ['сколько стоит курс и где ссылка', 'D'],
            ['ссылку на оплату не нашла', 'D'],
            ['сколько будет стоить курс по календарям?', 'D'],
            ['есть ли функция рассрочки или по частям оплата?', 'D'],
            ['Добрый вечер! Не могу открыть занятия по паролю 102. Он изменился?', 'E'],
            ['куда мы должны прикреплять домашнюю работу по занятию 3?', 'F'],
            ['Жаль, тогда прошу сделать возврат', null],
            ['Возврат', null],
            ['Намо намах!', null],
        ];
        foreach ($regressions as [$text, $expected]) {
            $this->assertSame(
                $expected,
                $suggester->categorize($text),
                "Regression misroute on: {$text}",
            );
        }
    }
}
