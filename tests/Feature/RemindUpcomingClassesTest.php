<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * classes:remind-upcoming — персональные ЛС «Скоро занятие» студентам из ростера.
 * Дубль-гвардия каналов (диагноз 28-08-2026): при dm_suppressed_when_group_chat занятие,
 * чья группа уже получает пост @zapisi_ORSbot в TG-чат (тот же T-60), ЛС-волну не получает —
 * иначе студент видит два почти одинаковых пинга с разницей в минуту.
 */
class RemindUpcomingClassesTest extends TestCase
{
    use RefreshDatabase;

    private function enable(bool $suppress = false): void
    {
        MarketingSetting::create([
            'class_reminders_enabled' => true,
            'class_reminder_lead_minutes' => 60,
            'dm_suppressed_when_group_chat' => $suppress,
        ]);
    }

    /** Группа + студент с привязанным Telegram в активном составе + занятие в окне T-60. */
    private function groupWithStudent(?string $chatId): array
    {
        $group = Group::create([
            'name' => 'Группа А',
            'telegram_chat_id' => $chatId,
        ]);
        $student = User::factory()->create(['telegram_id' => 4242]);
        $group->users()->attach($student->id);
        $schedule = Schedule::create([
            'title' => 'Санскрит live',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
        ]);

        return compact('group', 'student', 'schedule');
    }

    /** Рубильник выключен (дефолт) — поведение байт-в-байт как до 28-08-2026. */
    public function test_flag_off_keeps_dm_wave_for_groups_with_chat(): void
    {
        Bus::fake();
        $this->enable(false);
        ['schedule' => $schedule] = $this->groupWithStudent('-100123');

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Bus::assertDispatched(SendMessengerAlerts::class, 1);
        $this->assertNotNull($schedule->fresh()->reminded_at);
    }

    /** Рубильник включён + чат у группы есть: ЛС-волна глушится, пометки нет. */
    public function test_flag_on_suppresses_dm_when_group_has_chat(): void
    {
        Bus::fake();
        $this->enable(true);
        ['schedule' => $schedule] = $this->groupWithStudent('-100123');

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Bus::assertNotDispatched(SendMessengerAlerts::class);
        // Пропускаем без пометки: выключение рубильника вернёт ЛС в том же окне.
        $this->assertNull($schedule->fresh()->reminded_at);
    }

    /** Рубильник включён, но чата у группы нет: ЛС уходят как раньше. */
    public function test_flag_on_still_sends_dm_for_groups_without_chat(): void
    {
        Bus::fake();
        $this->enable(true);
        ['schedule' => $schedule] = $this->groupWithStudent(null);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Bus::assertDispatched(SendMessengerAlerts::class, 1);
        $this->assertNotNull($schedule->fresh()->reminded_at);
    }
}
