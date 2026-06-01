<?php

namespace Tests\Unit;

use App\Models\AdPostSpend;
use App\Models\DirectAdSpend;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadCostTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function counts_leads_within_period_and_respects_source_filter(): void
    {
        $this->makeLead('2026-02-10', 'yandex');
        $this->makeLead('2026-02-20', 'vk');
        $this->makeLead('2026-03-05', 'yandex'); // вне февраля

        $feb = Carbon::parse('2026-02-01');

        $this->assertSame(2, Lead::countForPeriod($feb->copy()->startOfMonth(), $feb->copy()->endOfMonth()));
        $this->assertSame(1, Lead::countForPeriod($feb->copy()->startOfMonth(), $feb->copy()->endOfMonth(), 'yandex'));
        $this->assertSame(0, Lead::countForPeriod('2026-04-01', '2026-04-30'));
    }

    /** @test */
    public function reversed_period_returns_zero(): void
    {
        $this->makeLead('2026-02-10');

        $this->assertSame(0, Lead::countForPeriod('2026-02-28', '2026-02-01'));
    }

    /** @test */
    public function direct_ad_spend_computes_budget_and_cost_per_lead(): void
    {
        $this->makeLead('2026-02-10');
        $this->makeLead('2026-02-15');

        $spend = new DirectAdSpend([
            'month' => '2026-02-01',
            'specialist_salary' => 30000,
            'ad_budget' => 10000,
        ]);

        $this->assertSame(2, $spend->leadsCount());
        $this->assertSame(40000.0, $spend->budgetTotal());
        $this->assertSame(20000.0, $spend->costPerLead());
    }

    /** @test */
    public function cost_per_lead_is_null_without_leads(): void
    {
        $spend = new DirectAdSpend([
            'month' => '2026-05-01',
            'specialist_salary' => 1000,
            'ad_budget' => 0,
        ]);

        $this->assertNull($spend->costPerLead());
    }

    /** @test */
    public function ad_post_cost_per_writer(): void
    {
        $this->assertSame(100.0, (new AdPostSpend(['budget' => 1000, 'writers_count' => 10]))->costPerWriter());
        $this->assertNull((new AdPostSpend(['budget' => 500, 'writers_count' => 0]))->costPerWriter());
    }

    private function makeLead(string $date, ?string $utmSource = null): void
    {
        $lead = Lead::create([
            'name' => 'Test Lead',
            'contact' => uniqid('c_', true),
            'email' => uniqid('lead_', true).'@test.local',
            'utm_source' => $utmSource,
        ]);
        // Точную дату ставим через query-update, чтобы не дёргать auto-timestamps.
        Lead::where('id', $lead->id)->update(['created_at' => Carbon::parse($date.' 12:00:00')]);
    }
}
