<?php

declare(strict_types=1);

namespace Tests\Unit\Srs;

use App\Services\Srs\Fsrs;
use App\Services\Srs\FsrsCard;
use App\Services\Srs\Rating;
use App\Services\Srs\State;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет наш PHP-порт FSRS против ЭТАЛОННЫХ векторов из
 * open-spaced-repetition/py-fsrs (tests/test_basic.py) — те же числа, что публикует
 * референс. Порт «не изобретаем» — эти два вектора и есть контракт корректности.
 *
 * Чистый unit-тест: движок не зависит от Laravel, поэтому наследуемся напрямую от
 * PHPUnit\Framework\TestCase (без загрузки приложения/БД).
 */
class FsrsTest extends TestCase
{
    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    /**
     * Эталон py-fsrs `test_review_card`: последовательность интервалов (в днях)
     * при фиксированных весах и выключенном fuzz.
     */
    public function test_reference_interval_history(): void
    {
        $fsrs = new Fsrs(enableFuzzing: false);

        $ratings = [
            Rating::Good, Rating::Good, Rating::Good, Rating::Good, Rating::Good, Rating::Good,
            Rating::Again, Rating::Again,
            Rating::Good, Rating::Good, Rating::Good, Rating::Good, Rating::Good,
        ];

        $now = $this->utc('2022-11-29 12:30:00');
        $card = FsrsCard::fresh($now);

        $ivlHistory = [];
        foreach ($ratings as $rating) {
            $card = $fsrs->reviewCard($card, $rating, $now);
            $ivlHistory[] = intdiv($card->due->getTimestamp() - $card->lastReview->getTimestamp(), 86400);
            $now = $card->due;
        }

        $this->assertSame([0, 2, 11, 46, 163, 498, 0, 0, 2, 4, 7, 12, 21], $ivlHistory);
    }

    /**
     * Эталон py-fsrs `test_memo_state`: итоговые stability/difficulty после
     * заданной последовательности оценок и таймингов (fuzz не влияет на них).
     */
    public function test_reference_memo_state(): void
    {
        $fsrs = new Fsrs();

        $ratings = [Rating::Again, Rating::Good, Rating::Good, Rating::Good, Rating::Good, Rating::Good];
        $ivls = [0, 0, 1, 3, 8, 21];

        $now = $this->utc('2022-11-29 12:30:00');
        $card = FsrsCard::fresh($now);

        foreach ($ratings as $i => $rating) {
            $now = $now->modify(sprintf('+%d days', $ivls[$i]));
            $card = $fsrs->reviewCard($card, $rating, $now);
        }

        $this->assertEqualsWithDelta(53.62691, $card->stability, 1e-4);
        $this->assertEqualsWithDelta(6.3574867, $card->difficulty, 1e-4);
    }

    /**
     * Свежая карточка при первом «Good» уходит в шаг обучения (~10 минут), а не на дни.
     */
    public function test_first_good_schedules_learning_step(): void
    {
        $fsrs = new Fsrs(enableFuzzing: false);
        $now = $this->utc('2026-07-07 10:00:00');

        $card = $fsrs->reviewCard(FsrsCard::fresh($now), Rating::Good, $now);

        $this->assertSame(State::Learning, $card->state);
        $seconds = $card->due->getTimestamp() - $now->getTimestamp();
        $this->assertSame(600, $seconds); // второй learning step = 10 минут
    }

    public function test_retrievability_decays_from_one(): void
    {
        $fsrs = new Fsrs(enableFuzzing: false);
        $now = $this->utc('2026-07-07 10:00:00');

        // Свежая карточка без истории — вероятность вспомнить 0.
        $fresh = FsrsCard::fresh($now);
        $this->assertSame(0.0, $fsrs->getCardRetrievability($fresh, $now));

        // После review в состоянии Review вероятность в (0,1].
        $card = FsrsCard::fresh($now);
        foreach ([Rating::Good, Rating::Good, Rating::Good] as $r) {
            $card = $fsrs->reviewCard($card, $r, $now);
            $now = $card->due;
        }
        $r = $fsrs->getCardRetrievability($card, $card->lastReview);
        $this->assertGreaterThan(0.0, $r);
        $this->assertLessThanOrEqual(1.0, $r);
    }
}
