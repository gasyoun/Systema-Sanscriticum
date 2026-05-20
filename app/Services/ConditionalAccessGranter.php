<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTelegramMessageJob;
use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConditionalAccessGranter
{
    public const MODE_FULL = 'full';

    public const MODE_BLOCKS = 'blocks';

    /**
     * Открыть доступ под обещание. Создаёт conditional Payments, не делая
     * реальную оплату — но проходящие через стандартные access-проверки
     * (StudentController::getUserUnlockedTariffs ищет paid-Payment.tariff).
     *
     * Для mode='full' — один Payment с tariff='full'.
     * Для mode='blocks' — по одному Payment на каждый блок (иначе урок блока X
     * не пройдёт гейт, который требует точного совпадения 'block_X').
     *
     * Все Payments создаются через withoutEvents — иначе на каждый Payment
     * улетит отдельное TG-сообщение «доступ открыт». Мы шлём одно общее.
     *
     * @param  list<int>  $blockNumbers  игнорируется для mode='full'
     * @return list<int> id созданных Payments
     */
    public function grantForPromise(
        PaymentPromise $promise,
        string $mode,
        array $blockNumbers = [],
    ): array {
        $this->validate($mode, $blockNumbers);

        if ($this->hasActiveGrant($promise)) {
            throw ValidationException::withMessages([
                'access' => 'Доступ под это обещание уже открыт. Сначала отзовите его.',
            ]);
        }

        $payments = [];

        DB::transaction(function () use ($promise, $mode, $blockNumbers, &$payments): void {
            if ($mode === self::MODE_FULL) {
                $payments[] = $this->createConditionalPayment($promise, 'full', null, null);
            } else {
                foreach ($blockNumbers as $n) {
                    $payments[] = $this->createConditionalPayment(
                        $promise,
                        'block_'.$n,
                        $n,
                        $n,
                    );
                }
            }

            // grantAccess руками: добавит юзера в группы курса, чтобы он увидел
            // курс в дашборде. Идемпотентно — syncWithoutDetaching.
            $payments[0]->grantAccess();
        });

        $this->notifyOpened($promise, $mode, $blockNumbers);

        return array_map(fn (Payment $p) => $p->id, $payments);
    }

    /**
     * Отозвать доступ: удалить все conditional Payments, связанные с обещанием.
     * Группы курса у юзера НЕ трогаем — иначе курс пропадёт из дашборда, что
     * слишком резко (студент может уже изучать оплаченные блоки). Доступ к
     * урокам закроется автоматически через getUserUnlockedTariffs.
     *
     * @return int сколько Payment-ов удалено
     */
    public function revokeForPromise(PaymentPromise $promise): int
    {
        $count = Payment::query()
            ->where('linked_promise_id', $promise->id)
            ->where('is_conditional', true)
            ->delete();

        if ($count > 0) {
            $this->notifyRevoked($promise);
        }

        return $count;
    }

    public function hasActiveGrant(PaymentPromise $promise): bool
    {
        return Payment::query()
            ->where('linked_promise_id', $promise->id)
            ->where('is_conditional', true)
            ->exists();
    }

    /**
     * @param  list<int>  $blockNumbers
     */
    private function validate(string $mode, array $blockNumbers): void
    {
        if (! in_array($mode, [self::MODE_FULL, self::MODE_BLOCKS], true)) {
            throw ValidationException::withMessages(['mode' => 'Неизвестный режим открытия доступа.']);
        }

        if ($mode === self::MODE_BLOCKS) {
            if (empty($blockNumbers)) {
                throw ValidationException::withMessages([
                    'block_numbers' => 'Укажите хотя бы один блок для открытия.',
                ]);
            }
            foreach ($blockNumbers as $n) {
                if (! is_int($n) || $n < 1) {
                    throw ValidationException::withMessages([
                        'block_numbers' => 'Номера блоков должны быть положительными целыми числами.',
                    ]);
                }
            }
        }
    }

    private function createConditionalPayment(
        PaymentPromise $promise,
        string $tariff,
        ?int $startBlock,
        ?int $endBlock,
    ): Payment {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $promise->user_id,
            'course_id' => $promise->course_id,
            'amount' => 0,
            'tariff' => $tariff,
            'status' => 'paid',
            'start_block' => $startBlock,
            'end_block' => $endBlock,
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'is_conditional' => true,
            'linked_promise_id' => $promise->id,
        ]));
    }

    /**
     * @param  list<int>  $blockNumbers
     */
    private function notifyOpened(PaymentPromise $promise, string $mode, array $blockNumbers): void
    {
        if (! $promise->user_id) {
            return;
        }

        $courseName = $promise->course->title ?? 'курс';
        $scope = $mode === self::MODE_FULL ? 'полный курс' : $this->humanizeBlocks($blockNumbers);
        $deadline = $promise->promised_at?->format('d.m.Y');
        $url = url('/login');

        $text = "📅 <b>Доступ открыт по договорённости</b>\n\n";
        $text .= "Намасте! Открыт {$scope} курса <b>«{$courseName}»</b> ";
        $text .= $deadline ? "до <b>{$deadline}</b>." : 'до согласованного срока.';
        $text .= "\n\n<a href='{$url}'>Перейти в личный кабинет</a>";

        SendTelegramMessageJob::dispatch($promise->user_id, $text);
    }

    private function notifyRevoked(PaymentPromise $promise): void
    {
        if (! $promise->user_id) {
            return;
        }

        $courseName = $promise->course->title ?? 'курс';
        $url = url('/login');

        $text = "🔒 <b>Доступ временно закрыт</b>\n\n";
        $text .= "Доступ к курсу <b>«{$courseName}»</b> приостановлен. ";
        $text .= "После оплаты он откроется снова.\n\n";
        $text .= "<a href='{$url}'>Перейти в личный кабинет</a>";

        SendTelegramMessageJob::dispatch($promise->user_id, $text);
    }

    /**
     * @param  list<int>  $blockNumbers
     */
    private function humanizeBlocks(array $blockNumbers): string
    {
        sort($blockNumbers);
        if (count($blockNumbers) === 1) {
            return 'блок №'.$blockNumbers[0];
        }

        // Сжимаем в диапазоны: [1,2,3,5] → «блоки №1–3, №5»
        $ranges = [];
        $start = $end = $blockNumbers[0];
        for ($i = 1, $len = count($blockNumbers); $i < $len; $i++) {
            $n = $blockNumbers[$i];
            if ($n === $end + 1) {
                $end = $n;
            } else {
                $ranges[] = $start === $end ? "№{$start}" : "№{$start}–{$end}";
                $start = $end = $n;
            }
        }
        $ranges[] = $start === $end ? "№{$start}" : "№{$start}–{$end}";

        return 'блоки '.implode(', ', $ranges);
    }
}
