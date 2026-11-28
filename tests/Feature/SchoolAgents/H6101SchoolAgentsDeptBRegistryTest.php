<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolAgents;

use App\Services\Support\EnrollContactBinder;
use App\Services\Support\HumanSend\SupportHumanSendService;
use App\Services\Support\SupportStudentPiiMasker;
use Tests\TestCase;

/**
 * H6101 — школа-агенты, департамент B «Продажи и онбординг» (роли 13-22)
 * — фаза-1 реестр по ростеру v2 (эпик E026).
 *
 * Инварианты гейтов решениями 05-10-2026 (Q2/Q3/Q5/Q6/Q7/Q9) и
 * разделения scope пакета Hermes 06-10: W1-роли (13/19/20) child-owned
 * (H6184/H6186/H6185), зонтик второй вариант не пишет; 0 прод-письма
 * до гейта волны (H6084). Тест читает только config, без БД и сети.
 */
class H6101SchoolAgentsDeptBRegistryTest extends TestCase
{
    private const W1_CHILD_HANDOFFS = ['H6184', 'H6185', 'H6186'];

    private const DEPARTMENT_B_IDS = [13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

    public function test_registry_loads_and_holds_exactly_ten_department_b_roles(): void
    {
        $registry = config('school_agents_dept_b');

        $this->assertNotNull($registry, 'config/school_agents_dept_b.php must be loadable');
        $this->assertSame('B', $registry['department']);
        $this->assertSame(25, $registry['phase1_quota']);

        $ids = array_column($registry['roles'], 'id');
        $this->assertCount(10, $ids, 'dept B roster v1 = 10 roles (13-22)');
        $this->assertSame(self::DEPARTMENT_B_IDS, $ids, 'canonical IDs 13-22, in order, no duplicates');
    }

    public function test_every_role_sits_behind_all_phase1_gates_and_writes_no_prod(): void
    {
        $registry = config('school_agents_dept_b');

        $this->assertFalse($registry['prod_write_enabled'], '0 prod messages before the wave gate (H6084)');

        foreach ($registry['roles'] as $role) {
            $label = sprintf('role %d %s', $role['id'], $role['slug']);

            // Q3 suggester: агент готовит — человек отправляет.
            $this->assertTrue($role['gates']['human_send_required'], "Q3 human-send: {$label}");
            // Q5 pii-guard фаза 0: без обёртки роль не запускается.
            $this->assertTrue($role['gates']['pii_guard_required'], "Q5 pii-guard: {$label}");
            // Q6 скидки: полный запрет автономии — только предлагать, решает MG.
            $this->assertFalse($role['gates']['discount_autonomy_allowed'], "Q6 no-discount autonomy: {$label}");
            // Q9 голос: исходящие через tone-гайд.
            $this->assertTrue($role['gates']['tone_guide_required'], "Q9 tone-guide: {$label}");
            // Q7 токены: killgate-слой + лимит по роли (0 = детерминированная роль без LLM).
            $this->assertIsInt($role['token_budget_daily']);
            $this->assertGreaterThanOrEqual(0, $role['token_budget_daily'], "Q7 token budget: {$label}");

            // Ни одна роль фазы-1 не статуса «живая».
            $this->assertContains($role['status'], ['reserved', 'child'], "nothing live: {$label}");
            $this->assertNotSame('enabled', $role['status'], "nothing live: {$label}");
        }
    }

    public function test_deterministic_roles_carry_zero_llm_budget_hybrid_stays_small(): void
    {
        foreach (config('school_agents_dept_b.roles') as $role) {
            if ($role['type'] === 'deterministic') {
                $this->assertSame(
                    0,
                    $role['token_budget_daily'],
                    "role {$role['id']} is deterministic — no LLM spend (Q7 «без новых трат»)"
                );
            } else {
                $this->assertGreaterThan(
                    0,
                    $role['token_budget_daily'],
                    "role {$role['id']} needs a positive token budget"
                );
            }
        }
    }

    public function test_w1_roles_are_child_owned_and_umbrella_does_not_duplicate_them(): void
    {
        $w1 = ['first-contact-247' => 13, 'onboarding-greeter' => 19, 'faq-rag' => 20];

        foreach (config('school_agents_dept_b.roles') as $role) {
            if ($role['wave'] !== 'W1') {
                // Разделение scope пакета Hermes 06-10: W1-роли — у детей.
                $this->assertNull($role['child_handoff'], 'only W1 roles are child-owned');
                continue;
            }

            $this->assertArrayHasKey(
                $role['slug'],
                $w1,
                "W1 wave contains the roster-v2 candidates only (unexpected: {$role['slug']})"
            );
            $this->assertSame($w1[$role['slug']], $role['id'], 'W1 slug-to-ID canon matches the 92ID disposition');
            $this->assertSame('child', $role['implementation'], "W1 role {$role['id']} is implemented by a GLM child");
            $this->assertContains($role['child_handoff'], self::W1_CHILD_HANDOFFS);
        }
    }

    public function test_waves_follow_roster_v2_mapping(): void
    {
        $expected = [
            13 => 'W1', 14 => 'W2', 15 => 'W2', 16 => 'W2', 17 => 'W2', 18 => 'W2',
            19 => 'W1', 20 => 'W1', 21 => 'W2', 22 => 'W2',
        ];

        foreach (config('school_agents_dept_b.roles') as $role) {
            $this->assertSame(
                $expected[$role['id']],
                $role['wave'],
                "role {$role['id']} wave matches roster v2"
            );
        }
    }

    public function test_every_role_declares_slugs_unique_and_surfaces_pinned(): void
    {
        $slugs = [];
        foreach (config('school_agents_dept_b.roles') as $role) {
            $slugs[] = $role['slug'];
            $this->assertNotEmpty($role['surfaces'], "role {$role['id']} pins its estate surfaces");
            $this->assertNotEmpty($role['label']);
        }

        $this->assertCount(10, array_unique($slugs), 'slugs unique');
    }

    /**
     * Own-data canary: гейты ссылаются на реальную механику эстейта,
     * не на фантазию — human-send (H6183), pii-маскер, привязка контакта
     * существуют в owning repo.
     */
    public function test_gate_surfaces_bind_to_real_estate_mechanics(): void
    {
        $this->assertTrue(class_exists(SupportHumanSendService::class), 'human-send surface exists (H6183)');
        $this->assertTrue(class_exists(SupportStudentPiiMasker::class), 'pii masker surface exists');
        $this->assertTrue(class_exists(EnrollContactBinder::class), 'TG contact binding surface exists (role 19)');
    }
}
