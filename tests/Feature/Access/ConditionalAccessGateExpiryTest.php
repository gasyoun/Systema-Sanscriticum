<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4396 — expiry-предикат на payment-keyed доступ (census
 * PAYWALL_CENSUS_2026-09-08 §C.1; аудит 06-08 спека 5).
 *
 * Дыра: conditional-платёж («доступ под обещание», is_conditional=true,
 * status=paid) проходил scopePaid() и открывал уроки НАВСЕГДА —
 * promises:expire менял только статус обещания, ключи переживали дедлайн.
 *
 * Фикс — read-side предикат Payment::scopeWithAccessExpiry за флагом
 * conditional_access_expiry (money-контур: дефолт OFF, прод-флип —
 * отдельный ops-шаг, H2085 discipline; реальные платежи не трогаются —
 * «оплатил = владеет навсегда»).
 */
class ConditionalAccessGateExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Lesson $lesson;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();

        $this->course = Course::factory()->create();
        $this->group = Group::create(['name' => 'Поток '.$this->course->id]);
        $this->course->groups()->attach($this->group->id);
        $this->student = User::factory()->create();
        $this->student->groups()->syncWithoutDetaching([$this->group->id]);

        $this->lesson = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
    }

    /**
     * Conditional-грант руками (без ConditionalAccessGranter — тот требует
     * promise-механику целиком; форма строки идентична createConditionalPayment).
     */
    private function grantConditional(PaymentPromise $promise, string $tariff = 'full'): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 0,
            'tariff' => $tariff,
            'status' => 'paid',
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'is_conditional' => true,
            'linked_promise_id' => $promise->id,
            'first_paid_at' => now(),
        ]));
    }

    private function promiseWith(string $promisedAt, string $status = PaymentPromise::STATUS_ACTIVE): PaymentPromise
    {
        return PaymentPromise::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'promised_at' => $promisedAt,
            'amount' => 6000,
            'status' => $status,
        ]);
    }

    private function grantFor(PaymentPromise $promise): Payment
    {
        return $this->grantConditional($promise);
    }

    /** @test */
    public function flag_off_keeps_the_current_unlocking_behavior(): void
    {
        // Флаг money-контура OFF (дефолт) — прод-поведение не меняется,
        // условный доступ на истёкшем обещании пока открывает.
        config()->set('features.conditional_access_expiry', false);

        $promise = $this->promiseWith(now()->subWeek()->toDateString(), PaymentPromise::STATUS_EXPIRED);
        $this->grantFor($promise);

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function expired_promise_stops_opening_lessons_when_enabled(): void
    {
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->subWeek()->toDateString(), PaymentPromise::STATUS_EXPIRED);
        $this->grantFor($promise);

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertNotContains('full', $keys);

        // Плеер: страница урока платит стеною (redirect к списку с ошибкой).
        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertRedirect(route('student.course', $this->course->slug));
    }

    /** @test */
    public function overdue_active_promise_is_already_expired_for_the_predicate(): void
    {
        // Демон promises:expire бежит раз в день (03:30) — между дедлайном и
        // прогоном статус ещё active. Предикат смотрит на ДАТУ, а не на статус.
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->subDay()->toDateString(), PaymentPromise::STATUS_ACTIVE);
        $this->grantFor($promise);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertRedirect(route('student.course', $this->course->slug));
    }

    /** @test */
    public function live_promise_still_opens_lessons_when_enabled(): void
    {
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->addWeek()->toDateString());
        $this->grantFor($promise);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function cancelled_promise_stops_granting_when_enabled(): void
    {
        // Куратор отменил договорённость (waive) — обещание не живо, ключ
        // под него больше не открывает (revokeForPromise остаётся явным
        // путём с уведомлениями; предикат закрывает data-residue).
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->addWeek()->toDateString(), PaymentPromise::STATUS_CANCELLED);
        $this->grantFor($promise);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertRedirect(route('student.course', $this->course->slug));
    }

    /** @test */
    public function orphan_conditional_without_a_promise_stops_granting_when_enabled(): void
    {
        // Residue из аудита (D9): is_conditional-строки, чьё обещание удалено
        // (linked_promise_id NULL после nullOnDelete), ключей не дают.
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->addWeek()->toDateString());
        $this->grantFor($promise);
        $promise->delete();

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertRedirect(route('student.course', $this->course->slug));
    }

    /** @test */
    public function real_payments_are_never_touched_by_the_predicate(): void
    {
        config()->set('features.conditional_access_expiry', true);

        Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]);

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function api_cabinet_applies_the_same_expiry_predicate(): void
    {
        config()->set('features.conditional_access_expiry', true);

        $promise = $this->promiseWith(now()->subWeek()->toDateString(), PaymentPromise::STATUS_EXPIRED);
        $this->grantFor($promise);

        // Ещё одно живое обещание на соседнем уроке — доказать точечность.
        $lesson2 = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 2,
        ]);
        $live = $this->promiseWith(now()->addWeek()->toDateString());
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 0,
            'tariff' => 'block_2',
            'status' => 'paid',
            'transaction_id' => 'promise_grant_#'.$live->id,
            'is_conditional' => true,
            'linked_promise_id' => $live->id,
            'first_paid_at' => now(),
        ]));

        $response = $this->actingAs($this->student)
            ->getJson('/api/v1/courses/'.$this->course->slug.'/lessons');
        $response->assertOk();

        $locked = collect($response->json('lessons'))->pluck('locked', 'id');
        $this->assertTrue($locked[$this->lesson->id]);
        $this->assertFalse($locked[$lesson2->id]);
    }

    /** @test */
    public function expire_command_reports_conditional_grants_on_just_expired_promises(): void
    {
        $promise = $this->promiseWith(now()->subDay()->toDateString());
        $this->grantFor($promise);
        config()->set('services.telegram.curators_chat_id', '999');

        $this->artisan('promises:expire')
            // Одна строка = один doWrite-вызов; substr-ожидание консьюмится
            // по одному на вызов, поэтому одна самая полная проверка.
            ->expectsOutputToContain('Conditional grants on expired promises: 1 (still open')
            ->assertSuccessful();
    }
}
