<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Tests\TestCase;

/**
 * H5154 (MG 18-09-2026) — cabinet Metrika goals registry. The config map is
 * the single source of truth for which cabinet goals exist in counter
 * 106964341 (ids 639422183–639422234 created via management API, PR #2700);
 * this pins the Tier-1 set and the first_cabinet_action flip.
 */
class CabinetMetrikaGoalsConfigTest extends TestCase
{
    /** All 10 Tier-1 cabinet goals from docs/METRIKA_GOALS_SHOP_CABINET_2026-09-18.md. */
    private const CABINET_TIER_1 = [
        'cabinet_home_view',
        'lesson_open',
        'lesson_complete',
        'lesson_mark_mastered',
        'library_shelf_view',
        'path_station_view',
        'access_renewal_start',
        'access_renewal_complete',
        'zoom_join_click',
        'first_cabinet_action',
    ];

    public function test_every_tier_1_cabinet_goal_is_registered_with_a_metrika_goal(): void
    {
        $funnel = config('analytics.funnel_events');

        foreach (self::CABINET_TIER_1 as $goal) {
            $this->assertArrayHasKey($goal, $funnel, "funnel_events must carry {$goal}");
            $this->assertSame(
                $goal,
                $funnel[$goal]['metrika_goal'],
                "{$goal}.metrika_goal must match the goal name created in Metrika"
            );
        }
    }

    public function test_first_cabinet_action_flip_is_not_null(): void
    {
        // MG 18-09-2026: metrika_goal was null by the old «кабинет не тегируем»
        // default — the flip to the goal name is the activation goal going live.
        $this->assertSame(
            'first_cabinet_action',
            config('analytics.funnel_events.first_cabinet_action.metrika_goal')
        );
    }

    public function test_tier_2_client_events_have_no_premature_metrika_goals(): void
    {
        // Tier 2 (offer.*, cabinet.continue.click, course.tab.view…) must NOT
        // appear in funnel_events until the H4134 emitter defect is fixed —
        // a registered goal with a lost emitter reads a lying zero.
        $funnel = (array) config('analytics.funnel_events');

        foreach (['offer_impression', 'offer_click', 'cabinet_continue_click', 'course_tab_view'] as $tier2) {
            $this->assertArrayNotHasKey($tier2, $funnel, "{$tier2} must stay out of funnel_events until Tier 2");
        }
    }
}
