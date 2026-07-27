<?php

declare(strict_types=1);

namespace Tests\Feature\Exercises;

use Tests\TestCase;

class RootDrillPagesTest extends TestCase
{
    private function fixtureRows(): array
    {
        $path = base_path('database/seeders/data/roots_frequency_ru.tsv');
        $lines = array_filter(explode("\n", rtrim(file_get_contents($path), "\n")));
        $header = str_getcsv(array_shift($lines), "\t");

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($header, str_getcsv($line, "\t"));
        }

        return $rows;
    }

    private function bands(): array
    {
        $data = file_get_contents(public_path('lila/roots/data.js'));
        preg_match('/global\.ROOT_BANDS\s*=\s*(\{.*\});/s', $data, $m);
        $this->assertNotEmpty($m, 'data.js must define global.ROOT_BANDS = {...};');

        return json_decode($m[1], true);
    }

    /** @test */
    public function the_family_index_and_all_three_band_pages_exist(): void
    {
        $this->assertFileExists(public_path('lila/roots/index.html'));
        $this->assertFileExists(public_path('lila/roots/top-25/index.html'));
        $this->assertFileExists(public_path('lila/roots/top-50/index.html'));
        $this->assertFileExists(public_path('lila/roots/top-100/index.html'));
        $this->assertFileExists(public_path('lila/roots/data.js'));
    }

    /** @test */
    public function every_band_page_references_the_free_games_gate(): void
    {
        foreach (['top-25', 'top-50', 'top-100'] as $band) {
            $html = file_get_contents(public_path("lila/roots/{$band}/index.html"));
            $this->assertStringContainsString(
                '/lila/gate.js',
                $html,
                "roots/{$band}/index.html must load the free-games gate"
            );
        }
    }

    /** @test */
    public function bands_have_the_right_size_and_are_cumulative_subsets(): void
    {
        $bands = $this->bands();

        $this->assertCount(25, $bands['top25']);
        $this->assertCount(50, $bands['top50']);
        $this->assertCount(100, $bands['top100']);

        $ranks25 = array_column($bands['top25'], 'rank');
        $ranks50 = array_column($bands['top50'], 'rank');
        $ranks100 = array_column($bands['top100'], 'rank');

        $this->assertEmpty(array_diff($ranks25, $ranks50), 'top25 must be a subset of top50');
        $this->assertEmpty(array_diff($ranks50, $ranks100), 'top50 must be a subset of top100');
    }

    /** @test */
    public function every_band_row_round_trips_to_a_real_fixture_row(): void
    {
        $fixtureByRank = [];
        foreach ($this->fixtureRows() as $row) {
            $fixtureByRank[(int) $row['rank']] = $row;
        }

        $bands = $this->bands();

        foreach (['top25', 'top50', 'top100'] as $band) {
            foreach ($bands[$band] as $row) {
                $this->assertArrayHasKey(
                    $row['rank'],
                    $fixtureByRank,
                    "{$band} rank {$row['rank']} has no matching fixture row"
                );

                $fixtureRow = $fixtureByRank[$row['rank']];
                $this->assertSame($fixtureRow['root_iast'], $row['iast'], "{$band} rank {$row['rank']} iast drifted from fixture");
                $this->assertSame($fixtureRow['root_devanagari'], $row['devanagari'], "{$band} rank {$row['rank']} devanagari drifted from fixture");
                $this->assertSame($fixtureRow['top_form'], $row['top_form'], "{$band} rank {$row['rank']} top_form drifted from fixture");
                $this->assertSame($fixtureRow['gloss_ru'], $row['gloss_ru'], "{$band} rank {$row['rank']} gloss_ru drifted from fixture");
            }
        }
    }
}
