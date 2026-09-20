<?php

declare(strict_types=1);

namespace Tests\Feature\Support\StudentAgent;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // H5193: гейт «платная подписка» читает клубное членство через
        // ClubEntitlement — контур должен быть включён и тирован, иначе
        // Free-грант неотличим от оплаты (activeTierFor вернул бы Club).
        config()->set('features.club_membership', true);
        config()->set('features.membership_tiered', true);
    }

    /** Период членства напрямую: сервис-путь H2644 здесь не тестируем. */
    private function grantTier(User $user, MembershipTier $tier, ?string $until = null): ClubMembership
    {
        $endsAt = $until !== null ? Carbon::parse($until) : now()->addMonth();

        return ClubMembership::create([
            'user_id' => $user->id,
            'payment_id' => null,
            'tier_code' => $tier,
            'term_months' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
            'grace_until' => $endsAt->copy()->addDays(3),
            'grace_days' => 3,
            'source' => $tier === MembershipTier::Free
                ? ClubMembership::SOURCE_GUEST_REGISTER
                : ClubMembership::SOURCE_MANUAL,
        ]);
    }

    public function test_route_404_when_flag_off(): void
    {
        config()->set('features.student_agent', false);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertNotFound();
    }

    public function test_route_requires_auth(): void
    {
        config()->set('features.student_agent', true);

        // JSON request through the `auth` middleware: 401, not a login redirect.
        $this->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertUnauthorized();
    }

    public function test_route_403_without_membership(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertForbidden();
    }

    public function test_route_403_for_free_tier_member(): void
    {
        // MG 20-09: ON for PAYING subscribers — Free-грант права не даёт.
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $this->grantTier($student, MembershipTier::Free);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertForbidden();
    }

    public function test_route_403_for_lapsed_membership(): void
    {
        // ends_at и grace_until в прошлом: active() больше не считает период живым.
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $this->grantTier($student, MembershipTier::Club, now()->subDays(10)->toDateString());

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertForbidden();
    }

    public function test_route_allows_paying_member_during_grace_window(): void
    {
        // Оплаченный период кончился, грейс ещё идёт — право живёт (active()).
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $membership = $this->grantTier($student, MembershipTier::Club, now()->subDay()->toDateString());
        $membership->update(['grace_until' => now()->addDays(2)]);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'free_chat', 'params' => []])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'tool_not_allowed']);
    }

    public function test_route_refuses_out_of_scope_tool_for_paying_member(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $this->grantTier($student, MembershipTier::Club);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'free_chat', 'params' => []])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'tool_not_allowed']);
    }

    public function test_route_runs_dictionary_lookup_for_club_member(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $this->grantTier($student, MembershipTier::Club);

        $dictionary = Dictionary::create(['name' => 'MW', 'is_active' => true]);
        DictionaryWord::create([
            'dictionary_id' => $dictionary->id,
            'devanagari' => 'अग्नि',
            'iast' => 'agni',
            'cyrillic' => 'агни',
            'translation' => 'огонь',
        ]);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.hits.0.iast', 'agni');
    }

    public function test_route_allows_basic_tier_member(): void
    {
        // Basic ₽1 000 — тоже платный уровень (тот же предикат, что у
        // RecordingAccessPolicy для открытых лекций).
        config()->set('features.student_agent', true);
        $student = User::factory()->create();
        $this->grantTier($student, MembershipTier::Basic);

        $dictionary = Dictionary::create(['name' => 'MW', 'is_active' => true]);
        DictionaryWord::create([
            'dictionary_id' => $dictionary->id,
            'devanagari' => 'अग्नि',
            'iast' => 'agni',
            'cyrillic' => 'агни',
            'translation' => 'огонь',
        ]);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
