<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SeasonNotifyStartCommand;
use App\Jobs\SendTelegramMessageJob;
use App\Mail\SeasonStartMail;
use App\Models\GameEvent;
use App\Models\Season;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeasonNotifyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function audience_counts_match_fixture(): void
    {
        // В: свежий логин.
        User::factory()->create(['last_login_at' => now()->subDays(5)]);
        // В: свежая активность (без логина за окно).
        User::factory()->create(['last_login_at' => now()->subDays(200), 'last_activity_at' => now()->subDays(10)]);
        // В: протух, но есть /lila-событие в game_events.
        $lila = User::factory()->create(['last_login_at' => now()->subDays(200)]);
        GameEvent::create([
            'anon_id' => 'anon-1',
            'drill' => 'verbs',
            'band' => null,
            'event' => GameEvent::START,
            'payload' => null,
            'authenticated' => true,
            'user_id' => $lila->id,
            'created_at' => now()->subDays(150),
        ]);
        // НЕ в: протух и без событий.
        User::factory()->create(['last_login_at' => now()->subDays(200)]);
        // НЕ в: активный персонал (admin-like).
        User::factory()->create(['role' => Roles::ADMIN, 'last_login_at' => now()->subDays(1)]);

        $this->assertSame(3, SeasonNotifyStartCommand::audienceQuery()->count());
    }

    /** @test */
    public function flag_off_live_run_is_a_noop(): void
    {
        $user = User::factory()->create(['telegram_id' => '123', 'last_login_at' => now()]);
        Mail::fake();
        Queue::fake();

        $this->artisan('season:notify-start')
            ->assertExitCode(1);

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('season_notifications', 0);
    }

    /** @test */
    public function dry_run_prints_counts_and_samples_without_sending(): void
    {
        User::factory()->create(['telegram_id' => '123456', 'last_activity_at' => now()]);

        $this->artisan('season:notify-start', ['--dry-run' => true])
            ->expectsOutputToContain('Аудитория (актив за 90 дней ИЛИ любое /lila-событие): 1')
            ->expectsOutputToContain('Канал email: 1')
            ->expectsOutputToContain('Канал Telegram: 1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('season_notifications', 0);
    }

    /** @test */
    public function live_send_marks_users_and_second_run_sends_nothing_new(): void
    {
        config(['season.notify.enabled' => true]);

        // a: и email, и Telegram; b: только email.
        User::factory()->create(['telegram_id' => '111111', 'last_login_at' => now()]);
        User::factory()->create(['last_login_at' => now()]);

        Mail::fake();
        Queue::fake();

        $this->artisan('season:notify-start')->assertExitCode(0);

        Mail::assertQueued(SeasonStartMail::class, 2);
        Queue::assertPushed(SendTelegramMessageJob::class, 1);
        $this->assertDatabaseCount('season_notifications', 3);

        // Повторный прогон cron — идемпотентность: ничего нового.
        $this->artisan('season:notify-start')->assertExitCode(0);

        Mail::assertQueued(SeasonStartMail::class, 2);
        Queue::assertPushed(SendTelegramMessageJob::class, 1);
        $this->assertDatabaseCount('season_notifications', 3);
    }

    /** @test */
    public function rendered_email_sample_contains_season_dates(): void
    {
        config(['season.defaults.title' => 'Сезон 1: Осень 2026']);

        $html = (new SeasonStartMail(null))->render();

        $this->assertStringContainsString('Сезон 1: Осень 2026', $html);
        // Дефолтные даты Сезона 1 из config/season.php: 01.09.2026 – 31.12.2026.
        $this->assertStringContainsString('1 сентября 2026', $html);
        $this->assertStringContainsString('31 декабря 2026', $html);
        $this->assertStringContainsString('базового снапшота', $html);
        $this->assertStringContainsString(url('/lila/'), $html);
    }

    /** @test */
    public function notify_command_is_registered_in_schedule_t_minus_24h(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $found = false;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'season:notify-start 1')) {
                $found = true;
                // T-24h до season:open ('0 21 31 8 *'): 30-08 21:00 UTC.
                $this->assertSame('0 21 30 8 *', $event->expression);
                break;
            }
        }

        $this->assertTrue($found, 'season:notify-start 1 не найден в расписании Kernel');
    }

    /** @test */
    public function season_row_dates_win_over_defaults_in_rendered_sample(): void
    {
        $season = Season::factory()->create([
            'title' => 'Тестовый сезон',
            'started_at' => '2027-02-01 00:00:00',
            'ended_at' => '2027-05-31 23:59:59',
        ]);

        $html = (new SeasonStartMail($season))->render();

        $this->assertStringContainsString('1 февраля 2027', $html);
        $this->assertStringContainsString('31 мая 2027', $html);
    }
}
