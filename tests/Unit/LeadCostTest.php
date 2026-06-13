<?php

namespace Tests\Unit;

use App\Models\AdPostSpend;
use App\Models\DirectAdSpend;
use App\Models\Lead;
use App\Support\LeadCostReport;
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
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-02-28',
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
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-31',
            'specialist_salary' => 1000,
            'ad_budget' => 0,
        ]);

        $this->assertNull($spend->costPerLead());
    }

    /** @test */
    public function budget_for_window_is_prorated_by_days(): void
    {
        // Период 10 дней (01–10 марта вкл.), бюджет 1000.
        $spend = new DirectAdSpend([
            'starts_at' => '2026-03-01',
            'ends_at' => '2026-03-10',
            'specialist_salary' => 1000,
            'ad_budget' => 0,
        ]);

        $this->assertSame(10, $spend->periodDays());

        // Окно покрывает 5 из 10 дней → половина бюджета.
        $this->assertSame(500.0, $spend->budgetForWindow(
            Carbon::parse('2026-03-01'), Carbon::parse('2026-03-05')
        ));

        // Окно шире периода → полный бюджет.
        $this->assertSame(1000.0, $spend->budgetForWindow(
            Carbon::parse('2026-02-01'), Carbon::parse('2026-03-31')
        ));

        // Нет пересечения → 0.
        $this->assertSame(0.0, $spend->budgetForWindow(
            Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30')
        ));
    }

    /** @test */
    public function report_buckets_split_budget_across_months_proportionally(): void
    {
        // Оплата на стыке месяцев: 22 марта – 10 апреля (20 дней), бюджет 2000 → 100 ₽/день.
        // Март: 22–31 = 10 дней → 1000 ₽. Апрель: 1–10 = 10 дней → 1000 ₽.
        DirectAdSpend::create([
            'starts_at' => '2026-03-22',
            'ends_at' => '2026-04-10',
            'specialist_salary' => 2000,
            'ad_budget' => 0,
        ]);

        $this->makeLead('2026-03-25'); // 1 лид в марте
        $this->makeLead('2026-04-05'); // 1 лид в апреле
        $this->makeLead('2026-04-08'); // 2-й лид в апреле

        $buckets = LeadCostReport::buckets(
            Carbon::parse('2026-03-22'),
            Carbon::parse('2026-04-10'),
            'month',
        );

        $this->assertCount(2, $buckets);

        $this->assertSame(1000.0, round($buckets[0]['budget'], 2));
        $this->assertSame(1, $buckets[0]['leads']);
        $this->assertSame(1000.0, round($buckets[0]['cost'], 2));

        $this->assertSame(1000.0, round($buckets[1]['budget'], 2));
        $this->assertSame(2, $buckets[1]['leads']);
        $this->assertSame(500.0, round($buckets[1]['cost'], 2));
    }

    /** @test */
    public function report_cost_per_lead_is_null_without_leads(): void
    {
        DirectAdSpend::create([
            'starts_at' => '2026-03-01',
            'ends_at' => '2026-03-31',
            'specialist_salary' => 5000,
            'ad_budget' => 0,
        ]);

        $this->assertNull(LeadCostReport::costPerLead(
            Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')
        ));
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
