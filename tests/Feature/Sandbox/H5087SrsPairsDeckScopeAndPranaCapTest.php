<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5087 regression · app/Livewire/SrsReview.php:finishPairsSession-unscoped-card-lookup.
 *
 * Same tampered-snapshot attack as the NV-03 proof, assertions flipped to the
 * fixed invariant: pairs-mode grading resolves cards ONLY inside the
 * access-checked deck (foreign private cards are not graded, no prana), and
 * srs_review awards stop at the per-day cap.
 */
class H5087SrsPairsDeckScopeAndPranaCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    /** @return array{User, User, SrsCard, SrsDeck} attacker, victim, victimCard, attackerDeck */
    private function fixture(): array
    {
        $noteType = SrsNoteType::create([
            'key' => 'h5087', 'name' => 'H5087', 'language' => 'sa', 'fields' => ['iast', 'translation_ru'],
        ]);

        $attacker = User::factory()->create();
        $ownDeck = SrsDeck::create([
            'user_id' => $attacker->id,
            'note_type_id' => $noteType->id,
            'name' => 'Колода атакующего',
            'slug' => 'attacker-deck',
            'language' => 'sa',
            'visibility' => 'private',
        ]);

        $victim = User::factory()->create();
        $victimDeck = SrsDeck::create([
            'user_id' => $victim->id,
            'note_type_id' => $noteType->id,
            'name' => 'Приватная колода жертвы',
            'slug' => 'victim-deck',
            'language' => 'sa',
            'visibility' => 'private',
        ]);

        $victimCard = SrsCard::create([
            'deck_id' => $victimDeck->id,
            'direction' => 'front_back',
            'fields' => ['iast' => 'agni', 'translation_ru' => 'огонь'],
        ]);

        return [$attacker, $victim, $victimCard, $ownDeck];
    }

    /** @test */
    public function tampered_pair_left_cannot_grade_a_foreign_private_deck_card(): void
    {
        [$attacker, , $victimCard] = $this->fixture();

        $balanceBefore = (int) $attacker->fresh()->prana_balance;

        Livewire::actingAs($attacker)
            ->test(SrsReview::class, ['slug' => 'attacker-deck'])
            ->set('mode', 'pairs')
            ->set('pairLeft', [['id' => $victimCard->id, 'text' => 'agni', 'answer' => 'огонь']])
            ->set('pairSelectedLeft', $victimCard->id)
            ->call('selectPairRight', $victimCard->id)
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('srs_review_logs')->where('card_id', $victimCard->id)->count(),
            'no log row for the foreign deck card'
        );
        $this->assertSame(
            0,
            DB::table('srs_review_states')->where('user_id', $attacker->id)->where('card_id', $victimCard->id)->count(),
            'no forged state row'
        );
        $this->assertSame(
            $balanceBefore,
            (int) $attacker->fresh()->prana_balance,
            'no prana farmed from a foreign card'
        );
    }

    /** @test */
    public function control_same_deck_card_is_graded_normally(): void
    {
        [$attacker, , , $deck] = $this->fixture();
        $balanceBefore = (int) $attacker->fresh()->prana_balance;
        $card = SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => ['iast' => 'vayu', 'translation_ru' => 'ветер'],
        ]);

        Livewire::actingAs($attacker)
            ->test(SrsReview::class, ['slug' => $deck->slug])
            ->set('mode', 'pairs')
            ->set('pairLeft', [['id' => $card->id, 'text' => 'vayu', 'answer' => 'ветер']])
            ->set('pairSelectedLeft', $card->id)
            ->call('selectPairRight', $card->id)
            ->assertOk();

        $this->assertSame(1, DB::table('srs_review_logs')->where('card_id', $card->id)->count());
        $this->assertSame(
            $balanceBefore + 3,
            (int) $attacker->fresh()->prana_balance,
            'own-deck grading still pays the srs_review award'
        );
    }

    /** @test */
    public function srs_review_awards_stop_at_the_daily_cap(): void
    {
        config(['prana.srs_review_daily_cap' => 1]);
        [$attacker, , , $deck] = $this->fixture();

        $first = SrsCard::create([
            'deck_id' => $deck->id, 'direction' => 'front_back',
            'fields' => ['iast' => 'one', 'translation_ru' => 'один'],
        ]);
        $second = SrsCard::create([
            'deck_id' => $deck->id, 'direction' => 'front_back',
            'fields' => ['iast' => 'two', 'translation_ru' => 'два'],
        ]);

        $start = (int) $attacker->fresh()->prana_balance;

        Livewire::actingAs($attacker)
            ->test(SrsReview::class, ['slug' => $deck->slug])
            ->set('mode', 'pairs')
            ->set('pairLeft', [
                ['id' => $first->id, 'text' => 'one', 'answer' => 'один'],
                ['id' => $second->id, 'text' => 'two', 'answer' => 'два'],
            ])
            ->set('pairSelectedLeft', $first->id)
            ->call('selectPairRight', $first->id)
            ->set('pairSelectedLeft', $second->id)
            ->call('selectPairRight', $second->id) // all pairs matched -> session grades both cards
            ->assertOk();

        // Both cards graded (2 log rows — reviews themselves are uncapped)…
        $this->assertSame(1, DB::table('srs_review_logs')->where('card_id', $first->id)->count());
        $this->assertSame(1, DB::table('srs_review_logs')->where('card_id', $second->id)->count());

        // …but only cap=1 award paid out.
        $this->assertSame(
            $start + 3,
            (int) $attacker->fresh()->prana_balance,
            'awards stop at the per-day cap'
        );
    }
}
