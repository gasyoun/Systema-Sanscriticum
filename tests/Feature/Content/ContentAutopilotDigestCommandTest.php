<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\StoryPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** H5020: content:autopilot-digest lists what the autopilots published + held, flag state. */
class ContentAutopilotDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_lists_published_posts_holds_and_flags(): void
    {
        Carbon::setTestNow('2026-09-20 12:00:00');
        config([
            'features.content_calendar_autopilot' => true,
            'features.telegram_story_publisher' => true,
            'services.n8n.calendar_post_webhook' => '',
        ]);

        ContentCalendarSlot::create([
            'slot_date' => '2026-09-18', 'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_PUBLISHED, 'publish_at' => '2026-09-18 09:00:00',
            'body' => 'Пост A', 'meta' => ['link' => 'https://vk.com/wall-1_1', 'autopilot_published_at' => '2026-09-18 09:03:00'],
        ]);
        ContentCalendarSlot::create([
            'slot_date' => '2026-09-19', 'slot_type' => ContentCalendarSlot::TYPE_FORWARD,
            'status' => ContentCalendarSlot::STATUS_DRAFT, 'publish_at' => '2026-09-19 09:00:00',
            'body' => 'x', 'meta' => ['prohibition_hold' => 'prohibition-hold §2.8: competitors («Окаруто»)'],
        ]);
        StoryPost::create([
            'kind' => StoryPost::KIND_TEXT, 'payload' => 'Слово дня', 'source' => StoryPost::SOURCE_QUEUE,
            'source_key' => 'k1', 'status' => StoryPost::STATUS_PUBLISHED, 'publish_at' => '2026-09-17 09:00:00',
            'posted_at' => '2026-09-17 09:01:00', 'telegram_message_id' => '77',
            'journal' => '2026-09-17 09:01:00 prohibition-warn: price_or_live_date («12 000 ₽»)',
        ]);
        StoryPost::create([
            'kind' => StoryPost::KIND_TEXT, 'payload' => 'старое', 'source' => StoryPost::SOURCE_QUEUE,
            'source_key' => 'k0', 'status' => StoryPost::STATUS_PUBLISHED, 'publish_at' => '2026-09-01 09:00:00',
            'posted_at' => '2026-09-01 09:01:00',
        ]);

        $out = sys_get_temp_dir().'/h5020-digest-'.uniqid().'.md';
        $this->artisan('content:autopilot-digest', ['--out' => $out, '--since' => '2026-09-14', '--until' => '2026-09-20 12:00'])
            ->assertSuccessful();

        $md = (string) file_get_contents($out);
        $this->assertStringContainsString('Опубликовано автопилотом — 2 пост(ов)', $md);
        $this->assertStringContainsString('[https://vk.com/wall-1_1](https://vk.com/wall-1_1)', $md);
        $this->assertStringContainsString('2026-09-18 09:03:00', $md);
        $this->assertStringContainsString('https://t.me/rusamskrtam/77', $md);
        $this->assertStringContainsString('price_or_live_date («12 000 ₽»)', $md);
        $this->assertStringNotContainsString('старое', $md, 'outside the window');
        $this->assertStringContainsString('Удержано чек-листом §2.8 — 1', $md);
        $this->assertStringContainsString('competitors («Окаруто»)', $md);
        $this->assertStringContainsString('`CONTENT_CALENDAR_AUTOPILOT` | ✅ true | ⚠️ `N8N_CALENDAR_POST_WEBHOOK` пуст', $md);
        $this->assertStringContainsString('`TELEGRAM_STORY_PUBLISHER` | ✅ true', $md);
        @unlink($out);
    }

    public function test_empty_window_says_so(): void
    {
        $this->artisan('content:autopilot-digest', ['--stdout' => true])
            ->expectsOutputToContain('за окно автопилоты ничего не опубликовали')
            ->assertSuccessful();
    }
}
