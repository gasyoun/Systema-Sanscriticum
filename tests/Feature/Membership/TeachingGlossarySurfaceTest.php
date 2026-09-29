<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\TeachingGlossaryCourse;
use App\Models\TeachingGlossaryTerm;
use App\Models\User;
use App\Services\Membership\ClubMembershipService;

/**
 * Поверхность тира Top «Преподавательский глоссарий» (H4832).
 *
 * Гейт двухключевой: флаг features.teaching_glossary + capability
 * `teaching_glossary` (минимум `top`). Прове­ряются ВСЕ отказные ветки:
 * гость, честный пользователь без членства, клубник, Top при выключенном
 * флаге — и одна зелёная: Top-член при включённом флаге.
 */
class TeachingGlossarySurfaceTest extends MembershipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TeachingGlossaryTerm::query()->create([
            'cyrillic_form' => 'вишну',
            'lemma_slp1' => 'vizRu',
            'ru_gloss' => 'Вишну',
            'corpus_freq' => 4303,
            'n_files' => 441,
            'n_courses' => 36,
            'ambiguous_lemmas' => false,
            'gloss_provenance' => 'agent',
        ]);

        TeachingGlossaryTerm::query()->create([
            'cyrillic_form' => 'атман',
            'lemma_slp1' => 'Atman atman',
            'ru_gloss' => 'себя; Атман',
            'corpus_freq' => 3950,
            'n_files' => 279,
            'n_courses' => 16,
            'ambiguous_lemmas' => true,
            'gloss_provenance' => 'human-pending',
        ]);

        TeachingGlossaryCourse::query()->create([
            'course' => 'Бюлероведение 2023-2024',
            'n_headwords' => 595,
            'top_terms' => 'вишну риши брахма',
        ]);
    }

    private function enableSurface(): void
    {
        config()->set('features.teaching_glossary', true);
        config()->set('features.membership_top', true);
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_advanced_features', true);
    }

    private function memberOfTier(MembershipTier $tier): User
    {
        $user = User::factory()->create();
        app(ClubMembershipService::class)->grantManualPeriod($user, 1, 'manual', $tier);

        return $user->fresh();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->enableSurface();

        $this->get(route('cabinet.teaching-glossary'))
            ->assertRedirect(route('login'));
    }

    public function test_surface_is_404_while_flag_is_off_even_for_top_member(): void
    {
        config()->set('features.membership_top', true);
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_advanced_features', true);
        // features.teaching_glossary остаётся OFF — поверхность ещё не включена MG.

        $top = $this->memberOfTier(MembershipTier::Top);

        $this->actingAs($top)
            ->get(route('cabinet.teaching-glossary'))
            ->assertNotFound();
    }

    public function test_authenticated_user_without_membership_is_404(): void
    {
        $this->enableSurface();

        $plain = User::factory()->create();

        $this->actingAs($plain->fresh())
            ->get(route('cabinet.teaching-glossary'))
            ->assertNotFound();
    }

    public function test_basic_member_is_404_surface_is_top_only(): void
    {
        $this->enableSurface();

        $basic = $this->memberOfTier(MembershipTier::Basic);

        $this->actingAs($basic)
            ->get(route('cabinet.teaching-glossary'))
            ->assertNotFound();
    }

    public function test_club_member_is_404(): void
    {
        $this->enableSurface();

        $club = $this->memberOfTier(MembershipTier::Club);

        $this->actingAs($club)
            ->get(route('cabinet.teaching-glossary'))
            ->assertNotFound();
    }

    public function test_top_member_sees_the_glossary(): void
    {
        $this->enableSurface();

        $top = $this->memberOfTier(MembershipTier::Top);

        $this->actingAs($top)
            ->get(route('cabinet.teaching-glossary'))
            ->assertOk()
            ->assertSee('Преподавательский глоссарий')
            ->assertSee('вишну')
            ->assertSee('vizRu')
            ->assertSee('Атман')
            ->assertSee('неск. лемм')
            ->assertSee('Бюлероведение 2023-2024');
    }

    public function test_lapsed_top_member_is_404(): void
    {
        $this->enableSurface();

        $user = User::factory()->create();
        app(ClubMembershipService::class)->grantManualPeriod($user, 1, 'manual', MembershipTier::Top);
        // Грейс вышел: право снято, страница снова 404, как и у любого не-члена.
        ClubMembership::query()->where('user_id', $user->id)
            ->update(['ends_at' => now()->subDays(9), 'grace_until' => now()->subDays(6)]);

        $this->actingAs($user->fresh())
            ->get(route('cabinet.teaching-glossary'))
            ->assertNotFound();
    }

    public function test_search_filters_by_form_and_gloss(): void
    {
        $this->enableSurface();

        $top = $this->memberOfTier(MembershipTier::Top);

        $this->actingAs($top)
            ->get(route('cabinet.teaching-glossary', ['q' => 'вишну']))
            ->assertOk()
            ->assertSee('вишну')
            ->assertDontSee('себя; Атман');

        $this->actingAs($top)
            ->get(route('cabinet.teaching-glossary', ['q' => 'Атман']))
            ->assertOk()
            ->assertSee('атман');

        $this->actingAs($top)
            ->get(route('cabinet.teaching-glossary', ['q' => 'zxqw несуществующее']))
            ->assertOk()
            ->assertSee('Ничего не найдено');
    }
}
