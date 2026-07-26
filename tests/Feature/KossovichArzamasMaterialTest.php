<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gates for the second Arzamas longread «Россия и санскритский словарь»
 * (H1696): import is idempotent, the published page renders with >= 12 h2
 * chapters, and the Materials hub surfaces the card.
 *
 * Runs against the real repo pack under docs/materials/kossovich-arzamas/ —
 * the same payload production imports — so a broken build_body.py output
 * fails CI here.
 */
class KossovichArzamasMaterialTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'rossiya-i-sanskritskiy-slovar';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('materials:import-kossovich-arzamas')->assertSuccessful();
        $this->artisan('materials:import-kossovich-arzamas')->assertSuccessful();

        $this->assertSame(1, Article::where('slug', self::SLUG)->count());
        $this->assertFalse(Article::where('slug', self::SLUG)->sole()->is_published);
    }

    public function test_reimport_does_not_unpublish(): void
    {
        $this->artisan('materials:import-kossovich-arzamas', ['--publish' => true])->assertSuccessful();
        $this->artisan('materials:import-kossovich-arzamas')->assertSuccessful();

        $this->assertTrue(Article::where('slug', self::SLUG)->sole()->is_published);
    }

    public function test_draft_is_hidden_from_guests(): void
    {
        $this->artisan('materials:import-kossovich-arzamas')->assertSuccessful();

        $this->get('/s/'.self::SLUG)->assertNotFound();
    }

    public function test_published_page_renders_with_12_plus_chapters(): void
    {
        $this->artisan('materials:import-kossovich-arzamas', ['--publish' => true])->assertSuccessful();

        $article = Article::where('slug', self::SLUG)->sole();
        $this->assertGreaterThanOrEqual(12, substr_count($article->body, '<h2'), 'H1696 goal: >= 12 h2 chapters');
        $this->assertGreaterThanOrEqual(1, $article->reading_time);
        $this->assertSame('Dr. Mārcis Gasūns', $article->author_name);
        $this->assertNotNull($article->published_at);

        $this->get('/s/'.self::SLUG)
            ->assertOk()
            ->assertSee($article->title)
            ->assertSee('<h2>', false);
    }

    public function test_materials_hub_lists_the_card_when_published(): void
    {
        $this->artisan('materials:import-kossovich-arzamas', ['--publish' => true])->assertSuccessful();

        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee(Article::where('slug', self::SLUG)->sole()->title);
    }

    public function test_both_arzamas_pieces_coexist_and_crosslink(): void
    {
        $this->artisan('materials:import-pwg-arzamas', ['--publish' => true])->assertSuccessful();
        $this->artisan('materials:import-kossovich-arzamas', ['--publish' => true])->assertSuccessful();

        $this->assertSame(2, Article::whereIn('slug', [self::SLUG, 'peterburgskiy-slovar-pwg'])->count());

        // First piece ch. 16 links to this one; this one's lead links back.
        $this->assertStringContainsString('/s/'.self::SLUG,
            Article::where('slug', 'peterburgskiy-slovar-pwg')->sole()->body);
        $this->assertStringContainsString('/s/peterburgskiy-slovar-pwg',
            Article::where('slug', self::SLUG)->sole()->body);
    }
}
