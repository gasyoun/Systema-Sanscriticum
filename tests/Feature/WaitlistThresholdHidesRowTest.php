<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Рулинг MG 28-10-2026: «как только порог достигается, снимать, но в порог
 * не считать Ивана и Марциса». Строка ждуна уходит с витрины и из публичного
 * фида в момент, когда студенческие голоса достигают min_payers, — без
 * ожидания cron-прогона waitlist:process. Внутренние аккаунты в порог не
 * считаются (внутренний голос сам по себе строку не снимает).
 */
class WaitlistThresholdHidesRowTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $minPayers = 8): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => 'threshold-hide-course',
            'course_title' => 'Философия санкхьи',
            'teacher_name' => 'Максим Леонов',
            'min_payers' => $minPayers,
            'block_price_rub' => 6000,
            'kind' => 'other',
            'is_listed' => true,
            'status' => 'collecting',
        ]);
    }

    public function test_below_threshold_row_stays_on_showcase(): void
    {
        $item = $this->item(minPayers: 8);
        $item->castVoteBy(User::factory()->create(), null);

        $this->assertTrue(CourseWaitlistItem::onShowcase()->whereKey($item->id)->exists());
    }

    public function test_threshold_reached_row_leaves_showcase(): void
    {
        $item = $this->item(minPayers: 2);
        $item->castVoteBy(User::factory()->create(), null);
        $item->castVoteBy(User::factory()->create(), null);

        $this->assertSame(2, $item->fresh()->votesCount());
        $this->assertFalse(CourseWaitlistItem::onShowcase()->whereKey($item->id)->exists());
    }

    public function test_internal_vote_alone_does_not_remove_row(): void
    {
        $item = $this->item(minPayers: 1);
        $admin = User::factory()->create();
        config(['internal_accounts.user_ids' => [$admin->id]]);

        $item->castVoteBy($admin, null);

        $this->assertSame(0, $item->fresh()->votesCount(), 'внутренний голос не считается');
        $this->assertTrue(CourseWaitlistItem::onShowcase()->whereKey($item->id)->exists(),
            'одна административная строка порог не достигает — витрина не тронута');
    }

    public function test_threshold_reached_row_absent_from_public_feed(): void
    {
        $shown = $this->item(minPayers: 8);
        $shown->slug = 'threshold-shown';
        $shown->save();

        $hidden = CourseWaitlistItem::create([
            'slug' => 'threshold-hidden',
            'course_title' => 'Курс с достигнутым порогом',
            'teacher_name' => 'Максим Леонов',
            'min_payers' => 1,
            'block_price_rub' => 6000,
            'kind' => 'other',
            'is_listed' => true,
            'status' => 'collecting',
        ]);
        $hidden->castVoteBy(User::factory()->create(), null);

        Cache::flush();

        $resp = $this->getJson(route('api.public.waitlist'));
        $resp->assertOk();

        $slugs = collect($resp->json('data'))->pluck('slug')->all();
        $this->assertContains('threshold-shown', $slugs, 'строка ниже порога на месте');
        $this->assertNotContains('threshold-hidden', $slugs, 'строка с достигнутым порогом снята');
    }
}
