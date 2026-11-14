<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Filament\Resources\PaymentResource;
use App\Jobs\SendTelegramChatMessageJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\BlockMonthRecognition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * H8250 — B2B-напоминания плательщику: после даты последнего урока покрытого
 * b2b-блока N плательщик получает РОВНО одно Telegram-напоминание оплатить
 * блок N+1 (гр.64 «Грамматика по Кочергиной», курс 463, плательщик — Алексей,
 * студия Адара; MG 14-11-2026). В сообщении оба пути оплаты: счёт юрлицу
 * или наличными.
 *
 * Гварды:
 *  - features.b2b_payer_reminders (дефолт OFF, env B2B_PAYER_REMINDERS) —
 *    собственный выключатель лейна поверх живого features.b2b_group_payment:
 *    напоминания не активируются молча вместе с b2b-контуром, только явным
 *    переворотом флага (DEPLOY_QUEUE). OFF → весь прогон no-op.
 *  - Дедуп ровно-один на (плательщик, блок): маркер в claim_meta платежа
 *    (`b2b_payer_reminder_block_N`), ставится в момент постановки job'а.
 *  - Предусловие MG: напоминания активны, только когда у плательщика есть
 *    личный кабинет (payment.user) и привязанный Telegram; без Telegram —
 *    задача кураторам в общий чат (со ссылкой на платёж в Filament).
 *  - Ученикам группы не пишем ничего — только плательщику.
 *  - (null,null) в диапазоне платежа = оплачен весь курс → тишина.
 *
 * Денежный контур не трогает: статус/диапазоны платежа только читаются,
 * запись — исключительно маркер дедупа в claim_meta.
 */
final class B2bPayerReminderService
{
    /**
     * Ключ дедупа в claim_meta платежа: наличие = «напоминание за блок N
     * уже поставлено», значение = stamp постановки.
     */
    public static function dedupKey(int $blockNumber): string
    {
        return 'b2b_payer_reminder_block_'.$blockNumber;
    }

    /** Лейн включён: свой флаг напоминаний + живой b2b-контур. */
    public static function enabled(): bool
    {
        return (bool) config('features.b2b_payer_reminders')
            && B2bGroupEntitlement::enabled();
    }

    /**
     * Оплаченные b2b_group-платежи с курсом и группой — рабочий список прогона.
     *
     * @return Collection<int, Payment>
     */
    public function payments(): Collection
    {
        return Payment::query()
            ->where('tariff', Payment::TARIFF_B2B_GROUP)
            ->paid()
            ->real()
            ->whereNotNull('course_id')
            ->whereNotNull('group_id')
            ->with('user')
            ->get();
    }

    /**
     * Прогон по всем оплаченным b2b_group-платежам: назревшие цели получают
     * ровно одно напоминание (Telegram плательщику или задача кураторам).
     *
     * @return array{checked: int, sent: int, curator_tasks: int, skipped: int}
     */
    public function run(): array
    {
        $stats = ['checked' => 0, 'sent' => 0, 'curator_tasks' => 0, 'skipped' => 0];

        if (! self::enabled()) {
            return $stats;
        }

        foreach ($this->payments() as $payment) {
            $stats['checked']++;

            $nextBlock = $this->dueNextBlock($payment);
            if ($nextBlock === null) {
                $stats['skipped']++;

                continue;
            }

            match ($this->remind($payment, $nextBlock)) {
                'sent' => $stats['sent']++,
                'curator' => $stats['curator_tasks']++,
                default => $stats['skipped']++,
            };
        }

        return $stats;
    }

    /**
     * Блок N+1, напоминание о котором назрело: существует покрытый блок N,
     * дата последнего урока которого уже прошла, при этом N+1 заведён
     * в расписании группы, НЕ покрыт платежом и дедуп-маркера для него
     * ещё нет. Иначе null.
     */
    public function dueNextBlock(Payment $payment): ?int
    {
        // (null,null) = оплачен весь курс — напоминаний нет вообще.
        if ($payment->start_block === null && $payment->end_block === null) {
            return null;
        }

        $blockNumbers = $this->groupBlockNumbers($payment);
        if ($blockNumbers === []) {
            return null;
        }

        $covered = BlockMonthRecognition::coveredBlockNumbers(
            $payment->start_block,
            $payment->end_block,
            $blockNumbers,
        );

        $today = Carbon::today();

        foreach ($covered as $n) {
            $next = $n + 1;

            // Следующего блока нет в расписании или он уже оплачен.
            if (! in_array($next, $blockNumbers, true) || in_array($next, $covered, true)) {
                continue;
            }

            $lastLessonDate = Lesson::query()
                ->where('course_id', $payment->course_id)
                ->where('group_id', $payment->group_id)
                ->where('block_number', $n)
                ->max('lesson_date');

            if ($lastLessonDate === null) {
                // Блок без датированных уроков — датировать триггер нечем:
                // названный пропуск, не выдуманная дата.
                Log::info('B2bPayerReminder: block without dated lessons, skip', [
                    'payment_id' => $payment->id,
                    'block_number' => $n,
                ]);

                continue;
            }

            // Триггер — «дата последнего урока блока N прошла»: строго до сегодня.
            if (Carbon::parse($lastLessonDate)->gte($today)) {
                continue;
            }

            // Ровно одно на (плательщик, блок).
            if ($payment->claimMeta(self::dedupKey($next)) !== null) {
                continue;
            }

            return $next;
        }

        return null;
    }

    /**
     * Поставить ровно одно напоминание за (плательщик, блок). Маркер дедупа
     * ставится в момент постановки job'а — до фактической доставки: повторный
     * прогон никогда не задублирует сообщение.
     *
     * @return string sent|curator|skipped
     */
    public function remind(Payment $payment, int $nextBlock): string
    {
        $payer = $payment->user;

        // Предусловие MG: лейн живёт только при личном кабинете плательщика.
        // b2b-платёж без user_id вне контура — тишина, дедуп не сжигаем.
        if ($payer === null) {
            Log::info('B2bPayerReminder: payment without payer user, skip', [
                'payment_id' => $payment->id,
            ]);

            return 'skipped';
        }

        if (filled($payer->telegram_username) && filled($payer->telegram_id)) {
            $this->markReminder($payment, $nextBlock);
            SendTelegramMessageJob::dispatch($payer->id, $this->payerText($payment, $nextBlock));

            return 'sent';
        }

        $curatorsChatId = (string) (config('services.telegram.curators_chat_id') ?? '');
        if ($curatorsChatId === '') {
            // Некуда поставить задачу — не сжигаем дедуп, куратор увидит в след. прогоне.
            Log::warning('B2bPayerReminder: curators_chat_id not configured, reminder deferred', [
                'payment_id' => $payment->id,
                'next_block' => $nextBlock,
            ]);

            return 'skipped';
        }

        $this->markReminder($payment, $nextBlock);
        SendTelegramChatMessageJob::dispatch($curatorsChatId, $this->curatorText($payment, $payer, $nextBlock));

        return 'curator';
    }

    /** Текст плательщику: оба пути оплаты, счёт юрлицу или нал. */
    private function payerText(Payment $payment, int $nextBlock): string
    {
        return sprintf(
            'Блок %d курса «%s» завершён — группа переходит к блоку %d. Пожалуйста, оплатите следующий блок: можем выставить счёт на юрлицо или принять оплату наличными. Если оплата уже сделана — просто ответьте на это сообщение, мы сверим.',
            $nextBlock - 1,
            $this->courseName($payment),
            $nextBlock,
        );
    }

    /** Задача кураторам: плательщик без Telegram, связаться и напомнить. */
    private function curatorText(Payment $payment, User $payer, int $nextBlock): string
    {
        $paymentLink = '';
        try {
            $paymentLink = PaymentResource::getUrl('edit', ['record' => $payment->id]);
        } catch (\Throwable) {
            $paymentLink = (string) $payment->id;
        }

        return sprintf(
            "📋 <b>B2B: плательщик без Telegram — задача связаться.</b>\n%s (кабинет #%d): блок %d курса «%s» завершён, блок %d не оплачен.\nНапомнить об оплате (счёт юрлицу или нал). Платёж: %s",
            e($payer->name),
            $payer->id,
            $nextBlock - 1,
            e($this->courseName($payment)),
            $nextBlock,
            $paymentLink,
        );
    }

    private function courseName(Payment $payment): string
    {
        $name = $payment->course?->name;

        return is_string($name) && $name !== '' ? $name : (string) $payment->course_id;
    }

    /** @return list<int> заведённые номера блоков расписания группы платежа */
    private function groupBlockNumbers(Payment $payment): array
    {
        return Lesson::query()
            ->where('course_id', $payment->course_id)
            ->where('group_id', $payment->group_id)
            ->whereNotNull('block_number')
            ->distinct()
            ->orderBy('block_number')
            ->pluck('block_number')
            ->map(fn (mixed $n) => (int) $n)
            ->all();
    }

    private function markReminder(Payment $payment, int $nextBlock): void
    {
        $meta = is_array($payment->claim_meta) ? $payment->claim_meta : [];
        $meta[self::dedupKey($nextBlock)] = now()->toIso8601String();
        $payment->update(['claim_meta' => $meta]);
    }
}
