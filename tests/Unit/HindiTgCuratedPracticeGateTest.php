<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HindiTgCuratedPractice;
use Tests\TestCase;

/**
 * H2446 — privacy gate drops PII rows; committed store is clean.
 */
class HindiTgCuratedPracticeGateTest extends TestCase
{
    public function test_committed_store_has_ten_clean_items(): void
    {
        $scan = app(HindiTgCuratedPractice::class)->scan();

        $this->assertSame(10, $scan['raw_count']);
        $this->assertCount(10, $scan['accepted']);
        $this->assertSame([], $scan['rejected']);
        foreach ($scan['accepted'] as $item) {
            $this->assertSame('teacher_curated', $item['source']);
            $this->assertNull($item['lesson_id']);
            foreach (HindiTgCuratedPractice::FORBIDDEN_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $item);
            }
        }
    }

    public function test_poisoned_fixture_keeps_only_the_clean_row(): void
    {
        $scan = app(HindiTgCuratedPractice::class)->scan(
            base_path('tests/fixtures/hindi_tg_curated/poisoned.json'),
        );

        $this->assertSame(4, $scan['raw_count']);
        $this->assertCount(1, $scan['accepted']);
        $this->assertSame('htg-ok', $scan['accepted'][0]['id']);
        $reasons = collect($scan['rejected'])->pluck('reason', 'id')->all();
        $this->assertSame('forbidden_key:telegram_user_id', $reasons['htg-pii-key']);
        $this->assertSame('username_mention', $reasons['htg-pii-username']);
        $this->assertSame('telegram_url', $reasons['htg-pii-url']);
    }

    public function test_probe_redacts_answers(): void
    {
        $probe = app(HindiTgCuratedPractice::class)->probe();

        $this->assertFalse($probe['flag']);
        $this->assertSame(10, $probe['accepted_count']);
        foreach ($probe['items'] as $row) {
            $this->assertSame('***', $row['answer']);
        }
    }
}
