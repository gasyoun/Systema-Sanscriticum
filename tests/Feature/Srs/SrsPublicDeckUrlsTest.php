<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-deck shareable URLs + guest trial + language-aware tagline.
 */
class SrsPublicDeckUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';

        parent::setUp();
        config(['srs.enabled' => true, 'srs.guest_trial_cards' => 3]);
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    private function makeDeck(string $slug, string $language, string $name, int $cards = 5): SrsDeck
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'basic_'.$language],
            [
                'name' => $language === 'hi' ? 'Hindi' : 'Sanskrit',
                'language' => $language,
                'fields' => ['devanagari', 'iast', 'translation'],
            ]
        );

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => $name,
            'slug' => $slug,
            'language' => $language,
            'visibility' => 'system',
        ]);

        for ($i = 1; $i <= $cards; $i++) {
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => [
                    'iast' => "word{$i}",
                    'translation' => "перевод{$i}",
                ],
            ]);
        }

        return $deck;
    }

    public function test_public_routes_registered_when_enabled(): void
    {
        $this->assertTrue(app('router')->has('srs.index'));
        $this->assertTrue(app('router')->has('srs.deck'));
        $this->assertTrue(app('router')->has('student.srs.deck'));
    }

    public function test_guest_can_open_public_hub_and_deck(): void
    {
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertSee('Попробовать');

        $this->get('/koloda/sanskrit-core')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertSee('учите санскрит')
            ->assertSee('Пробный режим')
            ->assertSee('word1');
    }

    public function test_hindi_deck_tagline_does_not_say_sanskrit(): void
    {
        $this->makeDeck('hindi-core', 'hi', 'Hindi Core 100');

        $this->get('/koloda/hindi-core')
            ->assertOk()
            ->assertSee('учите хинди')
            ->assertDontSee('учите санскрит');
    }

    public function test_guest_trial_grades_without_persisting(): void
    {
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро', cards: 5);

        Livewire::test(SrsReview::class, ['slug' => 'sanskrit-core'])
            ->assertSet('isGuest', true)
            ->assertSee('word1')
            ->call('reveal')
            ->call('grade', 3)
            ->assertSet('guestGraded', 1)
            ->assertSee('word2');

        $this->assertDatabaseCount('srs_review_states', 0);
        $this->assertDatabaseCount('srs_review_logs', 0);
    }

    public function test_guest_hits_register_wall_after_trial_limit(): void
    {
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро', cards: 5);

        $lw = Livewire::test(SrsReview::class, ['slug' => 'sanskrit-core']);
        for ($i = 0; $i < 3; $i++) {
            $lw->call('reveal')->call('grade', 3);
        }

        $lw->assertSee('Пробные карточки закончились')
            ->assertSee('Войти или зарегистрироваться');
    }

    public function test_auth_user_opens_cabinet_deck_url(): void
    {
        $user = User::factory()->create();
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');

        $this->actingAs($user)
            ->get('/dvaram/koloda/sanskrit-core')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertSee('учите санскрит')
            ->assertDontSee('Пробный режим');
    }

    public function test_cabinet_hub_lists_deck_links(): void
    {
        $user = User::factory()->create();
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');
        $this->makeDeck('hindi-core', 'hi', 'Hindi Core 100');

        $this->actingAs($user)
            ->get('/dvaram/koloda?lang=all')
            ->assertOk()
            ->assertSee('/dvaram/koloda/sanskrit-core')
            ->assertSee('/dvaram/koloda/hindi-core')
            ->assertSee('Санскрит — ядро')
            ->assertSee('Hindi Core 100');
    }

    public function test_private_deck_not_visible_to_guest(): void
    {
        $owner = User::factory()->create();
        $noteType = SrsNoteType::create([
            'key' => 'private_nt',
            'name' => 'Private',
            'language' => 'sa',
            'fields' => ['iast', 'translation'],
        ]);
        SrsDeck::create([
            'user_id' => $owner->id,
            'note_type_id' => $noteType->id,
            'name' => 'Secret deck',
            'slug' => 'secret-deck',
            'language' => 'sa',
            'visibility' => 'private',
        ]);

        $this->get('/koloda/secret-deck')->assertNotFound();
    }

    public function test_legacy_srs_urls_redirect_to_koloda(): void
    {
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');

        $this->get('/srs')
            ->assertRedirect('/koloda');

        $this->get('/srs/sanskrit-core')
            ->assertRedirect('/koloda/sanskrit-core');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/dvaram/srs')
            ->assertRedirect('/dvaram/koloda');

        $this->actingAs($user)
            ->get('/dvaram/srs/sanskrit-core')
            ->assertRedirect('/dvaram/koloda/sanskrit-core');
    }

    public function test_public_hub_filters_by_language(): void
    {
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');
        $this->makeDeck('hindi-core', 'hi', 'Hindi Core 100');

        // default sa: hindi hidden
        $this->get('/koloda')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertDontSee('Hindi Core 100')

        $this->get('/koloda?lang=hi')
            ->assertOk()
            ->assertSee('Hindi Core 100')
            ->assertDontSee('Санскрит — ядро');

        $this->get('/koloda?lang=all')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertSee('Hindi Core 100');
    }

    public function test_cabinet_hub_filters_by_language(): void
    {
        $user = User::factory()->create();
        $this->makeDeck('sanskrit-core', 'sa', 'Санскрит — ядро');
        $this->makeDeck('hindi-core', 'hi', 'Hindi Core 100');

        $this->actingAs($user)
            ->get('/dvaram/koloda')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertDontSee('Hindi Core 100');

        $this->actingAs($user)
            ->get('/dvaram/koloda?lang=all')
            ->assertOk()
            ->assertSee('Санскрит — ядро')
            ->assertSee('Hindi Core 100');
    }

    public function test_null_language_counts_as_sa(): void
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'basic_sa'],
            [
                'name' => 'Sanskrit',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation'],
            ]
        );
        SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Legacy null-lang',
            'slug' => 'legacy-null',
            'language' => null,
            'visibility' => 'system',
        ]);
        $this->makeDeck('hindi-core', 'hi', 'Hindi Core 100');

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('Legacy null-lang')
            ->assertDontSee('Hindi Core 100');
    }
}
