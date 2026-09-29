<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\User;
use App\Services\GroupMembershipManager;
use App\Services\RevenueScheduleService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * «Разбить оплату блока на другую группу»: студент оплатил блок N целиком в
 * курсе-когорте A (гр.60), но со 3-го занятия учится в курсе-когорте B (гр.61).
 *
 * Доступ к урокам считается по `payments.course_id` + ключу `payments.tariff`
 * (Lesson::isUnlockedBy), а не по членству в группе, поэтому платёж курса A
 * уроки курса B не открывает. Перенос — это ДВА платежа с ключами половин:
 *   - исходный: `block_N` → `block_N_h1`, сумма уменьшается на долю переноса;
 *   - новый на курсе B: `block_N_h2` на перенесённую долю.
 * Сумма двух платежей равна исходной копейка в копейку.
 *
 * Ключ половины открывает ТОЛЬКО уроки с lessons.block_half = H
 * (Lesson::unlockingKeys). Если в курсе нет размеченных уроков нужной половины,
 * платёж стал бы «пустым ключом» и студент потерял бы доступ, поэтому план
 * отказывает целиком, пока разметки нет.
 *
 * Членство в группе курса A не удаляется, а получает `left_at` (мягкий выход):
 * выручка группы для ЗП считается по составу `group_user`
 * (TeacherSalaryService::blockGroupRevenueDetail), и преподаватель курса A не
 * должен терять свою половину.
 *
 * Новый платёж создаётся withoutEvents: без реферальной/партнёрской награды,
 * писем и Telegram (это не новая покупка). Всё нужное делается явно: синк в
 * финансовый лист, график признания выручки, аудит, grantAccess, запись на курс.
 *
 * Money-контур: за флагом features.payment_block_half_split (дефолт OFF).
 * План (сухой прогон) ничего не пишет и от флага не зависит.
 */
final class PaymentBlockHalfSplitter
{
    /** Маркер новой строки: «разбит из платежа #id» (он же ключ идемпотентности). */
    public const MARKER_PREFIX = 'block_split_from_#';

    public const STATUS_READY = 'ready';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly GroupMembershipManager $memberships,
        private readonly RevenueScheduleService $revenue,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('features.payment_block_half_split', false);
    }

    /**
     * Сухой прогон. Ничего не пишет.
     *
     * @param  iterable<User>  $users
     * @return array{blocking: list<string>, warnings: list<string>, rows: list<array<string, mixed>>}
     */
    public function plan(iterable $users, Course $from, Course $to, int $block, float $movedPercent = 50.0): array
    {
        $blocking = $this->courseLevelProblems($from, $to, $block, $movedPercent);
        $warnings = $blocking === [] ? $this->courseLevelWarnings($from, $to, $block) : [];

        $rows = [];
        foreach ($users as $user) {
            $rows[] = $blocking === []
                ? $this->planUser($user, $from, $to, $block, $movedPercent)
                : $this->row($user, self::STATUS_REFUSED, 'Сначала устраните блокирующие проблемы курса.');
        }

        return ['blocking' => $blocking, 'warnings' => $warnings, 'rows' => $rows];
    }

    /**
     * Применить план: каждая строка `ready` — в своей транзакции, сбой одной
     * не откатывает остальные.
     *
     * @param  array{blocking: list<string>, warnings: list<string>, rows: list<array<string, mixed>>}  $plan
     * @return array{blocking: list<string>, warnings: list<string>, rows: list<array<string, mixed>>}
     */
    public function apply(array $plan, Course $from, Course $to, int $block): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('Разбиение оплаты блока выключено (features.payment_block_half_split).');
        }

        if ($plan['blocking'] !== []) {
            throw new RuntimeException('План содержит блокирующие проблемы: '.implode('; ', $plan['blocking']));
        }

        foreach ($plan['rows'] as $i => $row) {
            if ($row['status'] !== self::STATUS_READY) {
                continue;
            }

            try {
                $plan['rows'][$i] = DB::transaction(fn (): array => $this->applyRow($row, $from, $to, $block));
            } catch (Throwable $e) {
                Log::error('PaymentBlockHalfSplitter: строка не применена', [
                    'user_id' => $row['user_id'],
                    'payment_id' => $row['payment_id'],
                    'error' => $e->getMessage(),
                ]);
                $plan['rows'][$i] = array_merge($row, [
                    'status' => self::STATUS_FAILED,
                    'reason' => 'Ошибка: '.$e->getMessage(),
                ]);
            }
        }

        return $plan;
    }

    /** @return list<string> */
    private function courseLevelProblems(Course $from, Course $to, int $block, float $movedPercent): array
    {
        $problems = [];

        if ($from->is($to)) {
            $problems[] = 'Курс-источник и курс-цель совпадают.';
        }
        if ($block < 1) {
            $problems[] = 'Номер блока должен быть не меньше 1.';
        }
        if ($movedPercent <= 0 || $movedPercent >= 100) {
            $problems[] = 'Доля переноса должна быть строго между 0 и 100 %.';
        }
        if (! $to->groups()->exists()) {
            $problems[] = "У курса «{$to->title}» нет групп: платёж не сможет добавить студента в группу.";
        }

        if ($block >= 1) {
            if (! $this->hasHalfLessons($from, $block, 1)) {
                $problems[] = "В курсе «{$from->title}» нет уроков блока {$block} с разметкой «1-я половина»: "
                    ."ключ block_{$block}_h1 ничего бы не открывал, студенты потеряли бы доступ. Сначала разметьте половины уроков.";
            }
            if (! $this->hasHalfLessons($to, $block, 2)) {
                $problems[] = "В курсе «{$to->title}» нет уроков блока {$block} с разметкой «2-я половина»: "
                    ."ключ block_{$block}_h2 ничего бы не открывал. Сначала разметьте половины уроков.";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function courseLevelWarnings(Course $from, Course $to, int $block): array
    {
        $warnings = [];

        foreach ([$from, $to] as $course) {
            $unsplit = Lesson::query()
                ->where('course_id', $course->id)
                ->where('block_number', $block)
                ->whereNull('block_half')
                ->count();

            if ($unsplit > 0) {
                $warnings[] = "В курсе «{$course->title}» {$unsplit} ур. блока {$block} без разметки половины: "
                    ."их открывают только ключи full/block_{$block}, ключ половины их не откроет.";
            }
        }

        return $warnings;
    }

    private function hasHalfLessons(Course $course, int $block, int $half): bool
    {
        return Lesson::query()
            ->where('course_id', $course->id)
            ->where('block_number', $block)
            ->where('block_half', $half)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function planUser(User $user, Course $from, Course $to, int $block, float $movedPercent): array
    {
        $halfKey = 'block_'.$block.'_h2';

        $alreadySplit = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $to->id)
            ->where('tariff', $halfKey)
            ->where('transaction_id', 'like', self::MARKER_PREFIX.'%')
            ->exists();
        if ($alreadySplit) {
            return $this->row($user, self::STATUS_SKIPPED, "Уже разбит: на курсе-цели есть платёж {$halfKey} с меткой разбиения.");
        }

        $candidates = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $from->id)
            ->where('tariff', 'block_'.$block)
            ->paid()
            ->get();

        if ($candidates->isEmpty()) {
            return $this->row($user, self::STATUS_REFUSED, "Нет оплаченного платежа block_{$block} по курсу «{$from->title}».");
        }
        if ($candidates->count() > 1) {
            return $this->row($user, self::STATUS_REFUSED, "Несколько платежей block_{$block} (".$candidates->pluck('id')->implode(', ').') — разберите вручную.');
        }

        /** @var Payment $payment */
        $payment = $candidates->first();

        $refusal = match (true) {
            (bool) $payment->is_conditional => 'Платёж «под обещание» (conditional) — не деньги, разбивать нельзя.',
            (int) $payment->start_block !== $block || (int) $payment->end_block !== $block => "Платёж покрывает не один блок {$block} (start_block/end_block) — разберите вручную.",
            $payment->received_account !== Payment::RECEIVED_SCHOOL => 'Деньги получены не кассой школы (личный счёт преподавателя) — решает финансист.',
            $payment->refunds()->exists() => 'У платежа есть возвраты — разберите вручную.',
            default => null,
        };
        if ($refusal !== null) {
            return $this->row($user, self::STATUS_REFUSED, $refusal, $payment);
        }

        $cents = (int) round((float) $payment->amount * 100);
        $movedCents = (int) round($cents * $movedPercent / 100);
        $keptCents = $cents - $movedCents;

        $row = $this->row($user, self::STATUS_READY, null, $payment);
        $row['amount_before'] = Money::ofCents($cents)->value();
        $row['amount_kept'] = Money::ofCents($keptCents)->value();
        $row['amount_moved'] = Money::ofCents($movedCents)->value();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyRow(array $row, Course $from, Course $to, int $block): array
    {
        /** @var Payment $orig */
        $orig = Payment::query()->lockForUpdate()->findOrFail($row['payment_id']);

        $cents = (int) round((float) $orig->amount * 100);

        // Гонка: между планом и применением платёж уже изменили.
        if ($orig->tariff !== 'block_'.$block
            || (int) $orig->course_id !== $from->id
            || $cents !== (int) round($row['amount_before'] * 100)) {
            return array_merge($row, [
                'status' => self::STATUS_SKIPPED,
                'reason' => 'Платёж изменился после сухого прогона — пересоберите план.',
            ]);
        }

        $movedCents = (int) round($row['amount_moved'] * 100);
        $keptCents = $cents - $movedCents;
        $ratio = $cents > 0 ? $movedCents / $cents : 0.0;
        $user = User::query()->findOrFail($orig->user_id);
        $actorId = auth()->id();

        // 1. Новая строка на курсе-цели — тихо (без наград/писем/Telegram).
        $new = new Payment([
            'user_id' => $orig->user_id,
            'course_id' => $to->id,
            'amount' => Money::ofCents($movedCents)->value(),
            'tariff' => 'block_'.$block.'_h2',
            'status' => $orig->status,
            'start_block' => $block,
            'end_block' => $block,
            'is_conditional' => false,
            'transaction_id' => self::MARKER_PREFIX.$orig->id,
            'payment_method' => $orig->payment_method,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'first_paid_at' => $orig->first_paid_at ?? now(),
            'discount_percent' => $orig->discount_percent,
            'discount_amount' => $orig->discount_amount !== null
                ? round((float) $orig->discount_amount * $ratio, 2)
                : null,
            'foreign_amount' => $orig->foreign_amount !== null
                ? round((float) $orig->foreign_amount * $ratio, 2)
                : null,
            'foreign_currency' => $orig->foreign_currency,
            'salary_recognition_month' => $orig->salary_recognition_month,
            // Месяц кассы не сдвигается: дата — исходного платежа.
            'created_at' => $orig->created_at,
        ]);
        $new->created_by_user_id = $actorId;
        $new->updated_by_user_id = $actorId;
        Payment::withoutEvents(fn () => $new->save());

        // 2. Исходная строка — обычным update: аудит, синк «update» и пересбор
        //    графика выручки сработают через наблюдателей.
        $orig->update([
            'tariff' => 'block_'.$block.'_h1',
            'amount' => Money::ofCents($keptCents)->value(),
            'discount_amount' => $orig->discount_amount !== null
                ? round((float) $orig->discount_amount * (1 - $ratio), 2)
                : null,
            'foreign_amount' => $orig->foreign_amount !== null
                ? round((float) $orig->foreign_amount * (1 - $ratio), 2)
                : null,
        ]);

        // 3. Всё, что для новой строки делают наблюдатели (мы их обошли).
        PaymentAudit::create([
            'payment_id' => $new->id,
            'admin_id' => $actorId,
            'admin_name' => auth()->user()?->name ?? 'Система',
            'action' => PaymentAudit::ACTION_CREATED,
            'amount' => $new->amount,
            'changes' => [
                'split_from_payment_id' => [null, $orig->id],
                'course_id' => [$from->id, $to->id],
                'tariff' => ['block_'.$block, 'block_'.$block.'_h2'],
                'amount' => [Money::ofCents($cents)->value(), Money::ofCents($movedCents)->value()],
            ],
            'created_at' => now(),
        ]);
        $this->revenue->regenerateFor($new);
        DB::afterCommit(fn () => SendPaymentToSheetJob::dispatch($new->id, 'create'));

        // 4. Доступ и составы групп.
        $new->grantAccess();
        $new->enrollInCourse();

        $targetGroupIds = $to->groups()->pluck('groups.id')->all();
        $this->memberships->restore($user, $targetGroupIds, GroupMembershipManager::REASON_MANUAL);

        $sourceOnlyGroupIds = $from->groups()
            ->whereNotIn('groups.id', $targetGroupIds)
            ->pluck('groups.id')
            ->all();
        $this->memberships->softLeave($user, $sourceOnlyGroupIds, GroupMembershipManager::REASON_MANUAL);

        return array_merge($row, [
            'status' => self::STATUS_DONE,
            'reason' => null,
            'new_payment_id' => $new->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(User $user, string $status, ?string $reason, ?Payment $payment = null): array
    {
        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'status' => $status,
            'reason' => $reason,
            'payment_id' => $payment?->id,
            'amount_before' => $payment !== null ? (float) $payment->amount : null,
            'amount_kept' => null,
            'amount_moved' => null,
            'new_payment_id' => null,
        ];
    }
}
