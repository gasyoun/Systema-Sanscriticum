<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Cabinet\RecoveryState;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1481 — Phase 1 hybrid chassis + recovery-state resolver (R29.0–R29.2).
 *
 * Acceptance:
 *  - hybrid flag OFF → prod-stable legacy dashboard; new pages 404;
 *  - hybrid flag ON → job-named pages render; course hash tabs present;
 *  - declined payment → recovery banner, zero offers, owned access kept;
 *  - pending payment is NOT recovery (webhook-delay trap);
 *  - homework rework appears in «Сегодня» only when returned.
 */
class HybridPhase1Test extends TestCase
{
    use RefreshDatabase;

    private function enableHybrid(): void
    {
        config(['features.cabinet_hybrid' => true]);
    }

    private function studentWithCourse(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-hybrid-p1']);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика I']);
        $course->groups()->attach($group);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'title' => 'Урок 7',
            'is_free' => true,
        ]);

        return compact('user', 'group', 'course', 'lesson');
    }

    /** @test */
    public function hybrid_routes_404_when_flag_off(): void
    {
        config(['features.cabinet_hybrid' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('student.library'))->assertNotFound();
        $this->actingAs($user)->get(route('student.progress'))->assertNotFound();
        $this->actingAs($user)->get(route('student.access'))->assertNotFound();
    }

    /** @test */
    public function legacy_dashboard_unchanged_when_flag_off(): void
    {
        config(['features.cabinet_hybrid' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Личный кабинет', false)
            ->assertDontSee('data-cabinet-mode="recovery"', false)
            ->assertDontSee('data-today-band', false);
    }

    /** @test */
    public function hybrid_home_renders_job_nav_and_today_band(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithCourse();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Сегодня', false)
            ->assertSee('data-today-band', false)
            ->assertSee('Календарь', false)
            ->assertSee('Записи', false)
            ->assertSee('Оплата и доступ', false)
            ->assertSee('Помощь', false);

        $event = ActivityEvent::where('event_type', ActivityEvent::CABINET_HOME_VIEW)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('normal', $event->event_data['mode'] ?? null);
    }

    /** @test */
    public function today_band_debt_cta_posts_to_pay_bundle_instead_of_get_link(): void
    {
        // Прод-405 01-09-2026: карточка «Продолжить» рендерила платёжный CTA
        // голой ссылкой, а /debt/course/{id}/pay-bundle принимает только POST.
        $this->enableHybrid();
        $user = User::factory()->create();

        // Bundle-долг: блоки 1–3 (№3 текущий), оплачен только №1, у №2 и №3
        // есть поблочные тарифы → fallback pay-bundle (bundle-тарифа нет).
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика долг']);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->create(['number' => 2]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 3]);
        foreach ([2, 3] as $n) {
            Tariff::create([
                'course_id' => $course->id, 'title' => 'Блок №'.$n, 'type' => 'block',
                'block_number' => $n, 'price' => 4000, 'is_active' => true,
            ]);
        }
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
        ]);

        $payBundleUrl = route('student.debt.course.pay-bundle', $course->id);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('action="'.$payBundleUrl.'"', false)
            ->assertDontSee('href="'.$payBundleUrl.'"', false);
    }

    /** @test */
    public function hybrid_home_shows_flash_from_payment_redirects(): void
    {
        // Прод-кейс 01-09-2026: анти-дубль оплаты редиректил с session('error'),
        // а hybrid-вид flash не рендерил — «кнопка ничего не делает».
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithCourse();

        $error = 'У вас уже есть незавершённый заказ по этому курсу.';
        $response = $this->actingAs($user)
            ->withSession(['error' => $error])
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee($error, false);

        // Ровно один баннер — партиал не задвоен через layout+страницу.
        $this->assertSame(1, substr_count($response->getContent(), $error));

        $this->actingAs($user)
            ->withSession(['success' => 'Дата оплаты перенесена.'])
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Дата оплаты перенесена.', false);
    }

    /** @test */
    public function hybrid_access_page_shows_flash_too(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithCourse();

        $this->actingAs($user)
            ->withSession(['error' => 'Сервис оплаты временно недоступен.'])
            ->get(route('student.access'))
            ->assertOk()
            ->assertSee('Сервис оплаты временно недоступен.', false);
    }

    /** @test */
    public function today_band_lesson_cta_stays_a_plain_link(): void
    {
        // Регресс: обычный CTA «продолжить урок» — по-прежнему ссылка.
        $this->enableHybrid();
        ['user' => $user, 'lesson' => $lesson] = $this->studentWithCourse();

        $response = $this->actingAs($user)->get(route('student.dashboard'))->assertOk();

        $this->assertStringContainsString(
            'data-today-continue',
            $response->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/data-today-continue.*?<a href="[^"]*"/s',
            $response->getContent(),
            'CTA урока в карточке «Продолжить» должен остаться ссылкой.',
        );
    }

    /** @test */
    public function hybrid_library_progress_access_render_when_flag_on(): void
    {
        $this->enableHybrid();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('student.library'))->assertOk()->assertSee('Записи', false);
        $this->actingAs($user)->get(route('student.progress'))->assertOk()->assertSee('Прогресс', false);
        $this->actingAs($user)->get(route('student.access'))->assertOk()->assertSee('Оплата и доступ', false);
    }

    /** @test */
    public function course_workspace_exposes_hash_addressable_tabs(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->assertSee('hybridCourseTabs', false)
            ->assertSee("id: 'uroki'", false)
            ->assertSee('tab-uroki', false)
            ->assertSee('Уроки', false)
            ->assertSee('Материалы', false)
            ->assertSee('Доступ и продление', false);
    }

    /** @test */
    public function declined_payment_enters_recovery_with_zero_offers_and_owned_access(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course, 'lesson' => $lesson] = $this->studentWithCourse();

        // Owned free lesson access must keep working (R29.2).
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'failed',
            'is_conditional' => false,
            'updated_at' => now(),
        ]));

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertTrue($state->active);
        $this->assertTrue($state->suppressOffers());
        $this->assertTrue($state->ownedAccessKeepsWorking());
        $this->assertSame(RecoveryState::REASON_DECLINED_PAYMENT, $state->reason);

        $html = $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-cabinet-mode="recovery"', false)
            ->assertSee('Не проходит оплата', false)
            ->assertDontSee('data-offer-slot', false)
            ->getContent();

        // No promotional offer markup while recovery is active.
        $this->assertStringNotContainsString('data-offer-kind=', $html);

        // Owned free lesson still reachable.
        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk();

        $event = ActivityEvent::where('event_type', ActivityEvent::CABINET_HOME_VIEW)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        $this->assertSame('recovery', $event->event_data['mode'] ?? null);
        $this->assertSame(RecoveryState::REASON_DECLINED_PAYMENT, $event->event_data['reason'] ?? null);
    }

    /** @test */
    public function pending_payment_is_not_recovery(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'pending',
            'is_conditional' => false,
        ]));

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertFalse($state->active);
        $this->assertFalse($state->suppressOffers());
        $this->assertSame(RecoveryState::REASON_NONE, $state->reason);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('data-cabinet-mode="recovery"', false);
    }

    /** @test */
    public function later_paid_payment_clears_declined_recovery(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        Payment::withoutEvents(function () use ($user, $course) {
            $failed = Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => 'block_2',
                'status' => 'failed',
                'is_conditional' => false,
            ]);
            // Pin timestamps after create — mass-assignment of updated_at is unreliable
            // under withoutEvents + model events off.
            $failed->forceFill([
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ])->saveQuietly();

            $paid = Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => 'block_2',
                'status' => 'paid',
                'is_conditional' => false,
            ]);
            $paid->forceFill([
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ])->saveQuietly();
        });

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertFalse($state->active, 'Successful re-pay must clear recovery');
    }

    /** @test */
    public function expired_promise_enters_recovery(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(3),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]));

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertTrue($state->active);
        $this->assertSame(RecoveryState::REASON_EXPIRED_PROMISE, $state->reason);
        $this->assertTrue($state->suppressOffers());
    }

    /**
     * Twin of later_paid_payment_clears_declined_recovery for the promise branch.
     *
     * A promise only flips to STATUS_FULFILLED when the payment carries an explicit
     * linked_promise_id, or an admin closes that exact promise. Settling the same
     * course by any other route leaves the row at STATUS_EXPIRED forever — so without
     * a clearance check the student is held in recovery mode indefinitely with every
     * offer suppressed, despite having paid. On the pre-fix resolver this test fails
     * with the state still active.
     *
     * @test
     */
    public function later_paid_payment_clears_expired_promise_recovery(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(30),
            'amount' => 4800,
            // Left at STATUS_EXPIRED on purpose: this is the real-world shape when the
            // settling payment never carried linked_promise_id.
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]));

        Payment::withoutEvents(function () use ($user, $course) {
            $paid = Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => 'block_2',
                'status' => 'paid',
                'is_conditional' => false,
            ]);
            $paid->forceFill([
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ])->saveQuietly();
        });

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertFalse(
            $state->active,
            'A later real payment on the same course must clear expired-promise recovery'
        );
        $this->assertFalse($state->suppressOffers());
    }

    /** @test */
    public function unpaid_expired_promise_still_suppresses_offers(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithCourse();

        PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(30),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]));

        // A payment BEFORE the promise date cannot be what settles it, and a
        // conditional payment is not real money — neither may clear the fault.
        Payment::withoutEvents(function () use ($user, $course) {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => 'block_1',
                'status' => 'paid',
                'is_conditional' => false,
            ])->forceFill([
                'created_at' => now()->subDays(90),
                'updated_at' => now()->subDays(90),
            ])->saveQuietly();

            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => 'block_2',
                'status' => 'paid',
                'is_conditional' => true,
            ])->forceFill([
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ])->saveQuietly();
        });

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertTrue($state->active, 'An unsettled expired promise must still suppress offers');
        $this->assertSame(RecoveryState::REASON_EXPIRED_PROMISE, $state->reason);
    }

    /** @test */
    public function homework_rework_appears_in_today_band_only_when_returned(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course, 'lesson' => $lesson] = $this->studentWithCourse();

        // Without returned homework — no rework slot.
        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('data-today-homework-rework', false);

        HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'last_activity_at' => now(),
            'reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-today-homework-rework', false)
            ->assertSee('Доработать домашнее', false)
            ->assertSee($lesson->title, false);
    }

    /** @test */
    public function offer_precedence_recovery_suppresses_before_any_primary_offer(): void
    {
        // Mirrors speka §1 ladder: recovery → zero offers (stronger than any
        // ladder / next-block / ownership impression).
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'canceled',
            'is_conditional' => false,
        ]));

        $state = app(RecoveryStateResolver::class)->resolve($user);
        $this->assertTrue($state->suppressOffers());
        // Phase 1 has no primary-offer renderer yet; the predicate is the gate
        // every Phase 2–3 offer surface must call.
        $this->assertSame('recovery', $state->mode());
    }
}
