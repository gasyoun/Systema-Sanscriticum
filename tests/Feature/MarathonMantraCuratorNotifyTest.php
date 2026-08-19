<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\MarathonEnrollment;
use App\Services\CuratorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * H445 Phase 4 (H546) — curator gets notified only for the paid track's
 * mantra-voice submission; free-track review is out of scope (self-assessed).
 */
class MarathonMantraCuratorNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_curators_chat_when_configured(): void
    {
        config(['services.telegram.curators_chat_id' => '-100555']);
        Bus::fake([SendTelegramChatMessageJob::class]);

        $enrollment = MarathonEnrollment::factory()->deva()->paid()->create();

        app(CuratorNotifier::class)->marathonMantraVoiceReceived($enrollment);

        Bus::assertDispatched(SendTelegramChatMessageJob::class, fn ($job) => $job->chatId === '-100555'
            && str_contains($job->text, 'мантра'));
    }

    public function test_noop_when_curators_chat_not_configured(): void
    {
        config(['services.telegram.curators_chat_id' => null]);
        Bus::fake([SendTelegramChatMessageJob::class]);

        $enrollment = MarathonEnrollment::factory()->deva()->paid()->create();

        app(CuratorNotifier::class)->marathonMantraVoiceReceived($enrollment);

        Bus::assertNotDispatched(SendTelegramChatMessageJob::class);
    }
}
