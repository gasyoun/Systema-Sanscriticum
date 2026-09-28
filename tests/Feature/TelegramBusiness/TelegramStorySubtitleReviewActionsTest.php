<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Filament\Resources\TelegramBusinessStoryPublicationResource\Pages\ListTelegramBusinessStoryPublications;
use App\Jobs\PublishTelegramBusinessStory;
use App\Models\TelegramBusinessStoryPublication;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class TelegramStorySubtitleReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_ready_draft_gets_an_approval_action_and_dispatches_one_story(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        Queue::fake();
        $post = ['chat' => ['id' => -1001, 'username' => 'samskrte'],
            'message_id' => 621, 'video' => ['file_id' => 'file-id']];
        $draft = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 621,
            'status' => 'subtitle_pending', 'subtitle_status' => 'ready',
            'source_post' => $post,
            'subtitle_draft' => "1\n00:00:00,000 --> 00:00:01,000\nСанскрит.\n",
        ]);
        $ordinary = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 622, 'status' => 'published',
        ]);

        Livewire::test(ListTelegramBusinessStoryPublications::class)
            ->assertTableActionVisible('approveSubtitles', $draft)
            ->assertTableActionHidden('approveSubtitles', $ordinary)
            ->callTableAction('approveSubtitles', $draft);

        self::assertSame('approved', $draft->fresh()->subtitle_status);
        self::assertSame('subtitle_release', $draft->fresh()->status);
        Queue::assertPushed(PublishTelegramBusinessStory::class, 1);
    }

    public function test_worker_queue_exposes_a_signed_public_source_without_bot_credentials(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'story-worker-queue-');
        self::assertNotFalse($source);
        try {
            file_put_contents($source, 'public source video');
            $ledger = TelegramBusinessStoryPublication::create([
                'source_chat_id' => '-1001', 'source_message_id' => 623,
                'status' => 'subtitle_pending', 'subtitle_status' => 'pending',
                'source_media_path' => $source,
            ]);
            self::assertSame(0, Artisan::call('telegram-business:story-subtitles', ['action' => 'queue']));
            $rows = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($ledger->id, $rows[0]['id']);
            self::assertStringContainsString('signature=', $rows[0]['source_url']);
            self::assertStringNotContainsString('bot', $rows[0]['source_url']);
        } finally {
            @unlink($source);
        }
    }
}
