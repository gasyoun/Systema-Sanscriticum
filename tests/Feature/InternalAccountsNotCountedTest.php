<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Support\InternalAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Рулинг MG 26-10-2026: «@pe4kinsmart и @gasyoun никогда не считать, ни тут
 * ни вообще, это админы». Внутренние аккаунты не попадают в публичный
 * счётчик ждуна, в порог открытия оплаты и в прогноз — но остаются видны в
 * полной связи votes(), потому что админке нужны все строки.
 */
class InternalAccountsNotCountedTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $minPayers = 8): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => 'internal-test-course',
            'course_title' => 'Философия санкхьи',
            'teacher_name' => 'Максим Леонов',
            'min_payers' => $minPayers,
            'block_price_rub' => 6000,
            'kind' => 'other',
            'is_listed' => true,
        ]);
    }

    public function test_internal_vote_is_excluded_from_votes_count(): void
    {
        $item = $this->item();
        $student = User::factory()->create();
        $admin = User::factory()->create();
        config(['internal_accounts.user_ids' => [$admin->id]]);

        $item->castVoteBy($student, null);
        $item->castVoteBy($admin, null);

        $fresh = $item->fresh();
        $this->assertSame(1, $fresh->votesCount(), 'студенческий голос считается');
        $this->assertSame(2, $fresh->votes()->count(), 'админка видит оба голоса');
        $this->assertSame(1, $fresh->studentVotes()->count());
    }

    public function test_internal_vote_does_not_open_payment_threshold(): void
    {
        $item = $this->item(minPayers: 2);
        $student = User::factory()->create();
        $admin = User::factory()->create();
        config(['internal_accounts.user_ids' => [$admin->id]]);

        $item->castVoteBy($student, null);
        $item->castVoteBy($admin, null);

        $this->assertFalse($item->fresh()->hasThreshold(), 'порог не достигнут одним студентом');
    }

    public function test_internal_vote_is_absent_from_public_feed(): void
    {
        $item = $this->item();
        $student = User::factory()->create();
        $admin = User::factory()->create();
        config(['internal_accounts.user_ids' => [$admin->id]]);

        $item->castVoteBy($student, null);
        $item->castVoteBy($admin, null);
        Cache::flush();

        $resp = $this->getJson(route('api.public.waitlist'));
        $resp->assertOk();

        $row = collect($resp->json('data'))->firstWhere('slug', 'internal-test-course');
        $this->assertNotNull($row, 'строка ждуна есть в фиде');
        $this->assertSame(1, (int) $row['votes'], 'в публичном счётчике только студенческий голос');
    }

    public function test_voters_relation_skips_internal_accounts(): void
    {
        $item = $this->item();
        $student = User::factory()->create();
        $admin = User::factory()->create();
        config(['internal_accounts.user_ids' => [$admin->id]]);

        $item->castVoteBy($student, null);
        $item->castVoteBy($admin, null);

        $ids = $item->fresh()->voters()->pluck('users.id')->all();
        $this->assertSame([$student->id], $ids, 'рассылка «оплата открыта» не уходит сотрудникам');
        $this->assertTrue(InternalAccounts::isInternal($admin->id));
        $this->assertFalse(InternalAccounts::isInternal($student->id));
    }
}
