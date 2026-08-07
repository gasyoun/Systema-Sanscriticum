<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Reports\SalesFunnelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H2378 sales funnel: first-party events + canonical Payment denominator parity.
 */
class SalesFunnelReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
        config()->set('analytics.metrika.shop_counter_id', '106964341');
        config()->set('analytics.metrika.enabled', true);

        $this->course = Course::factory()->create([
            'title' => 'Санскрит',
            'slug' => 'sanskrit-test',
            'is_visible' => true,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(array $attrs): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ], $attrs)));
    }

    /** @test */
    public function funnel_telemetry_dedups_course_page_view_per_day(): void
    {
        $funnel = app(FunnelTelemetry::class);
        $funnel->emitCoursePageView($this->user, $this->course->id);
        $funnel->emitCoursePageView($this->user, $this->course->id);

        $n = DB::table('activity_events')
            ->where('user_id', $this->user->id)
            ->where('event_type', ActivityEvent::COURSE_PAGE_VIEW)
            ->count();

        $this->assertSame(1, $n);
    }

    /** @test */
    public function payment_success_skips_excluded_tariffs_and_matches_canonical_paid(): void
    {
        $funnel = app(FunnelTelemetry::class);

        $paid = $this->order(['status' => 'paid', 'created_at' => '2026-08-01 10:00:00']);
        $deposit = $this->order([
            'status' => 'paid',
            'tariff' => 'deposit',
            'created_at' => '2026-08-01 11:00:00',
        ]);
        $pending = $this->order(['status' => 'pending', 'created_at' => '2026-08-01 12:00:00']);

        $funnel->emitPaymentSuccess($this->user, $paid);
        $funnel->emitPaymentSuccess($this->user, $deposit);
        $funnel->emitPaymentSuccess($this->user, $pending);
        $funnel->emitPaymentSuccess($this->user, $paid); // dedup

        $n = DB::table('activity_events')
            ->where('event_type', ActivityEvent::PAYMENT_SUCCESS)
            ->count();
        $this->assertSame(1, $n);

        $report = app(SalesFunnelReportService::class)->report(days: 30);
        $this->assertSame(2, $report['paid_denominator']['orders']); // full paid + full pending; deposit excluded
        $this->assertSame(1, $report['paid_denominator']['paid']);
        $this->assertSame(1, $report['steps'][ActivityEvent::PAYMENT_SUCCESS]['events']);
        $this->assertSame(
            $report['paid_denominator']['paid'],
            $report['steps'][ActivityEvent::PAYMENT_SUCCESS]['events'],
            'first-party payment_success must match canonical paid count on fixtures',
        );
    }

    /** @test */
    public function artisan_sales_funnel_report_prints_denominators(): void
    {
        $this->order(['status' => 'paid', 'created_at' => '2026-08-01 10:00:00']);
        app(FunnelTelemetry::class)->emitCoursePageView($this->user, $this->course->id);

        $this->artisan('sales:funnel-report', ['--days' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain('Sales funnel report')
            ->expectsOutputToContain('Canonical Payment denominator')
            ->expectsOutputToContain('course_page_view')
            ->expectsOutputToContain('Denominators (explicit)');
    }

    /** @test */
    public function shop_layout_includes_metrika_when_enabled(): void
    {
        $tariff = Tariff::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'price' => 10000,
        ]);

        $this->actingAs($this->user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertSee('shopReachGoal', false)
            ->assertSee('begin_checkout', false);
    }

    /** @test */
    public function first_cabinet_action_emits_once(): void
    {
        $funnel = app(FunnelTelemetry::class);
        $funnel->emitFirstCabinetAction($this->user, 'cabinet.home.view');
        $funnel->emitFirstCabinetAction($this->user, 'lesson_open');

        $this->assertSame(1, DB::table('activity_events')
            ->where('event_type', ActivityEvent::FIRST_CABINET_ACTION)
            ->count());
    }
}
