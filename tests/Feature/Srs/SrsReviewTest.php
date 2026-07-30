<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Http\Controllers\SrsController;
use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SrsReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    private function makeSystemDeck(bool $withCard = true): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic',
            'name' => 'Санскрит',
            'language' => 'sa',
            'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Санскрит — ядро',
            'slug' => 'sanskrit-core',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        if ($withCard) {
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => [
                    'devanagari' => 'सत्य',
                    'iast' => 'satya',
                    'cyrillic' => 'сатья',
                    'translation' => 'истина',
                ],
            ]);
        }

        return $deck;
    }

    public function test_review_flow_reveal_then_grade_persists(): void
    {
        $user = User::factory()->create();
        $this->makeSystemDeck();

        Livewire::actingAs($user)->test(SrsReview::class)
            ->assertSet('revealed', false)
            ->assertSee('सत्य')          // лицо карточки — деванагари
            ->call('reveal')
            ->assertSet('revealed', true)
            ->assertSee('истина')        // ответ — перевод
            ->call('grade', 3)           // «Хорошо»
            ->assertSet('revealed', false);

        $this->assertDatabaseHas('srs_review_states', ['user_id' => $user->id]);
        $this->assertDatabaseHas('srs_review_logs', [
            'user_id' => $user->id,
            'rating' => 3,
        ]);
    }

    public function test_empty_state_when_deck_has_no_cards(): void
    {
        $user = User::factory()->create();
        $this->makeSystemDeck(withCard: false);

        Livewire::actingAs($user)->test(SrsReview::class)
            ->assertSee('На сегодня всё');
    }

    public function test_review_renders_audio_and_image_when_media_published(): void
    {
        $user = User::factory()->create();
        $noteType = SrsNoteType::create([
            'key' => 'anki_TESTMEDIA',
            'name' => 'Anki media',
            'language' => 'hi',
            'fields' => ['devanagari', 'iast', 'translation', 'audio', 'image'],
        ]);
        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Hindi media deck',
            'slug' => 'anki-TESTMEDIA-level-1',
            'language' => 'hi',
            'visibility' => 'system',
        ]);

        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('srs/anki_TESTMEDIA/clip.mp3', 'audio');
        \Illuminate\Support\Facades\Storage::disk('public')->put('srs/anki_TESTMEDIA/pic.jpg', 'img');

        SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => [
                'devanagari' => 'हफ्ता',
                'iast' => 'hafTaa',
                'translation' => 'week',
                'audio' => 'srs/anki_TESTMEDIA/clip.mp3',
                'image' => 'srs/anki_TESTMEDIA/pic.jpg',
            ],
        ]);

        Livewire::actingAs($user)->test(SrsReview::class)
            ->assertSee('हफ्ता')
            ->assertSee('hafTaa')
            ->assertSee('clip.mp3')
            ->assertSeeHtml('controls')
            ->call('reveal')
            ->assertSee('week')
            ->assertSee('pic.jpg')
            ->assertSeeHtml('<img');
    }

    public function test_review_page_aborts_when_flag_disabled(): void
    {
        config(['srs.enabled' => false]);

        $this->expectException(NotFoundHttpException::class);

        (new SrsController)->review();
    }

    public function test_component_gated_when_flag_disabled(): void
    {
        config(['srs.enabled' => false]);
        $user = User::factory()->create();

        // Livewire перехватывает abort(404) в mount и отдаёт статус 404, а не бросает.
        Livewire::actingAs($user)->test(SrsReview::class)->assertStatus(404);
    }
}
