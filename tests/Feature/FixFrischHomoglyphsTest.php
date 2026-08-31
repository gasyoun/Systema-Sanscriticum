<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\FixFrischHomoglyphs;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3762 residual / issue #2265 — the one-shot Фриш homoglyph repair.
 */
class FixFrischHomoglyphsTest extends TestCase
{
    use RefreshDatabase;

    private function makeFrischWord(int $id, array $attrs): DictionaryWord
    {
        $dict = Dictionary::firstOrCreate(['name' => 'Фриш'], ['is_active' => true]);

        $word = new DictionaryWord(array_merge(['dictionary_id' => $dict->id], $attrs));
        $word->id = $id;
        $word->save();

        return $word->fresh();
    }

    public function test_apply_repairs_all_columns_and_rederives_the_slug(): void
    {
        $this->makeFrischWord(112441, [
            'iast' => '/nīса/',
            'devanagari' => '/नीса/',
            'cyrillic' => '/ниса/',
            'translation' => 'низкий, пониженный',
        ]);

        $this->artisan('slovar:fix-frisch-homoglyphs', ['--apply' => true, '--only' => '112441'])
            ->assertExitCode(0);

        $word = DictionaryWord::find(112441);
        $this->assertSame('/nīca/', $word->iast);
        $this->assertSame('/नीच/', $word->devanagari);
        $this->assertSame('/нича/', $word->cyrillic);
        // Slug re-derived through the model's own helper: the corrupted «nisa» dies.
        $this->assertSame('nica', $word->slug);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->makeFrischWord(111988, [
            'iast' => '/drumа/',
            'devanagari' => '/द्रुम्а/',
            'cyrillic' => '/друма/',
            'translation' => 'дерево',
        ]);

        $this->artisan('slovar:fix-frisch-homoglyphs', ['--only' => '111988'])
            ->assertExitCode(0);

        $this->assertSame('/drumа/', DictionaryWord::find(111988)->iast);
    }

    public function test_drift_refuses_the_whole_batch(): void
    {
        // One row exactly as recorded…
        $this->makeFrischWord(111988, [
            'iast' => '/drumа/',
            'devanagari' => '/द्रुम्а/',
            'cyrillic' => '/друма/',
            'translation' => 'дерево',
        ]);
        // …and one already edited by someone else since the census.
        $this->makeFrischWord(112441, [
            'iast' => '/nīca/',
            'devanagari' => '/नीса/',
            'cyrillic' => '/ниса/',
            'translation' => 'низкий',
        ]);

        $this->artisan('slovar:fix-frisch-homoglyphs', ['--apply' => true, '--only' => '111988,112441'])
            ->assertExitCode(1);

        // The clean row was NOT half-applied.
        $this->assertSame('/drumа/', DictionaryWord::find(111988)->iast);
    }

    public function test_missing_row_refuses(): void
    {
        $this->artisan('slovar:fix-frisch-homoglyphs', ['--apply' => true, '--only' => '113326'])
            ->assertExitCode(1);
    }

    public function test_fix_table_excludes_the_two_undecided_b_rows(): void
    {
        $this->assertArrayNotHasKey(112501, FixFrischHomoglyphs::FIXES);
        $this->assertArrayNotHasKey(115974, FixFrischHomoglyphs::FIXES);
        $this->assertCount(14, FixFrischHomoglyphs::FIXES);
    }

    public function test_repaired_headword_gets_a_cdsl_block(): void
    {
        $this->makeFrischWord(111988, [
            'iast' => '/drumа/',
            'devanagari' => '/द्रुम्а/',
            'cyrillic' => '/друма/',
            'translation' => 'дерево',
        ]);

        $this->artisan('slovar:fix-frisch-homoglyphs', ['--apply' => true, '--only' => '111988'])
            ->assertExitCode(0);

        // Cyrillic-homoglyph dirt used to suppress the CDSL link-out; the repair unlocks it.
        $this->get('/slovar/druma')->assertOk()
            ->assertSee('indexcaller.php?key=druma&amp;transLit=slp1&amp;filter=roman', false);
    }
}
