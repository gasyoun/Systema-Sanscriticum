<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentGapSuggestion;
use App\Models\MessageTemplate;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\TelegramSupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectContentGapsTest extends TestCase
{
    use RefreshDatabase;

    private function chat(int $telegramChatId): TelegramSupportChat
    {
        return TelegramSupportChat::create(['telegram_chat_id' => $telegramChatId, 'type' => 'private']);
    }

    private function rollup(TelegramSupportChat $chat, string $date, int $queries, int $humanReplies, int $aiSent): SupportDailyRollup
    {
        return SupportDailyRollup::create([
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => $date,
            'incoming_count' => $queries,
            'human_reply_count' => $humanReplies,
            'ai_sent_count' => $aiSent,
        ]);
    }

    private function assign(SupportDailyRollup $rollup, string $category): void
    {
        SupportTopicAssignment::create([
            'support_daily_rollup_id' => $rollup->id,
            'category' => $category,
            'source' => 'keyword',
            'confidence' => 1,
        ]);
    }

    public function test_ranks_categories_by_deflection_and_persists_suggestions(): void
    {
        // "оплата" — highest deflection (most human replies), no template → GAP.
        $this->assign($this->rollup($this->chat(1), '2026-06-01', 5, 5, 0), 'оплата');
        $this->assign($this->rollup($this->chat(2), '2026-06-02', 4, 4, 0), 'оплата');
        // "доступ" — lower deflection, HAS a matching support template → not a gap.
        $this->assign($this->rollup($this->chat(3), '2026-06-03', 3, 1, 2), 'доступ');
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create([
            'title' => 'Доступ к урокам',
            'body' => 'Ссылка на личный кабинет: {pay_link}',
        ]);

        $this->artisan('content:detect-gaps', ['--since' => '2026-05-01', '--until' => '2026-07-01'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('content_gap_suggestions', 2);

        $oplata = ContentGapSuggestion::where('category', 'оплата')->firstOrFail();
        $this->assertSame(9, $oplata->human_replies);
        $this->assertFalse($oplata->has_content);
        $this->assertSame(9, $oplata->deflection_score);

        $dostup = ContentGapSuggestion::where('category', 'доступ')->firstOrFail();
        $this->assertTrue($dostup->has_content);

        // Ranked: оплата (deflection 9) ahead of доступ (deflection 1).
        $ranked = ContentGapSuggestion::query()->orderByDesc('deflection_score')->pluck('category')->all();
        $this->assertSame(['оплата', 'доступ'], $ranked);
    }

    public function test_gaps_scope_excludes_categories_with_content(): void
    {
        $this->assign($this->rollup($this->chat(1), '2026-06-01', 3, 3, 0), 'кто в группе');
        $this->assign($this->rollup($this->chat(2), '2026-06-02', 2, 2, 0), 'доступ');
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create([
            'title' => 'Доступ',
            'body' => 'см. личный кабинет',
        ]);

        $this->artisan('content:detect-gaps', ['--since' => '2026-05-01', '--until' => '2026-07-01'])
            ->assertExitCode(0);

        $gaps = ContentGapSuggestion::query()->gaps()->pluck('category')->all();
        $this->assertSame(['кто в группе'], $gaps);
    }

    public function test_rerunning_the_same_window_updates_in_place_not_duplicates(): void
    {
        $this->assign($this->rollup($this->chat(1), '2026-06-01', 2, 2, 0), 'доступ');

        $this->artisan('content:detect-gaps', ['--since' => '2026-05-01', '--until' => '2026-07-01']);
        $this->assertDatabaseCount('content_gap_suggestions', 1);

        $this->assign($this->rollup($this->chat(2), '2026-06-02', 3, 3, 0), 'доступ');
        $this->artisan('content:detect-gaps', ['--since' => '2026-05-01', '--until' => '2026-07-01']);

        $this->assertDatabaseCount('content_gap_suggestions', 1);
        $this->assertSame(5, ContentGapSuggestion::first()->human_replies);
    }

    public function test_uncategorized_is_never_persisted(): void
    {
        $this->assign($this->rollup($this->chat(1), '2026-06-01', 1, 1, 0), 'uncategorized');

        $this->artisan('content:detect-gaps', ['--since' => '2026-05-01', '--until' => '2026-07-01']);

        // A classifier miss is not a real content topic — nothing to write
        // content about, so it never becomes a gap suggestion at all.
        $this->assertDatabaseCount('content_gap_suggestions', 0);
    }
}
