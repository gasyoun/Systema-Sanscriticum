<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;

/**
 * Информативные уведомления кураторам в общий Telegram-чат по всем
 * денежным/договорным событиям: оплаты, брони, доступ под обещание,
 * создание/закрытие/отмена обещаний и рассрочек.
 *
 * Получатель — config('services.telegram.curators_chat_id'). Если он не задан,
 * все методы — no-op (фича выключена). Отправляет основной бот
 * (config('services.telegram.bot_token')) через SendTelegramChatMessageJob.
 */
class CuratorNotifier
{
    public function paymentPaid(Payment $payment): void
    {
        $isPromise = str_starts_with((string) $payment->transaction_id, 'promise_#');
        $head = $isPromise ? '💰 <b>Оплата по обещанию</b>' : '💰 <b>Оплата получена</b>';

        $lines = [
            $head,
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            $this->tariffLine($payment),
        ];

        if (! empty($payment->transaction_id)) {
            $lines[] = 'Транзакция: <code>'.e($payment->transaction_id).'</code>';
        }
        $lines[] = $this->dateLine($payment->created_at);
        $lines[] = $this->adminLink($payment->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Новая заявка на оплату через PayPal (оплата из-за рубежа). Требует ручной
     * сверки в PayPal — доступ откроется после перевода платежа в paid в админке.
     */
    public function paypalClaimReceived(Payment $payment): void
    {
        $lines = [
            '🌍 <b>Заявка на оплату через PayPal</b> — нужна сверка',
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            $this->tariffLine($payment),
            'Заявлено: <b>'.($payment->foreignAmountLabel() ?: '—').'</b>',
            'Номинал: <b>'.$this->money((float) $payment->amount).'</b>',
        ];
        if (! empty($payment->payer_note)) {
            $lines[] = 'Примечание: '.e($payment->payer_note);
        }
        if (! empty($payment->proof_path)) {
            $lines[] = '📎 Приложен файл подтверждения';
        }
        $lines[] = $this->adminLink($payment->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function depositReceived(Payment $payment): void
    {
        $lines = [
            '📌 <b>Бронь курса (депозит)</b>',
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            $this->dateLine($payment->created_at),
            $this->adminLink($payment->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * @param  list<int>  $blocks
     */
    public function conditionalAccessGranted(PaymentPromise $promise, string $mode, array $blocks): void
    {
        $scope = $mode === ConditionalAccessGranter::MODE_FULL
            ? 'весь курс'
            : 'блоки: '.($blocks ? implode(', ', $blocks) : '—');

        $lines = [
            '📅 <b>Открыт доступ под обещание</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Открыто: <b>'.$scope.'</b>',
            'Оплатить до: <b>'.$this->date($promise->promised_at).'</b>',
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма обещания: <b>'.$this->money((float) $promise->amount).'</b>';
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseCreated(PaymentPromise $promise): void
    {
        $lines = [
            '🤝 <b>Новое обещание оплатить</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Оплатить до: <b>'.$this->date($promise->promised_at).'</b>',
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма: <b>'.$this->money((float) $promise->amount).'</b>';
        }
        if (! empty($promise->note)) {
            $lines[] = 'Комментарий: '.e($promise->note);
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * @param  iterable<PaymentPromise>  $promises
     */
    public function installmentCreated(User $user, Course $course, iterable $promises, ?string $note): void
    {
        $rows = [];
        $total = 0.0;
        foreach ($promises as $p) {
            $amount = (float) ($p->amount ?? 0);
            $total += $amount;
            $rows[] = '• '.$this->date($p->promised_at).' — <b>'.$this->money($amount).'</b>';
        }

        $lines = [
            '🧩 <b>Создана рассрочка</b>',
            '',
            $this->studentLine($user),
            $this->courseLine($course),
            'Платежей: <b>'.count($rows).'</b>',
            'График:',
            ...$rows,
            'Итого по графику: <b>'.$this->money($total).'</b>',
        ];
        if (! empty($note)) {
            $lines[] = 'Комментарий: '.e($note);
        }
        $lines[] = $this->adminLink($user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseFulfilled(PaymentPromise $promise, Payment $payment): void
    {
        $lines = [
            '✅ <b>Обещание закрыто оплатой</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            'Блоки: <b>'.$this->blocksLabel($payment->start_block, $payment->end_block).'</b>',
            $this->adminLink($promise->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseCancelled(PaymentPromise $promise): void
    {
        $lines = [
            '❌ <b>Обещание отменено</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Было обещано до: '.$this->date($promise->promised_at),
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма: '.$this->money((float) $promise->amount);
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function installmentCancelled(PaymentPromise $promise, int $count): void
    {
        $lines = [
            '❌ <b>Рассрочка отменена</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Отменено обещаний: <b>'.$count.'</b>',
            $this->adminLink($promise->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    // ==========================================================
    // Внутреннее
    // ==========================================================

    private function dispatchToCurators(string $text): void
    {
        $chatId = (string) (config('services.telegram.curators_chat_id') ?? '');
        if ($chatId === '') {
            return; // фича выключена — чат не настроен
        }

        SendTelegramChatMessageJob::dispatch($chatId, $text);
    }

    /** @param  list<string>  $lines */
    private function join(array $lines): string
    {
        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function studentLine(?User $user): string
    {
        if (! $user) {
            return 'Студент: —';
        }

        $contacts = array_filter([
            $user->phone ?? null,
            $user->email ?? null,
            $user->telegram_id ? 'tg:'.$user->telegram_id : null,
        ]);

        $line = 'Студент: <b>'.e((string) ($user->name ?? ('#'.$user->id))).'</b>';
        if ($contacts) {
            $line .= ' ('.e(implode(', ', $contacts)).')';
        }

        return $line;
    }

    private function courseLine(?Course $course): string
    {
        return 'Курс: <b>'.e((string) ($course->title ?? '—')).'</b>';
    }

    private function tariffLine(Payment $payment): string
    {
        $tariff = $payment->tariff ? e($payment->tariff) : '—';
        $blocks = $this->blocksLabel($payment->start_block, $payment->end_block);

        return 'Тариф: <b>'.$tariff.'</b>'.($blocks !== '—' ? ' · блоки: '.$blocks : '');
    }

    private function blocksLabel(?int $start, ?int $end): string
    {
        if ($start === null && $end === null) {
            return '—';
        }
        if ($start !== null && $end !== null) {
            return $start === $end ? (string) $start : $start.'–'.$end;
        }

        return (string) ($start ?? $end);
    }

    private function dateLine(mixed $date): string
    {
        return 'Дата: '.$this->date($date);
    }

    private function date(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('d.m.Y H:i');
        }

        return $date ? e((string) $date) : '—';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0, '.', ' ').' ₽';
    }

    private function adminLink(?User $user): string
    {
        if (! $user) {
            return '';
        }

        try {
            $url = \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $user->id]);
        } catch (\Throwable) {
            $url = url('/admin');
        }

        return "👉 <a href=\"{$url}\">Карточка студента</a>";
    }
}
