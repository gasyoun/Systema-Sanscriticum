<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline-телеметрия ремейка кабинета (H962, Phase 0, спека §4):
 * серверные события, клиентский эндпоинт с белым списком, обсервер оплат,
 * readout-команда. Телеметрия не имеет права ломать страницы — все ассерты
 * ходят по штатным маршрутам кабинета.
 */
class CabinetTelemetryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function dashboard_emits_cabinet_home_view(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('student.dashboard'))->assertOk();

        $event = ActivityEvent::where('event_type', ActivityEvent::CABINET_HOME_VIEW)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($event, 'cabinet.home.view не записан');
        $this->assertSame('normal', $event->event_data['mode'] ?? null);
    }

    /** @test */
    public function telemetry_endpoint_accepts_whitelisted_client_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('student.telemetry'), [
                'event' => ActivityEvent::CABINET_CONTINUE_CLICK,
                'data' => ['kind' => 'lesson', 'surface' => 'continue-card'],
            ])
            ->assertNoContent();

        $event = ActivityEvent::where('event_type', ActivityEvent::CABINET_CONTINUE_CLICK)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('lesson', $event->event_data['kind'] ?? null);
    }

    /** @test */
    public function telemetry_endpoint_silently_drops_non_whitelisted_event(): void
    {
        $user = User::factory()->create();

        // Серверное имя (home.view) клиент прислать не может; мусорное — тем более.
        foreach ([ActivityEvent::CABINET_HOME_VIEW, 'evil.event'] as $event) {
            $this->actingAs($user)
                ->postJson(route('student.telemetry'), ['event' => $event])
                ->assertNoContent();
        }

        $this->assertSame(0, ActivityEvent::count());
    }

    /** @test */
    public function telemetry_endpoint_requires_auth(): void
    {
        $this->postJson(route('student.telemetry'), [
            'event' => ActivityEvent::CABINET_CONTINUE_CLICK,
        ])->assertUnauthorized();

        $this->assertSame(0, ActivityEvent::count());
    }

    /** @test */
    public function telemetry_endpoint_truncates_oversized_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('student.telemetry'), [
                'event' => ActivityEvent::OFFER_CLICK,
                'data' => array_merge(
                    array_combine(range('a', 'l'), range(1, 12)), // 12 ключей > лимита 8
                    ['kind' => str_repeat('x', 500)],
                ),
            ])
            ->assertNoContent();

        $event = ActivityEvent::where('event_type', ActivityEvent::OFFER_CLICK)->first();
        $this->assertNotNull($event);
        $this->assertLessThanOrEqual(8, count($event->event_data));
        foreach ($event->event_data as $value) {
            if (is_string($value)) {
                $this->assertLessThanOrEqual(120, mb_strlen($value));
            }
        }
    }

    /** @test */
    public function complete_lesson_emits_lesson_mark_mastered_once(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-telemetry']);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true]);
        $course->groups()->attach($group);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'is_free' => true,
        ]);

        $url = route('student.lesson.complete', [$course->slug, $lesson->id]);
        $this->actingAs($user)->post($url)->assertRedirect();
        // Повторное «усвоено» события не пишет (идемпотентность baseline-счётчика).
        $this->actingAs($user)->post($url)->assertRedirect();

        $this->assertSame(1, ActivityEvent::where('event_type', ActivityEvent::LESSON_MARK_MASTERED)
            ->where('user_id', $user->id)
            ->count());
    }

    /** @test */
    public function self_service_paid_payment_emits_access_renewal_complete(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'pending',
            'is_self_service' => true,
        ]);

        $payment->update(['status' => 'paid']);

        $event = ActivityEvent::where('event_type', ActivityEvent::ACCESS_RENEWAL_COMPLETE)->first();
        $this->assertNotNull($event, 'access.renewal.complete не записан');
        $this->assertSame($payment->id, $event->event_data['payment_id'] ?? null);
    }

    /** @test */
    public function manager_created_payment_does_not_emit_renewal_complete(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'pending',
            'is_self_service' => false,
        ]);

        $payment->update(['status' => 'paid']);

        $this->assertSame(0, ActivityEvent::where('event_type', ActivityEvent::ACCESS_RENEWAL_COMPLETE)->count());
    }

    /** @test */
    public function baseline_command_reports_all_spec_events(): void
    {
        $this->artisan('cabinet:baseline', ['--days' => 14])
            ->expectsOutputToContain('cabinet.home.view')
            ->expectsOutputToContain('lesson.view.heartbeat')
            ->expectsOutputToContain('cabinet.live.zoom.click')
            ->expectsOutputToContain('support.topic.pick')
            ->assertSuccessful();
    }
}
