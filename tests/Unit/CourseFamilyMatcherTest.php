<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * H3083 — матчер семьи потоков. Класс чистый, поэтому тест без БД и без
 * приложения: PHPUnit\TestCase, а не Tests\TestCase.
 */
class CourseFamilyMatcherTest extends TestCase
{
    private CourseFamilyMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new CourseFamilyMatcher;
    }

    /** @test */
    public function three_shivaism_streams_collapse_into_one_family(): void
    {
        $slugs = [
            $this->matcher->familySlug('Кашмирский шиваизм (1 поток, 2025)'),
            $this->matcher->familySlug('Кашмирский шиваизм (2 поток, 2026)'),
            $this->matcher->familySlug('Кашмирский шиваизм 2025 в записи'),
        ];

        $this->assertSame(['kasmirskii-sivaizm'], array_values(array_unique($slugs)));
    }

    /**
     * Контрпримеры: разные курсы НЕ должны схлопнуться в одну семью. Это тот
     * риск, ради которого бэкфил по умолчанию только печатает (§4 VERIFICATION).
     *
     * @test
     */
    public function different_courses_do_not_collapse(): void
    {
        $pairs = [
            ['Кашмирский шиваизм (1 поток, 2025)', 'Кашмирский шайвизм (1 поток, 2025)'],
            ['Санскрит с нуля (1 поток, 2025)', 'Санскрит продолжающим (1 поток, 2025)'],
            ['Хинди (2 поток, 2026)', 'Хинди для чтения (2 поток, 2026)'],
            ['Логика (часть 1)', 'Логика Ньяи (часть 1)'],
        ];

        foreach ($pairs as [$a, $b]) {
            $this->assertNotSame(
                $this->matcher->familySlug($a),
                $this->matcher->familySlug($b),
                "«{$a}» и «{$b}» не должны быть одной семьёй",
            );
        }
    }

    /** @test */
    public function tails_are_stripped_but_the_subject_survives(): void
    {
        $this->assertSame('sanskrit-s-nulia', $this->matcher->familySlug('Санскрит с нуля (3 поток, 2026)'));
        $this->assertSame('sanskrit-s-nulia', $this->matcher->familySlug('Санскрит с нуля, часть 2'));
        $this->assertSame('sanskrit-s-nulia', $this->matcher->familySlug('Санскрит с нуля 2024 в записи'));
        $this->assertSame('sanskrit-s-nulia', $this->matcher->familySlug('Санскрит с нуля 2024'));
    }

    /** @test */
    public function title_that_is_only_a_tail_yields_no_family(): void
    {
        // Иначе «мусорная» пустая семья схлопнула бы разные курсы в одну кучу.
        $this->assertSame('', $this->matcher->familySlug('(2 поток, 2026)'));
        $this->assertSame('', $this->matcher->familySlug('2025'));
    }

    /** @test */
    public function stream_role_is_live_when_blocks_or_tariffs_exist(): void
    {
        // Оба признака — живой поток.
        $this->assertSame(CourseFamilyMatcher::ROLE_LIVE, $this->matcher->streamRole(4, 5, 100));
        // Тарифы временно выключены, но блоки есть — всё ещё живой.
        $this->assertSame(CourseFamilyMatcher::ROLE_LIVE, $this->matcher->streamRole(4, 0, 100));
        // Блоков нет, но тарифы есть — тоже живой (курс ещё не размечен блоками).
        $this->assertSame(CourseFamilyMatcher::ROLE_LIVE, $this->matcher->streamRole(0, 3, 0));
    }

    /** @test */
    public function stream_role_is_recording_only_without_blocks_and_tariffs(): void
    {
        // Ровно случай курса 424: ни блоков, ни тарифов, но деньги пришли.
        $this->assertSame(CourseFamilyMatcher::ROLE_RECORDING, $this->matcher->streamRole(0, 0, 17));
        // Совсем пустой курс ролью не наделяется.
        $this->assertSame(CourseFamilyMatcher::ROLE_UNKNOWN, $this->matcher->streamRole(0, 0, 0));
    }

    /** @test */
    public function ordinal_is_read_from_the_title(): void
    {
        $this->assertSame(1, $this->matcher->ordinal('Кашмирский шиваизм (1 поток, 2025)'));
        $this->assertSame(2, $this->matcher->ordinal('Кашмирский шиваизм (2 поток, 2026)'));
        $this->assertSame(3, $this->matcher->ordinal('Санскрит (поток 3)'));
        $this->assertSame(2, $this->matcher->ordinal('Логика, часть 2'));
        $this->assertSame(0, $this->matcher->ordinal('Кашмирский шиваизм 2025 в записи'));
    }

    /** @test */
    public function unnumbered_streams_sort_by_first_payment_after_numbered_ones(): void
    {
        [$n1, $k1] = $this->matcher->ordinalFor('Курс (1 поток, 2025)', Carbon::parse('2025-09-01'));
        [$n0, $k0] = $this->matcher->ordinalFor('Курс 2025 в записи', Carbon::parse('2026-05-01'));

        $this->assertSame(1, $n1);
        $this->assertSame(0, $n0);
        $this->assertLessThan($k0, $k1, 'пронумерованный поток должен идти раньше ненумерованного');
    }
}
