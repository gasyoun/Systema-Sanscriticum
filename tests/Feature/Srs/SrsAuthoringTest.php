<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Filament\Resources\SrsCardResource;
use App\Filament\Resources\SrsDeckResource;
use App\Filament\Resources\SrsDeckResource\Pages\EditSrsDeck;
use App\Http\Controllers\SrsController;
use App\Livewire\SrsDeckEditor;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Wave 2 authoring (H1487): teacher Filament resources gate + seed/paste,
 * student private-deck Livewire editor.
 */
class SrsAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Route registration for /dvaram/koloda/* is gated on config at boot
        // (see routes/web.php) — same pattern as SrsFlagOverrideTest / SrsReviewTest.
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';

        parent::setUp();
        config(['srs.enabled' => true]);
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    private function makeNoteType(): SrsNoteType
    {
        return SrsNoteType::create([
            'key' => 'sanskrit_basic',
            'name' => 'Санскрит',
            'language' => 'sa',
            'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
        ]);
    }

    private function makeDeck(?User $owner = null, string $visibility = 'public'): SrsDeck
    {
        return SrsDeck::create([
            'user_id' => $owner?->id,
            'note_type_id' => $this->makeNoteType()->id,
            'name' => 'Test deck',
            'slug' => 'test-deck-'.uniqid(),
            'language' => 'sa',
            'visibility' => $visibility,
        ]);
    }

    public function test_filament_resources_hidden_when_flag_off(): void
    {
        config(['srs.enabled' => false]);

        $this->assertFalse(SrsDeckResource::canViewAny());
        $this->assertFalse(SrsCardResource::canViewAny());
        $this->assertFalse(SrsDeckResource::shouldRegisterNavigation());
        $this->assertFalse(SrsCardResource::shouldRegisterNavigation());
    }

    public function test_filament_resources_visible_when_flag_on_for_admin_role_gate_shape(): void
    {
        // Without an authenticated admin RoleGate::adminOnly() is false;
        // with flag on the resources still require admin — not open to guests.
        $this->assertFalse(SrsDeckResource::canViewAny());

        config(['srs.enabled' => true]);
        // Flag alone is not enough without admin role.
        $this->assertFalse(SrsDeckResource::canViewAny());
    }

    public function test_seed_from_dictionary_creates_cards_idempotently(): void
    {
        $deck = $this->makeDeck();
        $dict = Dictionary::create(['name' => 'Test', 'is_active' => true]);
        $word = DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्य',
            'iast' => 'satya',
            'cyrillic' => 'сатья',
            'translation' => 'истина',
        ]);
        DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => '',
            'iast' => '',
            'cyrillic' => 'пусто',
            'translation' => 'skip me — no script',
        ]);

        $page = new EditSrsDeck;
        $first = $page->seedDeckFromDictionary($deck, $dict->id, true);
        $second = $page->seedDeckFromDictionary($deck, $dict->id, true);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseHas('srs_cards', [
            'deck_id' => $deck->id,
            'source_word_id' => $word->id,
        ]);
        $this->assertSame(1, SrsCard::where('deck_id', $deck->id)->count());
    }

    public function test_paste_import_parses_pipe_lines(): void
    {
        $deck = $this->makeDeck();
        $page = new EditSrsDeck;

        $created = $page->importPasteLines($deck, "सत्य | satya | сатья | истина\n# comment\nbad\nनमः | namas | намас | поклон\n");

        $this->assertSame(2, $created);
        $this->assertSame(2, SrsCard::where('deck_id', $deck->id)->count());
        $this->assertDatabaseHas('srs_cards', ['deck_id' => $deck->id]);
    }

    public function test_student_deck_editor_gated_when_flag_disabled(): void
    {
        config(['srs.enabled' => false]);
        $user = User::factory()->create();

        // Livewire testing surface: assertStatus(404), same as SrsReviewTest.
        Livewire::actingAs($user)->test(SrsDeckEditor::class)->assertStatus(404);
    }

    public function test_student_decks_page_aborts_when_flag_disabled(): void
    {
        config(['srs.enabled' => false]);

        $this->expectException(NotFoundHttpException::class);
        (new SrsController)->decks();
    }

    public function test_student_can_create_private_deck_and_add_card(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SrsDeckEditor::class)
            ->set('newDeckName', 'Моя лексика')
            ->call('createDeck')
            ->assertSet('message', 'Колода создана.')
            ->set('cardDevanagari', 'धर्म')
            ->set('cardIast', 'dharma')
            ->set('cardTranslation', 'долг')
            ->call('addCard')
            ->assertSet('message', 'Карточка добавлена.')
            ->assertSee('धर्म')
            ->assertSee('долг');

        $this->assertDatabaseHas('srs_decks', [
            'user_id' => $user->id,
            'name' => 'Моя лексика',
            'visibility' => 'private',
        ]);
        $this->assertSame(1, SrsCard::count());
    }

    public function test_owner_can_edit_deck_slug(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SrsDeckEditor::class)
            ->set('newDeckName', 'Моя лексика')
            ->call('createDeck')
            ->set('editSlug', 'my-vocab-deck')
            ->call('updateSlug')
            ->assertSet('message', 'Адрес колоды обновлён: /dvaram/koloda/my-vocab-deck')
            ->assertSet('editSlug', 'my-vocab-deck');

        $this->assertDatabaseHas('srs_decks', [
            'user_id' => $user->id,
            'slug' => 'my-vocab-deck',
        ]);
    }

    public function test_owner_slug_rejects_reserved_and_duplicate(): void
    {
        $user = User::factory()->create();
        $noteType = $this->makeNoteType();
        SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'System',
            'slug' => 'taken-slug',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        Livewire::actingAs($user)
            ->test(SrsDeckEditor::class)
            ->set('newDeckName', 'Моя')
            ->call('createDeck')
            ->set('editSlug', 'stats')
            ->call('updateSlug')
            ->assertSet('error', 'Этот slug зарезервирован. Выберите другой.')
            ->set('editSlug', 'taken-slug')
            ->call('updateSlug')
            ->assertSet('error', 'Такой slug уже занят другой колодой.');
    }

    public function test_student_paste_bulk_and_cannot_see_others_decks(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $noteType = $this->makeNoteType();

        $private = SrsDeck::create([
            'user_id' => $owner->id,
            'note_type_id' => $noteType->id,
            'name' => 'Private A',
            'slug' => 'private-a',
            'language' => 'sa',
            'visibility' => 'private',
        ]);

        Livewire::actingAs($owner)
            ->test(SrsDeckEditor::class)
            ->assertSet('deckId', $private->id)
            ->set('pasteBulk', "राम | rāma | рама | Рама\nसीता | sītā | сита | Сита")
            ->call('importPaste')
            ->assertSee('Вставлено карточек: 2');

        $this->assertSame(2, SrsCard::where('deck_id', $private->id)->count());

        // Stranger mounts without seeing the other's deck id as selected content.
        Livewire::actingAs($stranger)
            ->test(SrsDeckEditor::class)
            ->assertSet('deckId', null)
            ->assertDontSee('Private A');
    }

    public function test_student_decks_route_ok_when_flag_on(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.srs.decks'))
            ->assertOk()
            ->assertSeeLivewire(SrsDeckEditor::class);
    }
}
