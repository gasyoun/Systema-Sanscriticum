<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Http\Middleware\ThrottleLivewireUpdates;
use App\Models\Course;
use App\Models\GameEvent;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\PranaTransaction;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewLog;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Srs\Fsrs;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use App\Support\Impersonation;
use App\Support\Roles;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5087 — регрессion-тесты моп-апа семи medium/low confirmed записей
 * аудита H5046: purpose-фенс /magic, имперсонационные префиксы
 * prana/access/membership, group-visibility гейт ДЗ, скоуп
 * AttendanceDashboard, deck-скоуп + дневной потолок праны SrsReview,
 * charset-фенс игрового телеметри-контента, throttle promo/remove и
 * /livewire/update.
 */
class H5087MopUpRegressionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // 1. /magic consumes ONLY newsletter-purpose tokens
    // ------------------------------------------------------------------

    /** @test */
    public function magic_rejects_foreign_purpose_tokens(): void
    {
        config(['features.newsletter_subscribe' => true]);
        $victim = User::factory()->create();

        $token = MagicLinkToken::issueFor($victim, 'admin_unblock');

        $this->get("/magic/{$token}")->assertNotFound();
        $this->assertGuest();
        // Token NOT consumed — still spendable on its own route.
        $row = MagicLinkToken::where('purpose', 'admin_unblock')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);
    }

    /** @test */
    public function magic_still_logs_in_with_newsletter_purpose_token(): void
    {
        config(['features.newsletter_subscribe' => true]);
        $user = User::factory()->create();

        $token = MagicLinkToken::issueFor($user, 'newsletter');

        $this->get("/magic/{$token}")
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    // ------------------------------------------------------------------
    // 2. Impersonation fence covers prana/access/membership
    // ------------------------------------------------------------------

    /** @test */
    public function impersonation_fence_blocks_prana_access_membership_writes(): void
    {
        foreach ([
            'student.prana.transfer',
            'student.prana.redeem',
            'student.access.materialize',
            'student.membership.cancel',
            'student.membership.resume',
        ] as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} must exist");
            $request = Request::create('/'.$route->uri(), 'POST');
            $request->setRouteResolver(fn () => $route);

            $this->assertTrue(Impersonation::isBlockedWrite($request), "POST {$name} must be blocked in impersonation mode");
        }
    }

    /** @test */
    public function impersonation_fence_keeps_legacy_checkout_block(): void
    {
        $route = RouteFacade::getRoutes()->getByName('checkout.promo');
        $request = Request::create('/'.$route->uri(), 'POST');
        $request->setRouteResolver(fn () => $route);

        $this->assertTrue(Impersonation::isBlockedWrite($request));
    }

    // ------------------------------------------------------------------
    // 3. Homework gate enforces group visibility (cross-stream 403)
    // ------------------------------------------------------------------

    /** @test */
    public function homework_submission_to_foreign_stream_lesson_is_403(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['services.telegram.student_bot_token' => 'TESTTOKEN']);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');

        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'hw-h5087@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);

        $g60 = Group::create(['name' => 'Поток 60']);
        $g62 = Group::create(['name' => 'Поток 62']);

        $foreignLesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => false,
            'block_number' => 2,
            'group_id' => $g60->id,
        ]);

        $student = User::factory()->create();
        $g62->users()->attach($student->id);

        // Paid block_2 key on the course — gate must STILL 403 on group rule.
        Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        $this->actingAs($student)
            ->post(route('student.homework.store', [$course->slug, $foreignLesson->id]), [
                'action' => 'submit',
                'body' => 'чужой поток',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('homework_submissions', [
            'user_id' => $student->id,
            'lesson_id' => $foreignLesson->id,
        ]);
    }

    // ------------------------------------------------------------------
    // 4. AttendanceDashboard teacher scoping
    // ------------------------------------------------------------------

    /** @test */
    public function attendance_dashboard_service_scopes_teacher_to_own_courses(): void
    {
        $t1 = Teacher::create(['name' => 'T1', 'email' => 't1-h5087@example.test']);
        $t2 = Teacher::create(['name' => 'T2', 'email' => 't2-h5087@example.test']);

        $c1 = Course::factory()->create(['teacher_id' => $t1->id]);
        $c2 = Course::factory()->create(['teacher_id' => $t2->id]);

        $s1 = User::factory()->create(['name' => 'Студент T1']);
        $s2 = User::factory()->create(['name' => 'Студент T2']);
        $g1 = Group::create(['name' => 'Группа T1']);
        $g2 = Group::create(['name' => 'Группа T2']);
        $g1->users()->attach($s1->id);
        $g2->users()->attach($s2->id);

        foreach ([[$g1, $c1, $s1], [$g2, $c2, $s2]] as [$g, $c, $s]) {
            $schedule = \App\Models\Schedule::create([
                'title' => 'Занятие',
                'start' => now()->subDay(),
                'group_id' => $g->id,
                'course_id' => $c->id,
            ]);
            \App\Models\WebinarAttendance::create([
                'schedule_id' => $schedule->id,
                'user_id' => $s->id,
                'zoom_participant_uuid' => 'u'.$s->id,
                'joined_at' => now()->subDay(),
                'duration_seconds' => 1800,
            ]);
        }

        $svc = app(\App\Services\ClassAttendanceService::class);
        $from = now()->subDays(7);
        $to = now();

        $scoped = $svc->dashboard($from, $to, 3, (int) $t1->id);
        $scopedIds = $scoped['students']->pluck('user.id')->all();
        $this->assertContains($s1->id, $scopedIds);
        $this->assertNotContains($s2->id, $scopedIds);

        $wide = $svc->dashboard($from, $to, 3, null);
        $wideIds = $wide['students']->pluck('user.id')->all();
        $this->assertContains($s1->id, $wideIds);
        $this->assertContains($s2->id, $wideIds);
    }

    /** @test */
    public function attendance_dashboard_hides_money_and_roster_from_teacher(): void
    {
        config(['features.attendance_dashboard' => true]);
        $teacher = Teacher::create(['name' => 'T1', 'email' => 't1b-h5087@example.test']);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        $component = Livewire::test(\App\Filament\Pages\AttendanceDashboard::class);
        $page = $component->instance();

        $this->assertSame([], $page->canvasMoney()['rows']);
        $this->assertSame([], $page->canvasRoster());
        // Own-scope report still renders (empty school => empty rows is fine):
        $this->assertIsArray($page->report());
    }

    // ------------------------------------------------------------------
    // 5. SrsReview daily prana cap (+ deck-scope regression is a unit fact)
    // ------------------------------------------------------------------

    private function makeDeck(int $cardCount = 3): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic_h5087',
            'name' => 'Санскрит H5087',
            'language' => 'sa',
            'fields' => ['devanagari', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Test deck H5087',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        for ($i = 0; $i < $cardCount; $i++) {
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => ['devanagari' => "पद{$i}", 'translation' => "meaning {$i}"],
            ]);
        }

        return $deck;
    }

    /** @test */
    public function srs_review_daily_cap_stops_awarding_but_grades_still_write(): void
    {
        config(['prana.srs_review_daily_cap' => 2]);

        $user = User::factory()->create();
        $cards = $this->makeDeck(3)->cards()->get();
        $service = new ReviewService(new Fsrs(enableFuzzing: false));

        foreach ($cards as $i => $card) {
            $service->grade($user, $card, Rating::Good, null, new DateTimeImmutable('now'));
        }

        // 3 log rows written (grading itself is never capped)...
        $this->assertSame(3, SrsReviewLog::where('user_id', $user->id)->count());
        // ...but only 2 prana awards (cap).
        $this->assertSame(2, PranaTransaction::where('user_id', $user->id)->where('reason', 'srs_review')->count());
        $expected = 2 * (int) config('prana.rewards.srs_review');
        $this->assertSame($expected, $user->fresh()->prana_balance);
    }

    /** @test */
    public function srs_review_without_cap_awards_every_grade(): void
    {
        config(['prana.srs_review_daily_cap' => 0]);

        $user = User::factory()->create();
        $cards = $this->makeDeck(3)->cards()->get();
        $service = new ReviewService(new Fsrs(enableFuzzing: false));

        foreach ($cards as $card) {
            $service->grade($user, $card, Rating::Good, null, new DateTimeImmutable('now'));
        }

        $this->assertSame(3, PranaTransaction::where('user_id', $user->id)->where('reason', 'srs_review')->count());
    }

    // ------------------------------------------------------------------
    // 6. Game telemetry charset fence
    // ------------------------------------------------------------------

    /** @test */
    public function game_telemetry_strips_formula_and_markup_characters(): void
    {
        $this->postJson('/api/games/event', [
            'anon_id' => 'h5087anon',
            'drill' => '<roots>',
            'band' => '=top',
            'event' => 'item_seen',
            'payload' => ['items' => [
                ['iast' => '=HYPERLINK("https://evil")', 'ru' => '<script>alert(1)</script>идти'],
            ]],
        ])->assertNoContent();

        $row = GameEvent::where('anon_id', 'h5087anon')->latest('id')->first();
        $this->assertNotNull($row);

        $iast = (string) ($row->payload['items'][0]['iast'] ?? '');
        $ru = (string) ($row->payload['items'][0]['ru'] ?? '');

        $this->assertStringNotContainsString('=', $iast);
        $this->assertStringNotContainsString('"', $iast);
        $this->assertStringNotContainsString('<', $ru);
        $this->assertStringNotContainsString('>', $ru);
        $this->assertStringNotContainsString('<', (string) $row->drill);
        // Legit Cyrillic content survives.
        $this->assertStringContainsString('идти', $ru);
    }

    // ------------------------------------------------------------------
    // 7. Throttles: promo/remove route + /livewire/update middleware
    // ------------------------------------------------------------------

    /** @test */
    public function promo_remove_route_carries_throttle(): void
    {
        $route = RouteFacade::getRoutes()->getByName('checkout.promo.remove');
        $this->assertNotNull($route);
        $this->assertContains('throttle:10,1', (array) $route->gatherMiddleware());
    }

    /** @test */
    public function livewire_update_throttle_middleware_enforces_named_limiter(): void
    {
        RateLimiter::clear(sha1('livewire|127.0.0.1'));

        $mw = new ThrottleLivewireUpdates;
        $request = Request::create('/livewire/update', 'POST');

        $throttled = false;
        for ($i = 0; $i < 61; $i++) {
            try {
                $response = $mw->handle($request, fn () => response('ok'));
                $throttled = $response->status() === 429;
            } catch (\Illuminate\Http\Exceptions\ThrottleRequestsException) {
                $throttled = true; // 429 surfaces as an exception without the exception handler
            }
        }
        $this->assertTrue($throttled, '61st /livewire/update hit must be 429');

        // Control: a non-livewire path is never throttled by this middleware.
        $other = Request::create('/checkout/1', 'GET');
        $status = $mw->handle($other, fn () => response('ok'))->status();
        $this->assertSame(200, $status);
    }
}
