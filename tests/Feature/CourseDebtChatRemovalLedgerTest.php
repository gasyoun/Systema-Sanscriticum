<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRemovalStatus;
use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\CourseDebtChatRemoval;
use App\Models\CourseDebtChatRemovalEvent;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Discipline\ChatRemovalCandidate;
use App\Services\Discipline\ChatRemovalEligibility;
use App\Services\Discipline\ChatRemovalLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Жизненный цикл строки реестра H2746: подтверждение → исключение → долг →
 * взнос → возврат, плюс арифметика взноса (₽1 000 × число чатов) и
 * неизменяемость снимка основания и аудит-следа.
 */
class CourseDebtChatRemovalLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $groupA;

    private Group $groupB;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Debtors::flushPairCaches();

        $start = Carbon::now()->subDays(60)->startOfDay();
        $this->course = Course::factory()->create([
            'is_active' => true,
            'slug' => 'h2746-ledger',
            'title' => 'Курс с двумя чатами',
        ]);
        CourseBlock::factory()->for($this->course)->withDates($start, $start->copy()->addDays(30))->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $this->course->id, 'price' => 4800]);

        $this->groupA = Group::create(['name' => 'Поток A', 'telegram_chat_id' => '-100A', 'status' => 'active']);
        $this->groupB = Group::create(['name' => 'Поток B', 'telegram_chat_id' => '-100B', 'status' => 'active']);
        $this->course->groups()->attach([$this->groupA->id, $this->groupB->id]);

        $this->operator = User::factory()->create();
    }

    private function debtorInBothChats(): User
    {
        $user = User::factory()->create(['telegram_id' => '770001']);
        $user->groups()->attach([$this->groupA->id, $this->groupB->id]);

        foreach ([40, 10] as $daysAgo) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $this->course->id,
                'block_number' => 1,
                'sent_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        return $user;
    }

    /** @return Collection<int, ChatRemovalCandidate> */
    private function eligibleFor(User $user)
    {
        Debtors::flushPairCaches();

        return app(ChatRemovalEligibility::class)
            ->candidates($user->id)
            ->filter(fn (ChatRemovalCandidate $c) => $c->isEligible())
            ->values();
    }

    public function test_fee_is_one_thousand_per_chat_not_per_student(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $eligible = $this->eligibleFor($user);
        $this->assertCount(2, $eligible, 'ожидались два чата курса');

        foreach ($eligible as $candidate) {
            $removal = $ledger->qualify($candidate, $this->operator->id);
            $ledger->markRemoved($removal, $this->operator->id);
        }

        $outstanding = $ledger->outstandingFee($user->id);

        $this->assertSame(2, $outstanding['chats']);
        $this->assertEqualsWithDelta(2000.0, $outstanding['amount'], 0.01);
        $this->assertSame('RUB', $outstanding['currency']);
    }

    public function test_restoration_requires_both_debt_and_fee(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);
        $ledger->markRemoved($removal, $this->operator->id);

        // Долг погашен, взнос — нет: возврат запрещён.
        $removal = $ledger->markDebtSettled($removal, $this->operator->id);
        $this->assertSame(ChatRemovalStatus::DebtSettled, $removal->status);

        try {
            $ledger->markRestored($removal, $this->operator->id);
            $this->fail('возврат без взноса должен быть отклонён');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('взнос', $e->getMessage());
        }

        // Взнос принят → стадия FeeSettled → возврат разрешён.
        $removal = $ledger->markFeePaid($removal, null, $this->operator->id);
        $this->assertSame(ChatRemovalStatus::FeeSettled, $removal->status);

        $removal = $ledger->markRestored($removal, $this->operator->id, 'вернул по инвайту');
        $this->assertSame(ChatRemovalStatus::Restored, $removal->status);
        $this->assertNotNull($removal->restored_at);
    }

    public function test_fee_paid_before_debt_still_blocks_restoration(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);
        $ledger->markRemoved($removal, $this->operator->id);

        $removal = $ledger->markFeePaid($removal, null, $this->operator->id);
        $this->assertSame(ChatRemovalStatus::Removed, $removal->status, 'взнос без долга не двигает стадию');

        try {
            $ledger->markRestored($removal, $this->operator->id);
            $this->fail('возврат без погашения долга должен быть отклонён');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('долг', $e->getMessage());
        }

        $removal = $ledger->markDebtSettled($removal, $this->operator->id);
        $this->assertSame(ChatRemovalStatus::FeeSettled, $removal->status);
    }

    public function test_waived_fee_requires_reason_and_closes_the_fee(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);
        $ledger->markRemoved($removal, $this->operator->id);

        try {
            $ledger->waiveFee($removal, '   ', $this->operator->id);
            $this->fail('прощение без причины должно быть отклонено');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('причин', $e->getMessage());
        }

        $removal = $ledger->waiveFee($removal, 'ошибка куратора при кике', $this->operator->id);

        $this->assertSame(CourseDebtChatRemoval::FEE_WAIVED, $removal->fee_status);
        $this->assertTrue($removal->feeIsClosed());
        $this->assertSame(0, $ledger->outstandingFee($user->id)['chats']);
    }

    public function test_second_open_episode_for_the_same_chat_is_refused(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $candidates = $this->eligibleFor($user);
        $first = $candidates->first();
        $ledger->qualify($first, $this->operator->id);

        // Тот же кандидат ещё раз — второй эпизод по одному чату недопустим.
        $this->expectException(RuntimeException::class);
        $ledger->qualify($first, $this->operator->id);
    }

    public function test_open_episode_blocks_the_chat_in_the_next_report(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $candidate = $this->eligibleFor($user)->first();
        $chatId = $candidate->telegramChatId;
        $ledger->qualify($candidate, $this->operator->id);

        Debtors::flushPairCaches();
        $again = app(ChatRemovalEligibility::class)
            ->candidates($user->id)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === $chatId);

        $this->assertNotNull($again);
        $this->assertContains(ChatRemovalEligibility::BLOCKER_ALREADY_OPEN, $again->blockers);
    }

    public function test_basis_snapshot_is_immutable(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);

        $this->expectException(RuntimeException::class);
        $removal->forceFill(['days_overdue' => 1])->save();
    }

    public function test_audit_trail_is_append_only_and_complete(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);
        $ledger->markRemoved($removal, $this->operator->id, 'кик кнопкой в «Должники»');
        $removal = $ledger->markDebtSettled($removal, $this->operator->id);
        $removal = $ledger->markFeePaid($removal, null, $this->operator->id);
        $ledger->markRestored($removal, $this->operator->id);

        $events = $removal->refresh()->events()->pluck('event')->all();

        $this->assertSame([
            CourseDebtChatRemovalEvent::QUALIFIED,
            CourseDebtChatRemovalEvent::REMOVED,
            CourseDebtChatRemovalEvent::DEBT_SETTLED,
            CourseDebtChatRemovalEvent::FEE_SETTLED,
            CourseDebtChatRemovalEvent::RESTORED,
        ], $events);

        $first = $removal->events()->first();
        $this->assertSame($this->operator->id, $first->actor_user_id);

        try {
            $first->forceFill(['event' => 'подчищено'])->save();
            $this->fail('событие аудита не должно поддаваться правке');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('неизменяем', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $first->delete();
    }

    public function test_cancelled_episode_frees_the_chat_and_owes_no_fee(): void
    {
        $user = $this->debtorInBothChats();
        $ledger = app(ChatRemovalLedger::class);

        $removal = $ledger->qualify($this->eligibleFor($user)->first(), $this->operator->id);
        $ledger->markRemoved($removal, $this->operator->id);
        $removal = $ledger->cancel($removal, 'платёж нашёлся, кик был ошибкой', $this->operator->id);

        $this->assertSame(ChatRemovalStatus::Cancelled, $removal->status);
        $this->assertSame(0, $ledger->outstandingFee($user->id)['chats']);
    }

    public function test_ineligible_candidate_cannot_enter_the_ledger(): void
    {
        // Должник без единого зафиксированного контакта.
        $user = User::factory()->create(['telegram_id' => '770099']);
        $user->groups()->attach($this->groupA->id);

        Debtors::flushPairCaches();
        $candidate = app(ChatRemovalEligibility::class)
            ->candidates($user->id)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === '-100A');

        $this->assertNotNull($candidate);
        $this->assertFalse($candidate->isEligible());

        $this->expectException(RuntimeException::class);
        app(ChatRemovalLedger::class)->qualify($candidate, $this->operator->id);
    }
}
