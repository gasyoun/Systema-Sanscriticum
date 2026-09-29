<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\CourseInterestRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H5066 — публичная форма интереса на курс (/interest/{course}).
 *
 * Инварианты: всё за флагом features.course_interest_form (OFF → 404); три
 * интента (join/recording/revive); заявка пишется в course_interest_requests
 * и уходит TG-уведомлением кураторам; анти-спам как в формах анкеты
 * (honeypot + time-trap + rate-limit 1/5 сек/IP); никаких платежей;
 * PII в логи не пишется (логов в контроллере нет вовсе).
 */
class CourseInterestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RateLimiter::clear('course-interest:127.0.0.1');
        config([
            'features.course_interest_form' => true,
            'services.telegram.curators_chat_id' => '12345',
        ]);
    }

    /** Зашифрованная метка времени рендера формы «N секунд назад» (time-trap). */
    private function timeTrap(int $secondsAgo = 5): string
    {
        return encrypt((string) (now()->timestamp - $secondsAgo));
    }

    /** Валидный «человеческий» payload: пустой honeypot + свежая метка времени. */
    private function humanPayload(array $overrides = []): array
    {
        return array_merge([
            'intent' => CourseInterestRequest::INTENT_JOIN,
            'name' => 'Тест Тестов',
            'email' => 'human@example.com',
            'telegram' => null,
            'comment' => null,
            'website' => '', // honeypot пуст
            'ff_ts' => $this->timeTrap(),
        ], $overrides);
    }

    public function test_flag_off_404s_show_and_store(): void
    {
        config(['features.course_interest_form' => false]);

        $this->get('/interest/nale')->assertNotFound();
        $this->get('/interest/nale/embed')->assertNotFound();
        $this->post('/interest/nale', $this->humanPayload())->assertNotFound();

        $this->assertSame(0, CourseInterestRequest::query()->count());
    }

    public function test_show_renders_registered_course_by_slug(): void
    {
        Course::factory()->create(['title' => 'Сказания о Нале', 'slug' => 'nale']);

        $this->get('/interest/nale')
            ->assertOk()
            ->assertSee('Сказания о Нале')
            ->assertSee('Возобновить занятия');
    }

    public function test_store_creates_request_for_registered_course_and_notifies_curators(): void
    {
        $course = Course::factory()->create(['title' => 'Сказания о Нале', 'slug' => 'nale']);

        $response = $this->post('/interest/nale', $this->humanPayload([
            'intent' => CourseInterestRequest::INTENT_REVIVE,
            'telegram' => '@tester',
            'email' => null,
        ]));

        $response->assertRedirect(route('course-interest.show', ['course' => 'nale']))
            ->assertSessionHas('course_interest_status');

        $request = CourseInterestRequest::query()->sole();
        $this->assertSame($course->id, $request->course_id);
        $this->assertSame('', $request->course_title);
        $this->assertSame(CourseInterestRequest::INTENT_REVIVE, $request->intent);
        $this->assertSame('@tester', $request->telegram);
        $this->assertNull($request->email);
        $this->assertSame(CourseInterestRequest::STATUS_NEW, $request->status);
        $this->assertNotNull($request->ip_address);

        // TG-уведомление кураторам ушло (чат настроен в setUp).
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => $job->chatId === '12345'
            && str_contains($job->text, 'Сказания о Нале')
            && str_contains($job->text, 'Возобновить занятия'));
    }

    public function test_store_accepts_unregistered_course_title_only(): void
    {
        // «Космография» Леонченко — анонс без карточки курса: slug есть только в URL.
        $this->post('/interest/kosmografiya', $this->humanPayload([
            'intent' => CourseInterestRequest::INTENT_JOIN,
        ]))->assertRedirect();

        $request = CourseInterestRequest::query()->sole();
        $this->assertNull($request->course_id);
        $this->assertSame('Kosmografiya', $request->course_title);
    }

    public function test_store_requires_email_or_telegram(): void
    {
        $this->post('/interest/nale', $this->humanPayload(['email' => null, 'telegram' => null]))
            ->assertSessionHasErrors(['email', 'telegram']);

        $this->assertSame(0, CourseInterestRequest::query()->count());
    }

    public function test_store_rejects_unknown_intent(): void
    {
        $this->post('/interest/nale', $this->humanPayload(['intent' => 'money']))
            ->assertSessionHasErrors(['intent']);

        $this->assertSame(0, CourseInterestRequest::query()->count());
    }

    public function test_honeypot_faked_success_but_no_record_and_no_notification(): void
    {
        Course::factory()->create(['slug' => 'nale']);

        $response = $this->post('/interest/nale', $this->humanPayload(['website' => 'spam.example']));

        // Тот же «успешный» ответ (анти-enumeration), но записи нет.
        $response->assertRedirect()->assertSessionHas('course_interest_status');
        $this->assertSame(0, CourseInterestRequest::query()->count());
        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    public function test_time_trap_missing_or_instant_rejects_silently(): void
    {
        Course::factory()->create(['slug' => 'nale']);

        // Прямой POST без метки.
        $this->post('/interest/nale', $this->humanPayload(['ff_ts' => null]))
            ->assertRedirect();
        RateLimiter::clear('course-interest:127.0.0.1'); // отдельный сабмит, не лимит-тест

        // Мгновенный сабмит (метка «0 секунд назад»).
        $this->post('/interest/nale', $this->humanPayload(['ff_ts' => $this->timeTrap(0)]))
            ->assertRedirect();
        RateLimiter::clear('course-interest:127.0.0.1');

        // Протухшая форма: метка старше max_form_age_seconds (12 ч по умолчанию).
        $this->post('/interest/nale', $this->humanPayload([
            'ff_ts' => $this->timeTrap((int) config('newsletter.antibot.max_form_age_seconds') + 600),
        ]))->assertRedirect();

        $this->assertSame(0, CourseInterestRequest::query()->count());
    }

    public function test_rate_limit_blocks_rapid_second_submit(): void
    {
        Course::factory()->create(['slug' => 'nale']);

        $this->post('/interest/nale', $this->humanPayload(['email' => 'a@example.com']))->assertRedirect();
        $this->post('/interest/nale', $this->humanPayload(['email' => 'b@example.com']))
            ->assertStatus(429);

        $this->assertSame(1, CourseInterestRequest::query()->count());
    }

    public function test_no_notification_when_curators_chat_unset(): void
    {
        config(['services.telegram.curators_chat_id' => null]);
        Course::factory()->create(['slug' => 'nale']);

        $this->post('/interest/nale', $this->humanPayload())->assertRedirect();

        $this->assertSame(1, CourseInterestRequest::query()->count());
        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    public function test_counts_for_course_and_revive_threshold_signal(): void
    {
        $course = Course::factory()->create(['slug' => 'nale', 'revive_threshold' => 2]);

        CourseInterestRequest::create([
            'course_id' => $course->id,
            'course_title' => '',
            'intent' => CourseInterestRequest::INTENT_REVIVE,
            'status' => CourseInterestRequest::STATUS_NEW,
        ]);
        CourseInterestRequest::create([
            'course_id' => $course->id,
            'course_title' => '',
            'intent' => CourseInterestRequest::INTENT_JOIN,
            'status' => CourseInterestRequest::STATUS_NEW,
        ]);

        $counts = CourseInterestRequest::countsForCourse($course->id);
        $this->assertSame(1, $counts[CourseInterestRequest::INTENT_REVIVE] ?? 0);
        $this->assertSame(1, $counts[CourseInterestRequest::INTENT_JOIN] ?? 0);

        // Вторая revive-заявка достигает порога — в TG-сообщении есть сигнал.
        $this->post('/interest/nale', $this->humanPayload([
            'intent' => CourseInterestRequest::INTENT_REVIVE,
            'email' => 'second@example.com',
        ]))->assertRedirect();

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => str_contains($job->text, 'порог возобновления (2) достигнут'));
    }

    public function test_embed_renders_standalone_minimal_form(): void
    {
        Course::factory()->create(['title' => 'Бюлер, учебник', 'slug' => 'buhler']);

        $response = $this->get('/interest/buhler/embed')
            ->assertOk()
            ->assertSee('Бюлер, учебник')
            ->assertSee('name="website"', false) // honeypot в разметке
            ->assertSee('<!DOCTYPE html>', false); // standalone-документ, не лэйаут сайта

        // H5094: CSP фиксируется ТОЧНЫМ значением, а не contains('frame-ancestors')
        // — ослабление allowlist до «frame-ancestors *» (кликджекинг формы из
        // любого origin) старая проверка пропускала. Литерал в тесте намеренно
        // не берётся из контроллера: тест должен ловить изменение константы.
        $this->assertSame(
            "frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru",
            (string) $response->headers->get('Content-Security-Policy')
        );

        // Запрещённый побочный эффект: CSP не «утекает» на обычную страницу —
        // ограничение рамки действует только на embed-ответ, site-wide
        // ослабления нет (контракт PublicWidgetController).
        $show = $this->get('/interest/buhler');
        $show->assertOk();
        $this->assertNull(
            $show->headers->get('Content-Security-Policy'),
            'embed-only CSP must not leak onto the show response'
        );

        // Хранимое состояние: embed — read-only, заявок не создаёт.
        $this->assertSame(0, CourseInterestRequest::query()->count());
    }
}
