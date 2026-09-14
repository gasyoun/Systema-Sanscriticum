<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\SafeWithdrawal;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\SafeWithdrawalService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeWithdrawalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // H2541: фикстуры на относительном now() взрываются 31-го числа —
        // subMonths()->startOfMonth() смещает месячную арифметику сервиса.
        Carbon::setTestNow(Carbon::now()->startOfMonth()->addDays(14)->setTime(12, 0));
    }

    private function fakeBank(float $closing = 500000.0): void
    {
        Cache::flush();
        config([
            'services.tochka.token' => 'test-token',
            'services.tochka.balance_cache_seconds' => 0,
        ]);
        Http::fake(['*' => Http::response([
            'Data' => ['Balance' => [
                ['accountId' => 'acc1', 'type' => 'ClosingAvailable', 'dateTime' => '2026-08-22T15:20:33+00:00', 'Amount' => ['amount' => $closing, 'currency' => 'RUB']],
                ['accountId' => 'acc1', 'type' => 'OpeningAvailable', 'dateTime' => '2026-08-22T15:20:33+00:00', 'Amount' => ['amount' => $closing, 'currency' => 'RUB']],
                ['accountId' => 'acc1', 'type' => 'Expected', 'dateTime' => '2026-08-22T15:20:33+00:00', 'Amount' => ['amount' => 0, 'currency' => 'RUB']],
            ]],
        ], 200)]);
    }

    /** @test */
    public function formula_rows_compute_and_money_tables_untouched(): void
    {
        $this->fakeBank(500000.0);
        config([
            'safe_withdrawal.opex_monthly_override' => 30000,
            // Оверрайд по несуществующему в LMS получателю — должен добавиться целиком.
            'safe_withdrawal.staff_overrides' => [
                ['match' => 'Тестовый Оверрайд', 'monthly' => 25000.0],
            ],
        ]);

        // Персонал: единственная сотрудница со ставкой 60 000/мес.
        $staff = User::factory()->create(['name' => 'Сотрудница Фикстура']);
        foreach ([3, 2, 1] as $monthsAgo) {
            $p = Payment::create(['user_id' => $staff->id, 'amount' => -60000, 'tariff' => 'Расход', 'status' => 'paid']);
            $p->forceFill(['created_at' => now()->subMonths($monthsAgo)->startOfMonth()->addDays(4)])->saveQuietly();
        }

        // Преподаватель с блоком 4, кончающимся в горизонте → обязательство в сетке.
        $teacher = Teacher::factory()->create(['name' => 'Препод Свд']);
        $course = Course::factory()->create(['is_active' => true]);
        $course->forceFill(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30])->saveQuietly();
        CourseBlock::factory()->withDates(now()->subDays(40), now()->addDays(10))->create(['course_id' => $course->id, 'number' => 4]);
        Tariff::factory()->block(4)->create(['course_id' => $course->id, 'price' => 100000]);
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        // Квартальный доход кассово: 200 000 → УСН 12 000.
        $student = User::factory()->create();
        $rev = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 200000,
            'tariff' => 'full',
            'status' => 'paid',
            'start_block' => null,
            'end_block' => null,
        ]);
        $rev->forceFill(['created_at' => now()])->saveQuietly();

        $sw = app(SafeWithdrawalService::class)->snapshot();

        // Балансы.
        $this->assertSame(500000.0, $sw['balance_total']);

        // Обязательства: персонал 60 000 × ceil(60/30)=2 мес + оверрайд 25 000 × 2; opex override 30 000 × 2.
        $this->assertSame(round(60000.0 * 2 + 25000.0 * 2, 2), $sw['obligations']['staff_total']);
        $this->assertSame(25000.0, $sw['obligations']['staff_overrides_monthly']);
        $this->assertSame(60000.0, $sw['obligations']['opex_total']);
        $this->assertGreaterThan(0.0, $sw['obligations']['teachers_rub']);
        $this->assertGreaterThan(180000.0, $sw['obligations']['total']);

        // Налоги: УСН 6% × 200 000 = 12 000 (без вычета авансов — консервативно).
        $this->assertSame(200000.0, $sw['taxes']['usn_qtd_revenue']);
        $this->assertSame(12000.0, $sw['taxes']['usn_reserve']);

        // НДФЛ: 13% × (60 000 LMS + 25 000 оверрайд) × 2 = 22 100.
        $this->assertSame(22100.0, $sw['taxes']['ndfl']);

        // Взносы за сотрудницу: 30% схема от 85 000 × 2 = 51 000; МСП ниже.
        $mrot = (float) config('safe_withdrawal.mrot_monthly');
        $this->assertSame(51000.0, $sw['taxes']['insurance_general']);
        $this->assertSame(round(($mrot * 0.30 + (85000 - $mrot) * 0.15) * 2, 2), $sw['taxes']['insurance_msp']);
        $this->assertLessThan($sw['taxes']['insurance_general'], $sw['taxes']['insurance_msp']);

        // ИП фикс: yearly/12 × 2 мес.
        $this->assertSame(round((float) config('safe_withdrawal.ip_fixed_yearly') / 12 * 2, 2), $sw['taxes']['ip_fixed']);

        // Операционный резерв: активные оттоки (персонал 60 000 + оверрайд 25 000 + opex override 30 000) × 1 мес.
        $this->assertSame(115000.0, $sw['op_reserve']['total']);

        // Доступно = баланс − обязательства − УСН − НДФЛ − взносы(схема) − ИП − резерв.
        $expectedGeneral = 500000.0
            - ($sw['obligations']['teachers_rub'] + (60000.0 * 2 + 25000.0 * 2) + 60000.0)
            - 12000.0 - 22100.0 - 51000.0 - $sw['taxes']['ip_fixed'] - 115000.0;
        $this->assertEqualsWithDelta($expectedGeneral, $sw['available_general'], 0.01);
        $this->assertGreaterThan($sw['available_general'], $sw['available_msp']); // МСП дешевле

        $this->assertFalse($sw['money_tables_moved']);
        $this->assertSame(0, TeacherPayout::query()->count());
    }

    /** @test */
    public function page_renders_for_finance_role_and_forbidden_for_manager(): void
    {
        config(['features.teacher_weekly_payout_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->get('/admin/safe-withdrawal')->assertSuccessful()
            ->assertSee('Сколько можно взять себе');

        $this->actingAs(User::factory()->create(['role' => 'manager', 'is_admin' => true]));
        $this->assertFalse(SafeWithdrawal::canAccess());
        $this->get('/admin/safe-withdrawal')->assertForbidden();
    }
}
