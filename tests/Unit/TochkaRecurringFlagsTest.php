<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BillingSubscriptionMode;
use App\Support\TochkaRecurring;
use Tests\TestCase;

/**
 * H2026 Phase 0 — dark feature flags default OFF; mode allow-list gated by master.
 * Executor: Grok 4.5 (grok-4.5).
 */
class TochkaRecurringFlagsTest extends TestCase
{
    public function test_master_flag_defaults_off_in_config(): void
    {
        // Config file default is false; tests may inherit env — force reset.
        config(['features.tochka_recurring' => false]);

        $this->assertFalse((bool) config('features.tochka_recurring'));
        $this->assertFalse(TochkaRecurring::enabled());
    }

    public function test_mode_not_allowed_when_master_off(): void
    {
        config([
            'features.tochka_recurring' => false,
            'features.tochka_recurring_modes' => ['per_course', 'club', 'installment'],
        ]);

        $this->assertFalse(TochkaRecurring::modeAllowed(BillingSubscriptionMode::PerCourse));
        $this->assertFalse(TochkaRecurring::modeAllowed('club'));
    }

    public function test_mode_allowed_only_when_on_and_in_list(): void
    {
        config([
            'features.tochka_recurring' => true,
            'features.tochka_recurring_modes' => ['per_course', 'club'],
        ]);

        $this->assertTrue(TochkaRecurring::modeAllowed(BillingSubscriptionMode::PerCourse));
        $this->assertTrue(TochkaRecurring::modeAllowed('club'));
        $this->assertFalse(TochkaRecurring::modeAllowed(BillingSubscriptionMode::Consolidated));
        $this->assertFalse(TochkaRecurring::modeAllowed(BillingSubscriptionMode::Installment));
    }

    public function test_modes_string_config_parses(): void
    {
        config([
            'features.tochka_recurring' => true,
            'features.tochka_recurring_modes' => 'per_course, club, installment',
        ]);

        $this->assertSame(
            ['per_course', 'club', 'installment'],
            TochkaRecurring::allowedModes()
        );
        $this->assertTrue(TochkaRecurring::modeAllowed('installment'));
    }
}
