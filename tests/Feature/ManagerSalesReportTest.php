<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ManagerSalesReport;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\OrderPaymentConversionService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GC-C2 (H2058 residual / GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §4). F5
 * RULED 02-08-2026: (a) join = payments.created_by_user_id, NOT
 * leads.assigned_to; (b) super_admin/admin/accountant see all rows, manager
 * sees own rows only. Flag `manager_sales_report` default OFF (deploy switch,
 * pinned here per the SrsFlagDefaultTest/DealFlagDefaultTest precedent: config
 * value AND observable canAccess() behaviour, not just one or the other).
 */
class ManagerSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00'));

        config()->set('conversion.target_pct', 63);
        config()->set('conversion.warn_pct', 50);
        config()->set('conversion.breakdown_window_days', 90);
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        $this->course = Course::factory()->create(['title' => 'Санскрит']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Заказ без Eloquent-событий (та же дисциплина, что OrderPaymentConversionTest).
     * created_by_user_id намеренно НЕ fillable (TracksBlame) — withoutEvents
     * обходит и хук, и mass-assignment guard, поэтому проставляем его отдельным
     * forceFill+saveQuietly, как «кто в CRM оформил этот платёж».
     */
    private function order(array $attrs, ?int $createdBy = null): Payment
    {
        $payment = Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ], $attrs)));

        if ($createdBy !== null) {
            $payment->forceFill(['created_by_user_id' => $createdBy])->saveQuietly();
        }

        return $payment;
    }

    private function service(): OrderPaymentConversionService
    {
        return app(OrderPaymentConversionService::class);
    }

    /** @test */
    public function flag_defaults_to_false(): void
    {
        $this->assertFalse(
            (bool) config('features.manager_sales_report'),
            'GC-C2 обязан быть ВЫКЛ по умолчанию — deploy-рубильник'
        );
    }

    /** @test */
    public function page_is_unreachable_by_default_even_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $this->assertFalse(ManagerSalesReport::canAccess());
        $this->assertFalse(ManagerSalesReport::shouldRegisterNavigation());
    }

    /** @test */
    public function admin_sees_all_rows_including_unassigned_when_flag_on(): void
    {
        config()->set('features.manager_sales_report', true);

        $anna = User::factory()->create(['name' => 'Анна', 'role' => Roles::MANAGER]);
        $buyer = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], $anna->id);
        $this->order(['user_id' => $buyer->id, 'status' => 'pending', 'created_at' => $at], $anna->id);
        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], null); // самостоятельная покупка

        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->actingAs($admin);
        $this->assertTrue(ManagerSalesReport::canAccess());

        $this->get('/admin/manager-sales-report')->assertSuccessful();

        $snap = $this->service()->managerScoreboard();
        $byManager = collect($snap['by_manager']);

        $this->assertSame(50.0, $byManager->firstWhere('manager', 'Анна')['conversion_pct']);
        $this->assertSame(100.0, $byManager->firstWhere('manager', 'Без менеджера')['conversion_pct']);
    }

    /** @test */
    public function manager_scoreboard_scopes_to_only_own_created_by_when_filtered(): void
    {
        $anna = User::factory()->create(['name' => 'Анна']);
        $boris = User::factory()->create(['name' => 'Борис']);
        $buyer = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], $anna->id);
        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], $boris->id);

        $scoped = $this->service()->managerScoreboard(onlyCreatedBy: $anna->id);
        $byManager = collect($scoped['by_manager']);

        $this->assertSame(1, $byManager->count());
        $this->assertSame('Анна', $byManager->first()['manager']);
    }

    /** @test */
    public function manager_role_sees_only_own_rows_when_flag_on(): void
    {
        config()->set('features.manager_sales_report', true);

        $anna = User::factory()->create(['name' => 'Анна', 'role' => Roles::MANAGER]);
        $boris = User::factory()->create(['name' => 'Борис', 'role' => Roles::MANAGER]);
        $buyer = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], $anna->id);
        $this->order(['user_id' => $buyer->id, 'status' => 'paid', 'created_at' => $at], $boris->id);

        $this->actingAs($anna);
        $this->assertTrue(ManagerSalesReport::canAccess());

        $response = $this->get('/admin/manager-sales-report')->assertSuccessful();
        $response->assertSee('Анна');
        $response->assertDontSee('Борис');
    }

    /** @test */
    public function teacher_cannot_access_page_even_when_flag_on(): void
    {
        config()->set('features.manager_sales_report', true);
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        $this->actingAs($teacher);
        $this->assertFalse(ManagerSalesReport::canAccess());

        $this->get('/admin/manager-sales-report')->assertForbidden();
    }
}
