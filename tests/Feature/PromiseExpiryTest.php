<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\UpcomingPromisesWidget;
use App\Jobs\SendTelegramChatMessageJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Course;
use App\Models\PaymentPromise;
use App\Models\PromiseEvent;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PromiseExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function promise(array $overrides = []): PaymentPromise
    {
        // Куратор-чат НЕ настроен на момент создания → booted::created no-op,
        // не засоряет очередь; настраиваем его в тесте перед прогоном команды.
        return PaymentPromise::create(array_merge([
            'user_id' => User::factory()->create(['telegram_id' => 700111])->id,
            'course_id' => Course::factory()->create()->id,
            'promised_at' => now()->subDay(),
            'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ], $overrides));
    }

    /** @test */
    public function expiring_promise_is_logged_and_digest_sent(): void
    {
        $promise = $this->promise();
        config()->set('services.telegram.curators_chat_id', '999');

        $this->artisan('promises:expire')->assertSuccessful();

        $this->assertSame(PaymentPromise::STATUS_EXPIRED, $promise->fresh()->status);

        $this->assertDatabaseHas('promise_events', [
            'payment_promise_id' => $promise->id,
            'event' => PromiseEvent::EVENT_EXPIRED,
        ]);

        // Утренний дайджест ушёл кураторам.
        Queue::assertPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function future_promise_is_untouched_and_no_digest(): void
    {
        $promise = $this->promise(['promised_at' => now()->addWeek()]);
        config()->set('services.telegram.curators_chat_id', '999');

        $this->artisan('promises:expire')->assertSuccessful();

        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->fresh()->status);
        $this->assertSame(0, PromiseEvent::count());
        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function widget_waive_cancels_promise_and_logs_event(): void
    {
        $promise = $this->promise();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(UpcomingPromisesWidget::class)
            ->callTableBulkAction('waive', [$promise]);

        $this->assertSame(PaymentPromise::STATUS_CANCELLED, $promise->fresh()->status);
        $this->assertNotNull($promise->fresh()->cancelled_at);
        $this->assertDatabaseHas('promise_events', [
            'payment_promise_id' => $promise->id,
            'event' => PromiseEvent::EVENT_WAIVED,
        ]);
    }

    /** @test */
    public function widget_nudge_pings_student_and_logs_event(): void
    {
        $promise = $this->promise();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(UpcomingPromisesWidget::class)
            ->callTableBulkAction('nudge', [$promise]);

        Queue::assertPushed(
            SendTelegramMessageJob::class,
            fn (SendTelegramMessageJob $job): bool => $job->userId === $promise->user_id,
        );
        $this->assertDatabaseHas('promise_events', [
            'payment_promise_id' => $promise->id,
            'event' => PromiseEvent::EVENT_NUDGED,
        ]);
    }
}
