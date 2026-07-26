<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ACCEPT gates for the PWG Arzamas longread (H1620, VERIFICATION A1/A2/A6/A10):
 * import is idempotent, the published page renders with >= 15 h2 chapters,
 * and the Materials hub surfaces the card.
 *
 * Runs against the real repo pack under docs/materials/pwg-arzamas/ — the same
 * payload production imports — so a broken build_body.py output fails CI here.
 */
class PwgArzamasMaterialTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'peterburgskiy-slovar-pwg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('materials:import-pwg-arzamas')->assertSuccessful();
        $this->artisan('materials:import-pwg-arzamas')->assertSuccessful();

        $this->assertSame(1, Article::where('slug', self::SLUG)->count());
        $this->assertFalse(Article::where('slug', self::SLUG)->sole()->is_published);
    }

    public function test_reimport_does_not_unpublish(): void
    {
        $this->artisan('materials:import-pwg-arzamas', ['--publish' => true])->assertSuccessful();
        $this->artisan('materials:import-pwg-arzamas')->assertSuccessful();

        $this->assertTrue(Article::where('slug', self::SLUG)->sole()->is_published);
    }

    public function test_draft_is_hidden_from_guests(): void
    {
        $this->artisan('materials:import-pwg-arzamas')->assertSuccessful();

        $this->get('/s/'.self::SLUG)->assertNotFound();
    }

    public function test_published_page_renders_with_15_plus_chapters(): void
    {
        $this->artisan('materials:import-pwg-arzamas', ['--publish' => true])->assertSuccessful();

        $article = Article::where('slug', self::SLUG)->sole();
        $this->assertGreaterThanOrEqual(15, substr_count($article->body, '<h2'), 'ACCEPT A1: >= 15 h2 chapters');
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
        $this->artisan('materials:import-pwg-arzamas', ['--publish' => true])->assertSuccessful();

        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee(Article::where('slug', self::SLUG)->sole()->title);
    }
}
