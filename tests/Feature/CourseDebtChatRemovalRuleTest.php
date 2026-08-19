<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\ChatMessage;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Discipline\ChatRemovalCandidate;
use App\Services\Discipline\ChatRemovalEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Матрица правила H2746: кто подлежит исключению из учебного TG-чата за
 * курсовой долг и молчание, а кто — нет и почему.
 *
 * Каждый негативный кейс здесь соответствует строке «Fail =» из handoff'а:
 * исключение по одному контакту, просрочка меньше 30 дней, членство,
 * принятое за долг, неоднозначный плательщик, спорный (отсроченный) долг.
 */
class CourseDebtChatRemovalRuleTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Carbon $blockStart;

    protected function setUp(): void
    {
        parent::setUp();

        Debtors::flushPairCaches();

        // Блок стартовал 45 дней назад — просрочка заведомо больше порога в 30.
        $this->blockStart = Carbon::now()->subDays(45)->startOfDay();

        $this->course = Course::factory()->create([
            'is_active' => true,
            'slug' => 'h2746-course',
            'title' => 'Санскрит — основной курс',
        ]);
        CourseBlock::factory()
            ->for($this->course)
            ->withDates($this->blockStart, $this->blockStart->copy()->addDays(30))
            ->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $this->course->id, 'price' => 4800]);

        $this->group = Group::create([
            'name' => 'Вторник 19:00',
            'telegram_chat_id' => '-100H2746',
            'status' => 'active',
        ]);
        $this->course->groups()->attach($this->group->id);
    }

    private function eligibility(): ChatRemovalEligibility
    {
        Debtors::flushPairCaches();

        return app(ChatRemovalEligibility::class);
    }

    /** Должник по курсу: в группе курса, без единого реального платежа. */
    private function debtor(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['telegram_id' => '900100'], $attrs));
        $user->groups()->attach($this->group->id);

        return $user;
    }

    /** Два авто-напоминания внутри эпизода долга, оба без ответа. */
    private function twoUnansweredContacts(User $user): void
    {
        foreach ([30, 12] as $daysAgo) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $this->course->id,
                'block_number' => 1,
                'sent_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    /**
     * Ответ студента в web-чате в заданный момент. Timestamps выставляем
     * отдельным saveQuietly: `created_at` не в $fillable у ChatMessage, и
     * переданный в create() он молча заменяется на now() — с ним тест
     * «ответ между контактами» доказывал бы не то, что написано в названии.
     */
    private function studentReply(User $user, Carbon $at, string $text = 'да, помню'): void
    {
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $text,
        ]);

        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    private function candidateFor(User $user): ?ChatRemovalCandidate
    {
        return $this->eligibility()
            ->candidates($user->id)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === '-100H2746');
    }

    public function test_qualifying_case_passes_with_full_basis(): void
    {
        $user = $this->debtor();
        $this->twoUnansweredContacts($user);

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertSame([], $candidate->blockers);
        $this->assertTrue($candidate->isEligible());
        $this->assertGreaterThanOrEqual(30, $candidate->daysOverdue);
        $this->assertSame(2, $candidate->evidence->trailingUnanswered);
        $this->assertSame([1], $candidate->debtBlockNumbers);
        $this->assertEqualsWithDelta(4800.0, (float) $candidate->debtAmount, 0.01);
        $this->assertSame(1000, $candidate->reinstatementFee);
        $this->assertNotNull($candidate->evidence->silentSince);
    }

    public function test_single_contact_never_qualifies(): void
    {
        $user = $this->debtor();
        DebtReminder::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'block_number' => 1,
            'sent_at' => Carbon::now()->subDays(20),
        ]);

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_INSUFFICIENT_CONTACTS,
            $candidate->blockers,
        );
        $this->assertFalse($candidate->isEligible());
    }

    public function test_debt_younger_than_threshold_never_qualifies(): void
    {
        // Свежий курс: блок стартовал 5 дней назад.
        $fresh = Course::factory()->create(['is_active' => true, 'slug' => 'h2746-fresh']);
        $start = Carbon::now()->subDays(5)->startOfDay();
        CourseBlock::factory()->for($fresh)->withDates($start, $start->copy()->addDays(30))->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $fresh->id, 'price' => 3000]);

        $freshGroup = Group::create([
            'name' => 'Свежая группа',
            'telegram_chat_id' => '-100FRESH',
            'status' => 'active',
        ]);
        $fresh->groups()->attach($freshGroup->id);

        $user = User::factory()->create(['telegram_id' => '900700']);
        $user->groups()->attach($freshGroup->id);

        foreach ([4, 2] as $daysAgo) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $fresh->id,
                'block_number' => 1,
                'sent_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        $candidate = $this->eligibility()
            ->candidates($user->id, $fresh->id)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === '-100FRESH');

        $this->assertNotNull($candidate);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_NOT_OVERDUE_ENOUGH,
            $candidate->blockers,
        );
    }

    public function test_student_who_answered_is_not_silent(): void
    {
        $user = $this->debtor();
        $this->twoUnansweredContacts($user);

        // Ответ пришёл ПОСЛЕ второго напоминания — молчания нет.
        $this->studentReply($user, Carbon::now()->subDays(5), 'Извините, оплачу на следующей неделе');

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_STUDENT_RESPONDED,
            $candidate->blockers,
        );
        $this->assertSame(0, $candidate->evidence->trailingUnanswered);
    }

    public function test_answer_between_contacts_restarts_the_silence_count(): void
    {
        $user = $this->debtor();
        $this->twoUnansweredContacts($user); // 30 и 12 дней назад

        $this->studentReply($user, Carbon::now()->subDays(20), 'да-да, помню');

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        // Первый контакт отвечен, второй — нет: хвост = 1, порога не хватает.
        $this->assertSame(1, $candidate->evidence->trailingUnanswered);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_STUDENT_RESPONDED,
            $candidate->blockers,
        );
    }

    public function test_membership_lapse_is_never_course_debt(): void
    {
        // Курс оплачен полностью, а клубное членство просрочено на полгода.
        $user = $this->debtor(['telegram_id' => '900200']);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 4800,
            'status' => 'paid',
            'is_conditional' => false,
            'start_block' => 1,
            'end_block' => 1,
            'tariff' => 'Блок 1',
        ]);
        ClubMembership::create([
            'user_id' => $user->id,
            'tier_code' => 'club',
            'term_months' => 1,
            'starts_at' => Carbon::now()->subMonths(7),
            'ends_at' => Carbon::now()->subMonths(6),
            'grace_until' => Carbon::now()->subMonths(6)->addDays(3),
            'source' => ClubMembership::SOURCE_MANUAL,
        ]);
        $this->twoUnansweredContacts($user);

        $candidate = $this->candidateFor($user);

        // Курсового долга нет вовсе — строки в отчёте быть не должно.
        $this->assertNull($candidate);
    }

    public function test_membership_payment_never_covers_course_debt(): void
    {
        // Обратная сторона того же guardrail: членский платёж не должен
        // ни закрывать курсовой долг, ни считаться «ответом» студента.
        $user = $this->debtor(['telegram_id' => '900250']);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 1500,
            'status' => 'paid',
            'is_conditional' => false,
            'tariff' => 'membership_club_1m',
        ]);
        $this->twoUnansweredContacts($user);

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertTrue($candidate->isEligible(), implode(', ', $candidate->blockers));
        $this->assertSame(2, $candidate->evidence->trailingUnanswered);
    }

    public function test_active_promise_makes_the_debt_disputed(): void
    {
        $user = $this->debtor(['telegram_id' => '900300']);
        $this->twoUnansweredContacts($user);

        PaymentPromise::withoutEvents(function () use ($user): void {
            PaymentPromise::create([
                'user_id' => $user->id,
                'course_id' => $this->course->id,
                'promised_at' => Carbon::now()->addDays(7)->toDateString(),
                'amount' => 4800,
                'status' => PaymentPromise::STATUS_ACTIVE,
            ]);
        });

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_DEBT_DISPUTED,
            $candidate->blockers,
        );
    }

    public function test_missing_telegram_link_blocks_removal(): void
    {
        $user = $this->debtor(['telegram_id' => null]);
        $this->twoUnansweredContacts($user);

        // Без telegram_id чат вообще не резолвится — строка приходит без чата.
        $row = $this->eligibility()->candidates($user->id)->first();

        $this->assertNotNull($row);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_NO_TELEGRAM_LINK,
            $row->blockers,
        );
    }

    public function test_shared_telegram_id_is_ambiguous_identity(): void
    {
        $user = $this->debtor(['telegram_id' => '900400']);
        // Второй аккаунт с тем же telegram_id — кто из них платил, неясно.
        User::factory()->create(['telegram_id' => '900400']);
        $this->twoUnansweredContacts($user);

        $candidate = $this->candidateFor($user);

        $this->assertNotNull($candidate);
        $this->assertContains(
            ChatRemovalEligibility::BLOCKER_AMBIGUOUS_IDENTITY,
            $candidate->blockers,
        );
    }

    public function test_group_without_chat_id_yields_no_removable_chat(): void
    {
        $chatless = Group::create(['name' => 'Без чата', 'status' => 'active']);
        $this->course->groups()->attach($chatless->id);

        $user = User::factory()->create(['telegram_id' => '900500']);
        $user->groups()->attach($chatless->id);
        $this->twoUnansweredContacts($user);

        $rows = $this->eligibility()->candidates($user->id);

        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue(
            $rows->every(fn (ChatRemovalCandidate $c) => in_array('no_study_chat', $c->blockers, true)),
        );
    }
}
