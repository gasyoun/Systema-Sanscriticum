<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * zapisi:remind-classes — напоминание о занятии в чат группы через @zapisi_ORSbot
 * прямо из расписания (Schedule → group.telegram_chat_id). Заменяет ручную
 * таблицу zapisi_class_schedules. Гейт telegram_zapisi_bot, дедуп zapisi_reminded_at.
 */
class RemindZapisiClassesTest extends TestCase
{
    use RefreshDatabase;

    private function enable(int $lead = 60): void
    {
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_reminder_lead_minutes' => $lead]);
    }

    public function test_reminds_group_chat_once_and_dedups(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 61', 'telegram_chat_id' => '-1001234567890']);
        $schedule = Schedule::create([
            'title' => 'Грамматика',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/61',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === '-1001234567890'
            && str_contains($job->text, 'Грамматика')
            && str_contains($job->text, 'Группа 61'));

        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);

        // Повторный прогон не шлёт второй раз (дедуп).
        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    /**
     * Сообщение уходит с parse_mode=HTML, поэтому один амперсанд в названии курса
     * заставлял Telegram отвергнуть ЗАПРОС ЦЕЛИКОМ («can't parse entities») —
     * напоминание не приходило вообще, и наружу это никак не проявлялось.
     */
    public function test_special_characters_in_names_are_escaped(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Хинди & урду', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'Грамматика <начальная> & чтение',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/esc',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return str_contains($job->text, 'Грамматика &lt;начальная&gt; &amp; чтение')
                && str_contains($job->text, 'Хинди &amp; урду')
                // Разметку шаблона экранирование не трогает.
                && str_contains($job->text, '<b>');
        });
    }

    /** Разметка из шаблона доходит как есть — это и даёт форматирование. */
    public function test_custom_template_keeps_its_markup(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create([
            'zapisi_reminder_lead_minutes' => 60,
            'zapisi_reminder_template' => "<b>{title}</b>\n<i>{group}</i> в {time}\n{join}",
        ]);

        $group = Group::create(['name' => 'Группа 61', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'Грамматика',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://us06web.zoom.us/j/123',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return str_contains($job->text, '<b>Грамматика</b>')
                && str_contains($job->text, '<i>Группа 61</i>')
                && str_contains($job->text, '<a href="https://us06web.zoom.us/j/123">Подключиться к занятию</a>');
        });
    }

    /**
     * Инцидент 02-09-2026 (schedule 1620): серия без ссылок — в чат ушло
     * «Подключится к занятию можно по ссылке:» без самой ссылки. Теперь без
     * ссылки напоминание не шлётся вовсе (зеркально classes:post-group-link)
     * и НЕ помечается — уйдёт, как только ссылка появится.
     */
    public function test_skips_schedule_without_any_link_and_leaves_unmarked(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 1', 'telegram_chat_id' => '-100']);
        $schedule = Schedule::create(['title' => 'Грамматика', 'start' => now()->addMinutes(10), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    /** Ссылка с курса — валидный fallback, напоминание уходит с ней. */
    public function test_falls_back_to_course_zoom_link(): void
    {
        Queue::fake();
        $this->enable();

        $course = Course::create(['title' => 'Хинди 2026', 'slug' => 'hindi-2026', 'zoom_link' => 'https://zoom.us/j/course-link']);
        $group = Group::create(['name' => 'Группа 1', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'Грамматика',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => str_contains($job->text, 'https://zoom.us/j/course-link'));
    }

    public function test_skips_when_flag_off(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => false]);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create(['title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_skips_group_without_chat_id_and_leaves_unmarked(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Без чата']); // нет telegram_chat_id
        $schedule = Schedule::create(['title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
        // Не помечаем — уйдёт, когда чат заполнят.
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_skips_schedule_outside_lead_window(): void
    {
        Queue::fake();
        $this->enable(60);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create(['title' => 'Позже', 'start' => now()->addHours(3), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_rescheduling_class_rearms_reminder(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        $schedule = Schedule::create([
            'title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/reschedule',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);

        // Перенос времени занятия сбрасывает колонку - напоминание перевыстрелит к новому времени.
        $schedule->update(['start' => now()->addMinutes(20)]);
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertPushed(SendZapisiBotMessageJob::class, 2);
    }

    /**
     * Зеркальная дубль-гвардия (диагноз 26-08-2026): автопостинг ссылки
     * (classes:post-group-link) уже отправил «Скоро занятие» в этот чат —
     * второй пост не нужен. Не шлём и не помечаем.
     */
    public function test_skips_schedule_already_posted_by_autopost(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 8', 'telegram_chat_id' => '-100888']);
        $schedule = Schedule::create([
            'title' => 'Занятие 8',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/8',
            'group_link_posted_at' => now()->subMinutes(5),
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }
}
