<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ReceivablesGovernanceService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ручная сверка контроля дебиторки/рассрочки (H257) на известной синтетической
 * когорте. Дебиторка = непогашенные обещания оплаты; рассрочка = обещания,
 * объединённые installment_group_id. Пороги/лимиты — config/receivables.php.
 */
class ReceivablesGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

        // Фиксируем пороги/лимиты, чтобы тест не зависел от .env.
        config()->set('receivables.threshold.mode', 'fixed');
        config()->set('receivables.threshold.fixed_amount', 100000);
        config()->set('receivables.illiquid_after_days', 14);
        config()->set('receivables.warn_ratio', 0.8);
        config()->set('receivables.installment_limits.max_share_pct', 30);
        config()->set('receivables.installment_limits.max_concurrent', 3);
        config()->set('receivables.installment_limits.max_term_months', 3);

        $this->course = Course::factory()->create(['title' => 'Санскрит']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Обещание без Eloquent-событий (минуем CuratorNotifier). created_at не
     * входит в $fillable — если задан, проставляем его явно после создания
     * (иначе Eloquent затрёт его текущим временем и «неделя назад» не сойдётся).
     */
    private function promise(array $attrs): PaymentPromise
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        return PaymentPromise::withoutEvents(function () use ($attrs, $createdAt): PaymentPromise {
            $p = PaymentPromise::create(array_merge([
                'course_id' => $this->course->id,
                'status' => PaymentPromise::STATUS_ACTIVE,
            ], $attrs));
            if ($createdAt !== null) {
                $p->created_at = $createdAt;
                $p->saveQuietly();
            }

            return $p;
        });
    }

    /** План рассрочки: N строк с общим installment_group_id. */
    private function installment(User $user, array $rows, ?string $groupId = null): string
    {
        $groupId ??= (string) Str::uuid();
        foreach ($rows as $row) {
            $this->promise(array_merge([
                'user_id' => $user->id,
                'installment_group_id' => $groupId,
            ], $row));
        }

        return $groupId;
    }

    private function service(): ReceivablesGovernanceService
    {
        return app(ReceivablesGovernanceService::class);
    }

    /** @test */
    public function totals_split_installment_versus_other_receivables(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // Рассрочка: 2 строки по 20 000 (будущие даты) = 40 000 дебиторки.
        $this->installment($u1, [
            ['amount' => 20000, 'promised_at' => '2026-07-20'],
            ['amount' => 20000, 'promised_at' => '2026-08-20'],
        ]);
        // Одиночное обещание = 15 000 прочей дебиторки.
        $this->promise(['user_id' => $u2->id, 'amount' => 15000, 'promised_at' => '2026-07-25']);
        // Выполненное обещание — НЕ дебиторка.
        $this->promise([
            'user_id' => $u2->id, 'amount' => 9999, 'promised_at' => '2026-07-01',
            'status' => PaymentPromise::STATUS_FULFILLED, 'fulfilled_at' => '2026-07-02',
        ]);

        $snap = $this->service()->snapshot();

        $this->assertSame(55000.0, $snap['total']);        // 40000 + 15000
        $this->assertSame(40000.0, $snap['installment']);
        $this->assertSame(15000.0, $snap['other']);
    }

    /** @test */
    public function red_alert_triggers_when_receivables_exceed_threshold(): void
    {
        $u = User::factory()->create();
        // Порог 100 000; заведём 120 000 дебиторки.
        $this->promise(['user_id' => $u->id, 'amount' => 120000, 'promised_at' => '2026-07-20']);

        $snap = $this->service()->snapshot();

        $this->assertSame('danger', $snap['level']);
        $this->assertFalse($snap['ok']);
        $this->assertSame(1.2, $snap['ratio']);
        $this->assertNotEmpty($snap['alerts']);
    }

    /** @test */
    public function green_when_within_threshold_and_limits(): void
    {
        $u = User::factory()->create();
        $this->promise(['user_id' => $u->id, 'amount' => 50000, 'promised_at' => '2026-07-20']); // 50% порога

        $snap = $this->service()->snapshot();

        $this->assertSame('success', $snap['level']);
        $this->assertTrue($snap['ok']);
        $this->assertEmpty($snap['alerts']);
    }

    /** @test */
    public function warning_between_warn_ratio_and_threshold(): void
    {
        $u = User::factory()->create();
        // 90 000 из 100 000 = 0.9 → между warn_ratio 0.8 и 1.0.
        $this->promise(['user_id' => $u->id, 'amount' => 90000, 'promised_at' => '2026-07-20']);

        $snap = $this->service()->snapshot();

        $this->assertSame('warning', $snap['level']);
        $this->assertTrue($snap['ok']); // жёлтый — ещё не алерт
    }

    /** @test */
    public function overdue_share_counts_only_promises_past_illiquid_window(): void
    {
        $u = User::factory()->create();
        // Просрочено на 20 дней (> 14) = неликвид.
        $this->promise(['user_id' => $u->id, 'amount' => 30000, 'promised_at' => '2026-06-17']);
        // Просрочено на 5 дней (< 14) — ещё не неликвид.
        $this->promise(['user_id' => $u->id, 'amount' => 10000, 'promised_at' => '2026-07-02']);
        // Будущее — не просрочено.
        $this->promise(['user_id' => $u->id, 'amount' => 10000, 'promised_at' => '2026-07-20']);

        $snap = $this->service()->snapshot();

        $this->assertSame(50000.0, $snap['total']);
        $this->assertSame(30000.0, $snap['overdue']);
        $this->assertSame(1, $snap['overdue_count']);
        $this->assertSame(60.0, $snap['overdue_pct']); // 30000 / 50000
    }

    /** @test */
    public function week_over_week_delta_reconstructs_from_ledger(): void
    {
        $u = User::factory()->create();
        // Старое обещание — существовало и неделю назад.
        $this->promise([
            'user_id' => $u->id, 'amount' => 30000, 'promised_at' => '2026-07-20',
            'created_at' => '2026-06-25 10:00:00',
        ]);
        // Новое обещание — заведено 3 дня назад, неделю назад его ещё не было.
        $this->promise([
            'user_id' => $u->id, 'amount' => 20000, 'promised_at' => '2026-07-25',
            'created_at' => '2026-07-04 10:00:00',
        ]);

        $snap = $this->service()->snapshot();

        $this->assertSame(50000.0, $snap['total']);      // сейчас оба
        $this->assertSame(30000.0, $snap['prev_total']); // неделю назад только старое
        $this->assertSame(20000.0, $snap['delta_abs']);
    }

    /** @test */
    public function concurrent_installment_limit_breach_raises_alert(): void
    {
        // Лимит одновременных планов = 3; заведём 4.
        for ($i = 0; $i < 4; $i++) {
            $u = User::factory()->create();
            $this->installment($u, [
                ['amount' => 1000, 'promised_at' => '2026-07-20'],
                ['amount' => 1000, 'promised_at' => '2026-08-20'],
            ]);
        }

        $snap = $this->service()->snapshot();
        $concurrent = collect($snap['limits'])->firstWhere('key', 'concurrent');

        $this->assertSame(4, $concurrent['current']);
        $this->assertTrue($concurrent['breached']);
        $this->assertSame('danger', $snap['level']); // пробитый лимит → красный
    }

    /** @test */
    public function term_limit_flags_plans_longer_than_max_months(): void
    {
        $u = User::factory()->create();
        // План на 5 месяцев (лимит 3): первая 20-07, последняя ~20-12.
        $this->installment($u, [
            ['amount' => 1000, 'promised_at' => '2026-07-20'],
            ['amount' => 1000, 'promised_at' => '2026-12-20'],
        ]);

        $snap = $this->service()->snapshot();
        $term = collect($snap['limits'])->firstWhere('key', 'term');

        $this->assertGreaterThan(3, $term['current']);
        $this->assertTrue($term['breached']);
    }

    /** @test */
    public function page_renders_for_finance_role(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/receivables-governance')->assertSuccessful();
    }

    /** @test */
    public function check_command_alerts_finance_users_when_over_threshold(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $u = User::factory()->create();
        $this->promise(['user_id' => $u->id, 'amount' => 150000, 'promised_at' => '2026-07-20']);

        $this->artisan('receivables:check')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $accountant->id]);
    }

    /** @test */
    public function check_command_stays_silent_when_within_threshold(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $u = User::factory()->create();
        $this->promise(['user_id' => $u->id, 'amount' => 10000, 'promised_at' => '2026-07-20']);

        $this->artisan('receivables:check')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $accountant->id]);
    }
}
