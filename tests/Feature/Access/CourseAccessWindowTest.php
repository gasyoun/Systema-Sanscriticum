<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\CourseAccessWindow;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4456 — окна доступа course_access_windows.
 *
 * Рулинг MG 09-09-2026 (вербатим): «сказать 18 дней и отрезать на 19й день,
 * не надо к курсам Парибка вечный доступ, если не оговорено конкретно у кого
 * такой исключение и вечный доступ».
 *
 * Реальный платёж (is_conditional=false) даёт ключ навсегда — окно доступа
 * с истёкшим ends_at закрывает его ПО ДАТЕ, не трогая строки платежей.
 * ends_at = NULL — вечное именное исключение. Нет строки — прежнее поведение.
 */
class CourseAccessWindowTest extends TestCase
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

    private function grantRealPayment(string $tariff = 'full'): Payment
    {
        return Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 5500,
            'tariff' => $tariff,
            'status' => 'paid',
            'is_conditional' => false,
        ]);
    }

    /** @test */
    public function no_window_row_keeps_the_current_unlocking_behavior(): void
    {
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment();

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function flag_off_keeps_the_current_unlocking_behavior(): void
    {
        config()->set('features.course_access_windows', false);

        $this->grantRealPayment();
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->subDay(), 'истёкшее окно');

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function expired_window_cuts_real_payment_keys_when_enabled(): void
    {
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment();
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->subDay(), 'MG 09-09: 18 дней');

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertNotContains('full', $keys);
        $this->assertSame([], $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertRedirect(route('student.course', $this->course->slug));
    }

    /** @test */
    public function live_window_still_opens_lessons_when_enabled(): void
    {
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment();
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addDays(18), '18 дней');

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function forever_window_is_the_named_eternal_exception(): void
    {
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment();
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, null, 'именное исключение');

        // Дата далеко в будущем: вечное окно доступ не режет никогда.
        $this->travelTo(now()->addYears(3));

        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        $this->assertContains('full', $keys);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function window_is_scoped_to_its_own_course(): void
    {
        config()->set('features.course_access_windows', true);

        $otherCourse = Course::factory()->create();
        $otherGroup = Group::create(['name' => 'Поток '.$otherCourse->id]);
        $otherCourse->groups()->attach($otherGroup->id);
        $this->student->groups()->syncWithoutDetaching([$otherGroup->id]);
        Lesson::factory()->create([
            'course_id' => $otherCourse->id,
            'group_id' => $otherGroup->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        $this->grantRealPayment('block_1');
        Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $otherCourse->id,
            'amount' => 5500,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]);

        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->subDay(), 'окно только на первый курс');

        $this->assertSame(
            [],
            StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug),
        );
        $this->assertContains(
            'full',
            StudentController::getUserUnlockedTariffs($this->student->id, $otherCourse->slug),
        );
    }

    /** @test */
    public function expiry_is_date_driven_not_status_driven(): void
    {
        // Окно «истекает само»: никакого демона нет — предикат смотрит на ДАТУ,
        // как H4396 (окно до дневного прогона не нужно вовсе).
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment();
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addMinute(), 'час X');

        $this->assertTrue(
            StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug) !== [],
        );

        $this->travelTo(now()->addMinutes(2));

        $this->assertSame(
            [],
            StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug),
        );
    }

    /** @test */
    public function expired_window_frees_the_course_for_repurchase(): void
    {
        // Магазин/каталог: «уже куплено» больше не блокирует повторную покупку
        // курса с истёкшим окном (иначе замкнутый круг: доступ закрыт, купить нельзя).
        config()->set('features.course_access_windows', true);

        $payment = $this->grantRealPayment('block_1');
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->subDay());

        $stillOwned = Payment::query()
            ->real()
            ->where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->paid()
            ->withoutExpiredAccessWindow()
            ->pluck('tariff')
            ->all();
        $this->assertSame([], $stillOwned);

        CourseAccessWindow::clearFor($this->student->id, $this->course->id);

        $ownedAfterClear = Payment::query()
            ->real()
            ->where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->paid()
            ->withoutExpiredAccessWindow()
            ->pluck('tariff')
            ->all();
        $this->assertSame(['block_1'], $ownedAfterClear, 'снятие окна возвращает владение');

        unset($payment);
    }

    /** @test */
    public function api_cabinet_applies_the_same_window_predicate(): void
    {
        config()->set('features.course_access_windows', true);

        $this->grantRealPayment('block_1');
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->subHour());

        $response = $this->actingAs($this->student)
            ->getJson('/api/v1/courses/'.$this->course->slug.'/lessons');
        $response->assertOk();

        $locked = collect($response->json('lessons'))->pluck('locked', 'id');
        $this->assertTrue($locked[$this->lesson->id]);
    }

    /** @test */
    public function set_until_upserts_and_clear_for_removes(): void
    {
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addDays(9), 'первая');
        CourseAccessWindow::setUntil($this->student->id, $this->course->id, now()->addDays(18), 'вторая');

        $this->assertSame(1, CourseAccessWindow::query()->count());
        $this->assertSame('вторая', CourseAccessWindow::query()->first()->reason);

        $this->assertSame(1, CourseAccessWindow::clearFor($this->student->id, $this->course->id));
        $this->assertSame(0, CourseAccessWindow::query()->count());
        $this->assertFalse(CourseAccessWindow::hasExpiredFor($this->student->id, $this->course->id));
    }

    /** @test */
    public function access_set_window_command_sets_and_revokes(): void
    {
        $this->artisan('access:set-window', [
            'user' => $this->student->id,
            'course' => $this->course->id,
            '--until' => '2026-09-27 23:59',
            '--reason' => 'MG 09-09: 18 дней',
            '--by' => '1',
        ])->assertSuccessful();

        $window = CourseAccessWindow::query()->firstOrFail();
        $this->assertSame('2026-09-27 23:59', $window->ends_at->format('Y-m-d H:i'));
        $this->assertTrue($window->isActive());

        $this->artisan('access:set-window', [
            'user' => $this->student->id,
            'course' => $this->course->id,
            '--revoke' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CourseAccessWindow::query()->count());
    }
}
