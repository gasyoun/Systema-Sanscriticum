<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Планировщик анонсов (H816 PR 2): scheduled_at + команда announcements:dispatch-due,
 * дедуп по dispatched_at. Мессенджер-джоб фейкается (Bus::fake) — ничего не уходит.
 */
class AnnouncementSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(array $overrides = []): User
    {
        // Получатель мессенджер-рассылки С СОГЛАСИЕМ на анонсы в мессенджеры
        // (152-ФЗ гейт; email не проверяем — тестируем TG-канал).
        return User::factory()->create(array_merge(['wants_messenger_announcements' => true], $overrides));
    }

    private function scheduledAnnouncement(?string $scheduledAt, array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Старт нового потока',
            'preview' => 'Скоро старт',
            'content' => 'Подробности внутри.',
            'is_published' => true,
            'scheduled_at' => $scheduledAt,
            'send_to_telegram' => true,
        ], $overrides));
    }

    public function test_due_announcement_is_dispatched_and_marked(): void
    {
        Bus::fake();
        $this->recipient();
        $announcement = $this->scheduledAnnouncement(now()->subMinute()->toDateTimeString());

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        Bus::assertDispatched(SendMessengerAlerts::class, 1);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
    }

    public function test_dispatch_is_idempotent_across_runs(): void
    {
        Bus::fake();
        $this->recipient();
        $this->scheduledAnnouncement(now()->subMinute()->toDateTimeString());

        $this->artisan('announcements:dispatch-due')->assertSuccessful();
        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        // Второй прогон видит dispatched_at и не рассылает повторно.
        Bus::assertDispatched(SendMessengerAlerts::class, 1);
    }

    public function test_future_announcement_is_not_dispatched(): void
    {
        Bus::fake();
        $this->recipient();
        $announcement = $this->scheduledAnnouncement(now()->addDay()->toDateTimeString());

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        Bus::assertNotDispatched(SendMessengerAlerts::class);
        $this->assertNull($announcement->fresh()->dispatched_at);
    }

    public function test_unpublished_due_announcement_is_not_dispatched(): void
    {
        Bus::fake();
        $this->recipient();
        $this->scheduledAnnouncement(now()->subMinute()->toDateTimeString(), ['is_published' => false]);

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        Bus::assertNotDispatched(SendMessengerAlerts::class);
    }

    public function test_announcement_with_no_channel_is_never_picked(): void
    {
        Bus::fake();
        $this->recipient();
        $announcement = $this->scheduledAnnouncement(
            now()->subMinute()->toDateTimeString(),
            ['send_to_telegram' => false, 'send_to_vk' => false, 'send_to_email' => false],
        );

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        Bus::assertNotDispatched(SendMessengerAlerts::class);
        // dispatched_at не ставится — рассылать было нечего.
        $this->assertNull($announcement->fresh()->dispatched_at);
    }

    public function test_immediate_dispatch_via_dispatcher_sends_now(): void
    {
        Bus::fake();
        $this->recipient();
        $announcement = $this->scheduledAnnouncement(null); // scheduled_at пуст → сразу

        app(AnnouncementDispatcher::class)->dispatch($announcement);

        Bus::assertDispatched(SendMessengerAlerts::class, 1);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
    }

    public function test_messenger_broadcast_skips_user_without_consent(): void
    {
        Bus::fake();
        // Пользователь БЕЗ согласия на анонсы в мессенджеры (152-ФЗ).
        User::factory()->create(['wants_messenger_announcements' => false]);
        $announcement = $this->scheduledAnnouncement(now()->subMinute()->toDateTimeString());

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        // Гейт не пустил рекламную рассылку в мессенджеры.
        Bus::assertNotDispatched(SendMessengerAlerts::class);
        // Но dispatched_at ставится — анонс обработан (для тех, у кого email/согласие).
        $this->assertNotNull($announcement->fresh()->dispatched_at);
    }

    public function test_messenger_broadcast_reaches_only_consenting_users(): void
    {
        Bus::fake();
        $this->recipient(); // согласие = true
        User::factory()->create(['wants_messenger_announcements' => false]); // без согласия
        $this->scheduledAnnouncement(now()->subMinute()->toDateTimeString());

        $this->artisan('announcements:dispatch-due')->assertSuccessful();

        // Ровно один джоб — на согласившегося получателя.
        Bus::assertDispatched(SendMessengerAlerts::class, 1);
    }
}
