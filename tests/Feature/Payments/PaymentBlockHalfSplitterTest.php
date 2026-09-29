<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\StudentController;
use App\Jobs\SendPaymentToSheetJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\Tariff;
use App\Models\User;
use App\Services\PartnerService;
use App\Services\Payments\PaymentBlockHalfSplitter;
use App\Services\ReferralService;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Разбиение оплаченного блока между двумя курсами-когортами (гр.60 → гр.61).
 *
 * Пинним три инварианта money/access-контура:
 *  1. сумма двух платежей = исходной копейка в копейку;
 *  2. у студента открыты уроки нужных половин В ОБОИХ курсах;
 *  3. «нового платежа» для реферала/партнёра не возникает (это не покупка).
 */
class PaymentBlockHalfSplitterTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCK = 3;

    private Course $from;

    private Course $to;

    private Group $fromGroup;

    private Group $toGroup;

    /** @var array<string, Lesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.payment_block_half_split' => true]);

        $this->from = Course::factory()->create(['slug' => 'gram-gr60', 'title' => 'Грамматика гр.60']);
        $this->to = Course::factory()->create(['slug' => 'gram-gr61', 'title' => 'Грамматика гр.61']);

        $this->fromGroup = Group::factory()->create();
        $this->toGroup = Group::factory()->create();
        $this->from->groups()->attach($this->fromGroup->id);
        $this->to->groups()->attach($this->toGroup->id);

        foreach ([[$this->from, 'from'], [$this->to, 'to']] as [$course, $tag]) {
            foreach ([1, 2] as $half) {
                $this->lessons["{$tag}_h{$half}"] = Lesson::factory()->create([
                    'course_id' => $course->id,
                    'block_number' => self::BLOCK,
                    'block_half' => $half,
                ]);
            }
            // Блок должен быть известен курсу (иначе график/ЗП не узнают номер блока).
            Tariff::factory()->block(self::BLOCK)->create(['course_id' => $course->id, 'price' => 4800]);
        }
    }

    private function splitter(): PaymentBlockHalfSplitter
    {
        return app(PaymentBlockHalfSplitter::class);
    }

    private function student(): User
    {
        return User::factory()->create();
    }

    private function pay(User $user, float $amount = 4800.0, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $this->from->id,
            'amount' => $amount,
            'tariff' => 'block_'.self::BLOCK,
            'status' => 'paid',
            'start_block' => self::BLOCK,
            'end_block' => self::BLOCK,
        ], $extra));
    }

    /** @param  list<User>  $users */
    private function split(array $users, float $percent = 50.0): array
    {
        $plan = $this->splitter()->plan($users, $this->from, $this->to, self::BLOCK, $percent);

        return $this->splitter()->apply($plan, $this->from, $this->to, self::BLOCK);
    }

    private function unlocked(User $user, Course $course, Lesson $lesson): bool
    {
        return $lesson->isUnlockedBy(StudentController::getUserUnlockedTariffs($user->id, $course->slug));
    }

    public function test_split_conserves_money_to_the_kopeck(): void
    {
        $user = $this->student();
        $orig = $this->pay($user, 1000.01);

        $result = $this->split([$user]);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_DONE, $result['rows'][0]['status']);

        $orig->refresh();
        $new = Payment::query()->findOrFail($result['rows'][0]['new_payment_id']);

        $this->assertSame(
            100001,
            (int) round(((float) $orig->amount + (float) $new->amount) * 100),
            'Сумма двух платежей обязана равняться исходной.',
        );
        $this->assertSame('block_3_h1', $orig->tariff);
        $this->assertSame($this->from->id, (int) $orig->course_id);
        $this->assertSame('block_3_h2', $new->tariff);
        $this->assertSame($this->to->id, (int) $new->course_id);
        $this->assertSame(PaymentBlockHalfSplitter::MARKER_PREFIX.$orig->id, $new->transaction_id);
        $this->assertSame('paid', $new->status);
        $this->assertSame(self::BLOCK, (int) $new->start_block);
        $this->assertSame(self::BLOCK, (int) $new->end_block);
        $this->assertTrue($orig->created_at->equalTo($new->created_at), 'Месяц кассы не должен сдвигаться.');
    }

    public function test_custom_share_splits_proportionally(): void
    {
        $user = $this->student();
        $this->pay($user, 4800.0);

        $result = $this->split([$user], 25.0);

        $this->assertSame(3600.0, $result['rows'][0]['amount_kept']);
        $this->assertSame(1200.0, $result['rows'][0]['amount_moved']);
    }

    public function test_student_has_the_right_lessons_open_in_both_courses(): void
    {
        $user = $this->student();
        $this->pay($user);

        // До разбиения: блок целиком в курсе-источнике, в курсе-цели ничего.
        $this->assertTrue($this->unlocked($user, $this->from, $this->lessons['from_h1']));
        $this->assertTrue($this->unlocked($user, $this->from, $this->lessons['from_h2']));
        $this->assertFalse($this->unlocked($user, $this->to, $this->lessons['to_h2']));

        $this->split([$user]);

        $this->assertTrue($this->unlocked($user, $this->from, $this->lessons['from_h1']), '1-я половина остаётся в гр.60.');
        $this->assertFalse($this->unlocked($user, $this->from, $this->lessons['from_h2']), '2-я половина гр.60 закрывается.');
        $this->assertTrue($this->unlocked($user, $this->to, $this->lessons['to_h2']), '2-я половина гр.61 открывается.');
        $this->assertFalse($this->unlocked($user, $this->to, $this->lessons['to_h1']), '1-я половина гр.61 не выдаётся.');
    }

    public function test_group_membership_moves_by_soft_leave_not_detach(): void
    {
        $user = $this->student();
        $this->pay($user);
        $this->assertTrue($user->groups()->where('groups.id', $this->fromGroup->id)->exists(), 'Предусловие: grantAccess добавил в группу гр.60.');

        $this->split([$user]);

        $user = $user->fresh();
        $toPivot = $user->groups()->where('groups.id', $this->toGroup->id)->first()?->pivot;
        $fromPivot = $user->groups()->where('groups.id', $this->fromGroup->id)->first()?->pivot;

        $this->assertNotNull($toPivot, 'Студент добавлен в группу гр.61.');
        $this->assertNull($toPivot->left_at);
        $this->assertNotNull($fromPivot, 'Строка group_user гр.60 сохранена: по ней считается выручка группы для ЗП.');
        $this->assertNotNull($fromPivot->left_at, 'Из активного состава гр.60 студент скрыт.');
        $this->assertTrue($user->courses()->where('courses.id', $this->to->id)->exists(), 'Студент записан на курс гр.61.');
    }

    public function test_group_revenue_for_salary_is_split_between_the_two_groups(): void
    {
        $users = [$this->student(), $this->student(), $this->student()];
        foreach ($users as $user) {
            $this->pay($user, 4800.0);
        }

        $salary = app(TeacherSalaryService::class);
        $before = $salary->blockGroupRevenue($this->from->id, self::BLOCK, $this->fromGroup->id);
        $this->assertSame(14400.0, $before, 'Предусловие: вся выручка блока у гр.60.');

        $this->split($users);

        $salary = app(TeacherSalaryService::class); // сброс кэшей сервиса
        $fromRevenue = $salary->blockGroupRevenue($this->from->id, self::BLOCK, $this->fromGroup->id);
        $toRevenue = $salary->blockGroupRevenue($this->to->id, self::BLOCK, $this->toGroup->id);

        $this->assertSame(7200.0, $fromRevenue, 'Преподаватель гр.60 сохраняет свою половину.');
        $this->assertSame(7200.0, $toRevenue, 'Преподаватель гр.61 получает вторую половину.');
        $this->assertSame($before, $fromRevenue + $toRevenue, 'Итог выручки блока не изменился.');
    }

    public function test_no_referral_or_partner_reward_and_no_notifications_for_the_new_row(): void
    {
        $user = $this->student();
        $this->pay($user);

        $referral = $this->spy(ReferralService::class);
        $partner = $this->spy(PartnerService::class);

        $this->split([$user]);

        $referral->shouldNotHaveReceived('rewardForPayment');
        $partner->shouldNotHaveReceived('rewardForPayment');
    }

    public function test_new_row_is_synced_to_the_sheet_and_audited(): void
    {
        $user = $this->student();
        $orig = $this->pay($user);

        Queue::fake();
        $result = $this->split([$user]);
        $newId = $result['rows'][0]['new_payment_id'];

        Queue::assertPushed(
            SendPaymentToSheetJob::class,
            fn (SendPaymentToSheetJob $job): bool => $job->paymentId === $newId && $job->action === 'create',
        );

        $created = PaymentAudit::query()->where('payment_id', $newId)->where('action', PaymentAudit::ACTION_CREATED)->first();
        $this->assertNotNull($created);
        $this->assertSame([null, $orig->id], $created->changes['split_from_payment_id']);

        $updated = PaymentAudit::query()->where('payment_id', $orig->id)->where('action', PaymentAudit::ACTION_UPDATED)->latest('id')->first();
        $this->assertNotNull($updated, 'Правка исходного платежа аудируется наблюдателем.');
        $this->assertSame(['block_3', 'block_3_h1'], $updated->changes['tariff']);
    }

    public function test_apply_is_idempotent(): void
    {
        $user = $this->student();
        $this->pay($user);

        $this->split([$user]);
        $paymentsAfterFirst = Payment::query()->count();

        $second = $this->split([$user]);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_SKIPPED, $second['rows'][0]['status']);
        $this->assertSame($paymentsAfterFirst, Payment::query()->count(), 'Повторный прогон не плодит платежи.');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = $this->student();
        $this->pay($user);
        $paymentCount = Payment::query()->count();
        $auditCount = PaymentAudit::query()->count();
        $pivotCount = DB::table('group_user')->count();

        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_READY, $plan['rows'][0]['status']);
        $this->assertSame(2400.0, $plan['rows'][0]['amount_moved']);
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($auditCount, PaymentAudit::query()->count());
        $this->assertSame($pivotCount, DB::table('group_user')->count());
        $this->assertSame('block_3', Payment::query()->where('user_id', $user->id)->value('tariff'));
    }

    public function test_apply_refuses_when_the_flag_is_off(): void
    {
        config(['features.payment_block_half_split' => false]);
        $user = $this->student();
        $this->pay($user);

        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);
        $this->assertSame(PaymentBlockHalfSplitter::STATUS_READY, $plan['rows'][0]['status'], 'Сухой прогон от флага не зависит.');

        $this->expectException(RuntimeException::class);
        try {
            $this->splitter()->apply($plan, $this->from, $this->to, self::BLOCK);
        } finally {
            $this->assertSame('block_3', Payment::query()->where('user_id', $user->id)->value('tariff'));
        }
    }

    #[DataProvider('refusalCases')]
    public function test_payments_that_must_not_be_split_are_refused(string $case): void
    {
        $user = $this->student();
        $orig = $this->pay($user);

        match ($case) {
            'conditional' => Payment::query()->whereKey($orig->id)->update(['is_conditional' => true]),
            'range' => Payment::query()->whereKey($orig->id)->update(['start_block' => 3, 'end_block' => 5]),
            'no_range' => Payment::query()->whereKey($orig->id)->update(['start_block' => null, 'end_block' => null]),
            'teacher_account' => Payment::query()->whereKey($orig->id)->update(['received_account' => Payment::RECEIVED_TEACHER]),
            'refund' => Payment::create([
                'user_id' => $user->id,
                'course_id' => $this->from->id,
                'amount' => -100,
                'tariff' => 'Расход',
                'status' => 'paid',
                'refund_of_payment_id' => $orig->id,
            ]),
            'duplicate' => $this->pay($user),
            'no_payment' => Payment::query()->whereKey($orig->id)->update(['tariff' => 'block_9']),
            'unpaid' => Payment::query()->whereKey($orig->id)->update(['status' => 'pending']),
        };

        $result = $this->split([$user]);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_REFUSED, $result['rows'][0]['status'], $case);
        $this->assertNotEmpty($result['rows'][0]['reason']);
        $this->assertNull($result['rows'][0]['new_payment_id']);
        $this->assertSame(
            0,
            Payment::query()->where('transaction_id', 'like', PaymentBlockHalfSplitter::MARKER_PREFIX.'%')->count(),
            'Отказ ничего не пишет.',
        );
    }

    /** @return array<string, array{string}> */
    public static function refusalCases(): array
    {
        return array_combine(
            ['conditional', 'range', 'no_range', 'teacher_account', 'refund', 'duplicate', 'no_payment', 'unpaid'],
            array_map(fn (string $c): array => [$c], ['conditional', 'range', 'no_range', 'teacher_account', 'refund', 'duplicate', 'no_payment', 'unpaid']),
        );
    }

    public function test_missing_half_markup_blocks_the_whole_plan_to_avoid_losing_access(): void
    {
        $user = $this->student();
        $this->pay($user);
        Lesson::query()->where('course_id', $this->from->id)->update(['block_half' => null]);

        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);

        $this->assertNotEmpty($plan['blocking']);
        $this->assertStringContainsString('1-я половина', $plan['blocking'][0]);
        $this->assertSame(PaymentBlockHalfSplitter::STATUS_REFUSED, $plan['rows'][0]['status']);

        try {
            $this->splitter()->apply($plan, $this->from, $this->to, self::BLOCK);
            $this->fail('apply() обязан отказаться при блокирующих проблемах.');
        } catch (RuntimeException) {
            $this->assertSame('block_3', Payment::query()->where('user_id', $user->id)->value('tariff'), 'Доступ студента не тронут.');
        }
    }

    public function test_target_course_without_half_markup_or_groups_blocks_the_plan(): void
    {
        $user = $this->student();
        $this->pay($user);

        Lesson::query()->where('course_id', $this->to->id)->update(['block_half' => null]);
        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);
        $this->assertNotEmpty($plan['blocking']);
        $this->assertStringContainsString('2-я половина', implode(' ', $plan['blocking']));

        Lesson::query()->where('course_id', $this->to->id)->where('id', $this->lessons['to_h2']->id)->update(['block_half' => 2]);
        $this->to->groups()->detach();
        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);
        $this->assertStringContainsString('нет групп', implode(' ', $plan['blocking']));
    }

    public function test_bad_percent_and_same_course_are_blocking(): void
    {
        $user = $this->student();
        $this->pay($user);

        foreach ([0.0, 100.0, -5.0] as $percent) {
            $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK, $percent);
            $this->assertNotEmpty($plan['blocking'], "percent={$percent}");
        }

        $plan = $this->splitter()->plan([$user], $this->from, $this->from, self::BLOCK);
        $this->assertNotEmpty($plan['blocking']);
    }

    public function test_one_failing_student_does_not_roll_back_the_others(): void
    {
        $good = $this->student();
        $bad = $this->student();
        $this->pay($good);
        $this->pay($bad);

        $plan = $this->splitter()->plan([$bad, $good], $this->from, $this->to, self::BLOCK);
        // Портим план «плохого» студента: платёж исчезает между сухим прогоном и применением.
        Payment::query()->where('user_id', $bad->id)->delete();

        $result = $this->splitter()->apply($plan, $this->from, $this->to, self::BLOCK);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_FAILED, $result['rows'][0]['status']);
        $this->assertSame(PaymentBlockHalfSplitter::STATUS_DONE, $result['rows'][1]['status']);
        $this->assertSame('block_3_h1', Payment::query()->where('user_id', $good->id)->where('course_id', $this->from->id)->value('tariff'));
    }

    public function test_plan_changed_after_dry_run_is_skipped_not_applied(): void
    {
        $user = $this->student();
        $orig = $this->pay($user, 4800.0);

        $plan = $this->splitter()->plan([$user], $this->from, $this->to, self::BLOCK);
        Payment::query()->whereKey($orig->id)->update(['amount' => 5000]);

        $result = $this->splitter()->apply($plan, $this->from, $this->to, self::BLOCK);

        $this->assertSame(PaymentBlockHalfSplitter::STATUS_SKIPPED, $result['rows'][0]['status']);
        $this->assertSame('block_3', $orig->fresh()->tariff);
    }

    public function test_discount_and_foreign_amount_are_split_with_the_same_ratio(): void
    {
        $user = $this->student();
        $orig = $this->pay($user, 4000.0, [
            'discount_percent' => 10,
            'discount_amount' => 400,
            'foreign_amount' => 44.0,
            'foreign_currency' => 'USD',
        ]);

        $result = $this->split([$user]);
        $new = Payment::query()->findOrFail($result['rows'][0]['new_payment_id']);
        $orig->refresh();

        $this->assertSame(200.0, (float) $orig->discount_amount);
        $this->assertSame(200.0, (float) $new->discount_amount);
        $this->assertEqualsWithDelta(44.0, (float) $orig->foreign_amount + (float) $new->foreign_amount, 0.001);
        $this->assertSame('USD', $new->foreign_currency);
        $this->assertEquals(10, $new->discount_percent);
    }
}
