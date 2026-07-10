<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TrialZoomLinkMail;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrialPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const ZOOM = 'https://zoom.us/j/123456';

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['services.lesson_sync.secret' => self::SECRET]);
    }

    /**
     * Курс с настроенным пробным занятием: событие расписания + цена.
     * Сохранение курса создаёт урок-заготовку и проставляет trial_lesson_id.
     *
     * @return array{0: Course, 1: Schedule}
     */
    private function courseWithTrial(float $price = 500, ?Carbon $start = null): array
    {
        $course = Course::factory()->create(['slug' => 'grammatika-hindi-sreda']);
        $schedule = Schedule::create([
            'title' => 'Грамматика хинди (среда 7:00)',
            'course_id' => $course->id,
            'group_id' => null,
            'start' => $start ?? now()->addDays(3)->setTime(7, 0),
            'link' => self::ZOOM,
        ]);

        $course->update(['trial_price' => $price, 'trial_schedule_id' => $schedule->id]);

        return [$course->fresh(), $schedule];
    }

    /** @test */
    public function saving_course_creates_placeholder_lesson_linked_as_trial(): void
    {
        [$course, $schedule] = $this->courseWithTrial();

        $this->assertNotNull($course->trial_lesson_id, 'trial_lesson_id должен быть проставлен.');

        $lesson = Lesson::find($course->trial_lesson_id);
        $this->assertNotNull($lesson);
        $this->assertEquals($course->id, $lesson->course_id);
        $this->assertNull($lesson->group_id);
        $this->assertSame($schedule->start->toDateString(), $lesson->lesson_date->toDateString());

        // Повторное сохранение не плодит дубль заготовки.
        $course->update(['title' => $course->title.' (ред.)']);
        $this->assertSame(1, Lesson::where('course_id', $course->id)
            ->whereDate('lesson_date', $schedule->start->toDateString())->count());
    }

    /** @test */
    public function n8n_fills_placeholder_without_duplicate(): void
    {
        [$course, $schedule] = $this->courseWithTrial();
        $date = $schedule->start->toDateString();

        $this->postJson('/api/lessons/from-zoom', [
            'course_id' => $course->id,
            'group_id' => null,
            'title' => 'Запись занятия',
            'lesson_date' => $date,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ], ['X-Secret-Key' => self::SECRET])->assertOk();

        // Та же строка обновилась — дубля нет, видео залилось.
        $this->assertSame(1, Lesson::where('course_id', $course->id)->whereDate('lesson_date', $date)->count());
        $this->assertNotNull(Lesson::find($course->trial_lesson_id)->youtube_url);
    }

    /** @test */
    public function guest_buys_trial_creates_pending_payment_and_redirects(): void
    {
        [$course] = $this->courseWithTrial(500);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/abc', 'paymentLinkId' => 'pl_1'],
        ], 200)]);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Иванов', 'name' => 'Иван', 'email' => 'guest@example.test', 'city' => 'Москва',
        ])->assertRedirect('https://pay.tochka/abc');

        $this->assertDatabaseHas('payments', [
            'course_id' => $course->id, 'tariff' => 'trial', 'status' => 'pending', 'amount' => 500,
        ]);
        // ФИО+город склеены в name — как на чекауте.
        $this->assertDatabaseHas('users', ['email' => 'guest@example.test', 'name' => 'Иванов Иван, Москва']);
    }

    /** @test */
    public function trial_unavailable_without_schedule_or_price(): void
    {
        $course = Course::factory()->create(['slug' => 'no-trial', 'trial_price' => null, 'trial_schedule_id' => null]);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Иванов', 'name' => 'Иван', 'email' => 'g@example.test', 'city' => 'Москва',
        ])->assertForbidden();
    }

    /** @test */
    public function paid_trial_grants_access_and_sends_zoom_email(): void
    {
        [$course] = $this->courseWithTrial();
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        $this->assertEquals(1, LessonAccessGrant::where('user_id', $user->id)
            ->where('lesson_id', $course->trial_lesson_id)->active()->count());

        Mail::assertQueued(TrialZoomLinkMail::class, fn ($m) => $m->hasTo($user->email));
    }

    /** @test */
    public function paid_trial_for_past_class_grants_recording_access_and_marks_email_as_recording(): void
    {
        // Прошедшее занятие: пробное открывает его запись, а не живой Zoom.
        [$course] = $this->courseWithTrial(500, now()->subDays(3)->setTime(7, 0));
        $user = User::factory()->create();

        // Заготовка под прошедшую дату всё равно создана и привязана.
        $this->assertNotNull($course->trial_lesson_id);

        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        // Доступ к уроку-заготовке (записи) открыт.
        $this->assertEquals(1, LessonAccessGrant::where('user_id', $user->id)
            ->where('lesson_id', $course->trial_lesson_id)->active()->count());

        // Письмо помечено как «запись», без Zoom-ссылки (хоть у события она и задана).
        Mail::assertQueued(TrialZoomLinkMail::class, fn ($m) => $m->hasTo($user->email)
            && $m->isRecording === true
            && $m->zoomLink === null);
    }

    /** @test */
    public function lesson_page_shows_join_panel_before_recording(): void
    {
        [$course] = $this->courseWithTrial();
        $buyer = User::factory()->create();
        Payment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        // Кнопка ведёт на трекинг-редирект (учёт посещаемости), а не на сырой Zoom-URL.
        $this->actingAs($buyer)
            ->get(route('student.lesson', [$course->slug, $course->trial_lesson_id]))
            ->assertOk()
            ->assertSee(route('class.join', $course->trial_schedule_id), escape: false)
            ->assertSee('Подключиться к Zoom');
    }

    /** @test */
    public function trial_amount_credits_and_is_consumed_by_real_purchase(): void
    {
        [$course] = $this->courseWithTrial(500);
        $user = User::factory()->create();

        $trial = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        $this->assertEquals(500, (float) Payment::query()
            ->where('user_id', $user->id)->where('course_id', $course->id)
            ->unconsumedDeposits()->sum('amount'));

        $full = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 9000, 'tariff' => 'full', 'status' => 'pending',
        ]);
        $full->consumeDepositsForCourse();

        $this->assertNotNull($trial->fresh()->deposit_consumed_at);
    }

    /** @test */
    public function guest_with_existing_email_is_rejected(): void
    {
        [$course] = $this->courseWithTrial();
        User::factory()->create(['email' => 'taken@example.test']);

        // Ошибка уходит в bag `trial` — по нему модалка на витрине открывается
        // заново с сообщением (иначе редирект выглядел бы как «тихая перезагрузка»).
        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Иванов', 'name' => 'Иван', 'email' => 'taken@example.test', 'city' => 'Москва',
        ])
            ->assertSessionHasErrors('email', null, 'trial')
            ->assertRedirect();

        $this->assertDatabaseMissing('payments', ['course_id' => $course->id, 'tariff' => 'trial']);
    }

    /** @test */
    public function invalid_guest_input_reports_errors_in_trial_bag(): void
    {
        // Пустые поля (не email-конфликт) тоже должны попасть в bag `trial`,
        // чтобы модалка распознала ошибку и открылась, а не «молча» перезагрузилась.
        [$course] = $this->courseWithTrial();

        $this->post(route('trial.create', $course->slug), [
            'surname' => '', 'name' => '', 'email' => 'not-an-email', 'city' => '',
        ])->assertSessionHasErrors(['surname', 'name', 'email', 'city'], null, 'trial');
    }
}
