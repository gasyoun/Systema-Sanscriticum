<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3313: tamperable Livewire deckId закрыт ownership-гейтом.
 *
 * Чужая приватная колода недоступна ни через payload-tamper (#[Locked] —
 * клиентский апдейт свойства отклоняется), ни через selectDeck/currentDeck
 * (403 / пустой рендер без карточек). Легитимный ревью своей колоды и
 * гостевой пробный режим работают как раньше.
 */
class SrsAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    private function makePrivateDeckWithSecretCard(User $owner): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'secret_nt',
            'name' => 'Private',
            'language' => 'sa',
            'fields' => ['iast', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'user_id' => $owner->id,
            'note_type_id' => $noteType->id,
            'name' => 'Secret deck',
            'slug' => 'secret-deck',
            'language' => 'sa',
            'visibility' => 'private',
        ]);

        SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => [
                'iast' => 'rahasiya-pada',
                'translation' => 'секретный-перевод',
            ],
        ]);

        return $deck;
    }

    private function makeSystemDeck(bool $withCard = true): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic_ac',
            'name' => 'Санскрит — ядро',
            'language' => 'sa',
            'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Санскрит — ядро',
            'slug' => 'sanskrit-core-ac',
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

    public function test_client_cannot_overwrite_locked_deck_id(): void
    {
        $victim = User::factory()->create();
        $this->makePrivateDeckWithSecretCard($victim);
        $attacker = User::factory()->create();
        $this->makeSystemDeck();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($attacker)->test(SrsReview::class)
            ->set('deckId', (int) SrsDeck::where('slug', 'secret-deck')->value('id'));
    }

    public function test_select_deck_with_foreign_private_deck_aborts_403(): void
    {
        $victim = User::factory()->create();
        $victimDeck = $this->makePrivateDeckWithSecretCard($victim);
        $attacker = User::factory()->create();
        $this->makeSystemDeck();

        Livewire::actingAs($attacker)->test(SrsReview::class)
            ->call('selectDeck', $victimDeck->id)
            ->assertStatus(403);
    }

    public function test_current_deck_gate_returns_null_for_foreign_private_deck(): void
    {
        // Defense-in-depth (H3313): даже если deckId подменён «серверно», минуя
        // #[Locked] (hydrate-байпас), currentDeck() отдаёт чужую приватную
        // колоду только владельцу — рендер и грейд получают null.
        $victim = User::factory()->create();
        $victimDeck = $this->makePrivateDeckWithSecretCard($victim);

        $component = new SrsReview;
        $component->deckId = $victimDeck->id;

        $gate = new \ReflectionMethod(SrsReview::class, 'currentDeck');

        // Без аутентификации (гость) — null.
        $this->assertNull($gate->invoke($component));

        // Чужой пользователь — null.
        $this->actingAs(User::factory()->create());
        $this->assertNull($gate->invoke($component));

        // Владелец — своя колода проходит.
        $this->actingAs($victim);
        $this->assertSame($victimDeck->id, $gate->invoke($component)?->id);
    }

    public function test_current_deck_gate_keeps_guest_access_to_public_decks(): void
    {
        // Легитимный гостевой путь не сломан: system/public колода доступна.
        $deck = $this->makeSystemDeck();

        $component = new SrsReview;
        $component->deckId = $deck->id;

        $gate = new \ReflectionMethod(SrsReview::class, 'currentDeck');

        $this->assertSame($deck->id, $gate->invoke($component)?->id);
    }

    public function test_owner_still_reviews_own_private_deck(): void
    {
        $owner = User::factory()->create();
        $this->makePrivateDeckWithSecretCard($owner);

        Livewire::actingAs($owner)->test(SrsReview::class, ['slug' => 'secret-deck'])
            ->assertSet('isGuest', false)
            ->assertSee('rahasiya-pada')
            ->call('reveal')
            ->assertSee('секретный-перевод');
    }

    public function test_guest_trial_on_public_deck_unchanged(): void
    {
        $this->makeSystemDeck();

        Livewire::test(SrsReview::class)
            ->assertSet('isGuest', true)
            ->assertSee('सत्य')
            ->call('reveal')
            ->call('grade', 3)
            ->assertSet('guestGraded', 1);

        $this->assertDatabaseCount('srs_review_states', 0);
    }

    public function test_guest_cannot_open_or_select_foreign_private_deck(): void
    {
        $victim = User::factory()->create();
        $victimDeck = $this->makePrivateDeckWithSecretCard($victim);

        Livewire::test(SrsReview::class, ['slug' => 'secret-deck'])
            ->assertStatus(404);

        $this->makeSystemDeck();

        Livewire::test(SrsReview::class)
            ->call('selectDeck', $victimDeck->id)
            ->assertStatus(403);
    }
}
