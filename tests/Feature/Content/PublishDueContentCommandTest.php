<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * content:publish-due (H1568, content calendar W5). VERIFICATION criteria E1/E3/E4.
 */
class PublishDueContentCommandTest extends TestCase
{
    use RefreshDatabase;

    private function slot(array $overrides = []): ContentCalendarSlot
    {
        return ContentCalendarSlot::create(array_merge([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => now()->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => now()->subHour(),
            'source_kind' => 'vk_ors_post',
            'source_ref' => 'wall-88831040_1',
            'body' => 'Текст поста',
        ], $overrides));
    }

    public function test_noop_when_autopilot_flag_off(): void
    {
        config(['features.content_calendar_autopilot' => false]);
        Http::fake();

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->fresh()->status);
    }

    public function test_skips_when_webhook_not_configured(): void
    {
        config([
            'features.content_calendar_autopilot' => true,
            'services.n8n.calendar_post_webhook' => null,
        ]);
        Http::fake();

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->fresh()->status);
    }

    public function test_posts_only_due_slots_and_marks_published(): void
    {
        config([
            'features.content_calendar_autopilot' => true,
            'services.n8n.calendar_post_webhook' => 'https://n8n.example/webhook/vk-calendar-post',
            'services.n8n.calendar_post_secret' => 'out-secret',
        ]);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);

        $due = $this->slot(['body' => 'Пост A']);
        $candidate = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_VK_POST,
            'status' => ContentCandidate::STATUS_SCHEDULED,
            'calendar_slot_id' => $due->id,
            'body' => 'Пост A',
        ]);
        $notYetDue = $this->slot(['publish_at' => now()->addHour(), 'body' => 'Пост B']);
        $alreadyPublished = $this->slot(['status' => ContentCalendarSlot::STATUS_PUBLISHED, 'body' => 'Пост C']);

        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://n8n.example/webhook/vk-calendar-post'
            && $request->hasHeader('X-Webhook-Secret', 'out-secret')
            && $request['action'] === 'vk_calendar_post'
            && $request['calendar_slot_id'] === $due->id
            && $request['text_vk'] === 'Пост A');

        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $due->fresh()->status);
        $this->assertSame(ContentCandidate::STATUS_PUBLISHED, $candidate->fresh()->status);
        $this->assertNotNull($candidate->fresh()->published_at);
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $notYetDue->fresh()->status);
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $alreadyPublished->fresh()->status);
    }

    public function test_failed_n8n_response_keeps_slot_scheduled_for_retry(): void
    {
        config([
            'features.content_calendar_autopilot' => true,
            'services.n8n.calendar_post_webhook' => 'https://n8n.example/webhook/vk-calendar-post',
        ]);
        Http::fake(['n8n.example/*' => Http::response(['error' => 'boom'], 500)]);

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->fresh()->status);
    }

    /** E4: no real VK/n8n call ever fires from a test run — Http::fake makes any live URL impossible. */
    public function test_never_calls_live_vk_api(): void
    {
        config([
            'features.content_calendar_autopilot' => true,
            'services.n8n.calendar_post_webhook' => 'https://n8n.example/webhook/vk-calendar-post',
        ]);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);

        $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.vk.com'));
    }
}
