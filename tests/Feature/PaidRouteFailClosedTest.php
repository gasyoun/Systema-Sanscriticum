<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\RevenueSchedule;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use App\Services\PaymentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2304: все платные маршруты fail closed на курсе без групп доступа, а импорт
 * оплат проходит через цепочку выдачи доступа (группы + course_user + сабледжер).
 *
 * Точка-вебхук покрыт в Webhooks/TochkaWebhookTest (H2085), PayPal-подписки —
 * в PaypalSubscriptionsWebhookTest; здесь остальные маршруты §4(a) аудита:
 * Filament/ручное создание, zero-price checkout (updated→paid), conditional
 * access и импорт.
 */
class PaidRouteFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Money-контур: throw в grantAccess за флагом (дефолт OFF в проде).
        config(['features.grant_access_fail_closed' => true]);
    }

    /** @test */
    public function manual_paid_create_on_groupless_course_throws_and_grants_nothing(): void
    {
        // Маршрут «Filament create / любой Payment::create со status=paid».
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->assertFalse($course->groups()->exists());

        try {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 5000,
                'tariff' => 'full',
                'status' => 'paid',
                'transaction_id' => 'manual-test',
            ]);
            $this->fail('Ожидался RuntimeException: у курса нет групп доступа.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('нет привязанных групп', $e->getMessage());
        }

        $this->assertFalse($user->fresh()->groups()->exists());
    }

    /** @test */
    public function flag_off_keeps_legacy_log_and_return_behaviour(): void
    {
        // Prod-inert as merged: с выключенным флагом grantAccess по-прежнему
        // логирует и возвращается (доступ не выдан, но и исключения нет).
        config(['features.grant_access_fail_closed' => false]);
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'transaction_id' => 'legacy-flag-off',
        ]);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups()->exists());
    }

    /** @test */
    public function zero_price_style_update_to_paid_on_groupless_course_throws(): void
    {
        // Маршрут «zero-price checkout»: PaymentController создаёт pending и
        // переводит в paid ($payment->update(['status' => 'paid'])) — та же
        // центральная цепочка updated→fireOnPaid→grantAccess.
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'pending',
            'transaction_id' => 'zero-price-test',
        ]);

        try {
            $payment->update(['status' => 'paid']);
            $this->fail('Ожидался RuntimeException: у курса нет групп доступа.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('нет привязанных групп', $e->getMessage());
        }

        $this->assertFalse($user->fresh()->groups()->exists());
    }

    /** @test */
    public function conditional_grant_on_groupless_course_throws_and_rolls_back(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek(),
            'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        try {
            app(ConditionalAccessGranter::class)
                ->grantForPromise($promise, ConditionalAccessGranter::MODE_FULL);
            $this->fail('Ожидался RuntimeException: у курса нет групп доступа.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('нет привязанных групп', $e->getMessage());
        }

        // Транзакция откатила и conditional-платёж: не «paid без доступа».
        $this->assertSame(0, Payment::where('is_conditional', true)->count());
        $this->assertFalse($user->fresh()->groups()->exists());
    }

    /** @test */
    public function import_into_groupless_course_is_refused(): void
    {
        $course = Course::factory()->create();
        $this->assertFalse($course->groups()->exists());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/нет привязанных групп/u');

        app(PaymentImportService::class)->import(
            $this->makeCsv(['Студент,Сумма', 'Кто-то,5000']),
            $course,
            ['user' => 'A', 'amount' => 'B'],
            'name',
        );
    }

    /** @test */
    public function import_grants_groups_enrollment_subledger_and_course_page_opens(): void
    {
        // H2304 spec 1 acceptance: 2-строчный импорт ⇒ group_user + course_user
        // строки существуют и страница курса отдаёт 200 (раньше — 404, потому
        // что сырой insert обходил Payment::booted()).
        $group = Group::factory()->create();
        $course = Course::factory()->create();
        $course->groups()->attach($group);
        $alice = User::factory()->create(['name' => 'Импорт Алиса']);
        $bob = User::factory()->create(['name' => 'Импорт Боб']);

        $stats = app(PaymentImportService::class)->import(
            $this->makeCsv([
                'Студент,Сумма',
                "{$alice->name},5000",
                "{$bob->name},3000",
            ]),
            $course,
            ['user' => 'A', 'amount' => 'B'],
            'name',
        );

        $this->assertSame(2, $stats['inserted']);
        $this->assertSame(2, Payment::where('course_id', $course->id)->where('status', 'paid')->count());

        foreach ([$alice, $bob] as $student) {
            $this->assertTrue(
                $student->fresh()->groups()->whereKey($group->id)->exists(),
                "group_user: студент #{$student->id} не попал в группу курса"
            );
            $this->assertTrue(
                $student->fresh()->courses()->whereKey($course->id)->exists(),
                "course_user: студент #{$student->id} не записан на курс"
            );
        }

        $paymentIds = Payment::where('course_id', $course->id)->pluck('id');
        $this->assertGreaterThan(
            0,
            RevenueSchedule::whereIn('payment_id', $paymentIds)->count(),
            'revenue_schedules: сабледжер не сгенерирован для импортированных оплат'
        );

        $this->actingAs($alice)
            ->get('/c/'.$course->slug)
            ->assertOk();
    }

    /** @test */
    public function reimport_of_same_file_is_deduplicated(): void
    {
        $group = Group::factory()->create();
        $course = Course::factory()->create();
        $course->groups()->attach($group);
        $user = User::factory()->create(['name' => 'Импорт Дедуп']);

        $csvLines = ['Студент,Сумма,Дата', "{$user->name},5000,2026-01-15"];
        $service = app(PaymentImportService::class);
        $mapping = ['user' => 'A', 'amount' => 'B', 'date' => 'C'];

        $first = $service->import($this->makeCsv($csvLines), $course, $mapping, 'name');
        $second = $service->import($this->makeCsv($csvLines), $course, $mapping, 'name');

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, Payment::where('course_id', $course->id)->count());
    }

    /**
     * @param  list<string>  $lines
     */
    private function makeCsv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'h2304_import_');
        $csvPath = $path.'.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, implode("\n", $lines)."\n");

        return $csvPath;
    }
}
