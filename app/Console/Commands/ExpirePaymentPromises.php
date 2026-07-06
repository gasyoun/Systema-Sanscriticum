<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentPromise;
use App\Models\PromiseEvent;
use App\Services\CuratorNotifier;
use Illuminate\Console\Command;

class ExpirePaymentPromises extends Command
{
    protected $signature = 'promises:expire';

    protected $description = 'Перевод просроченных обещаний оплатить в статус expired (запускать раз в день).';

    public function handle(CuratorNotifier $curator): int
    {
        // Сначала забираем сами обещания — раньше демон молча делал mass-update
        // active→expired, и никто об этом не узнавал (retention audit gap #4).
        // Теперь по каждому пишем PromiseEvent и шлём куратору утренний дайджест.
        $promises = PaymentPromise::query()
            ->with(['user', 'course'])
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', now()->toDateString())
            ->orderByDesc('amount')
            ->get();

        if ($promises->isEmpty()) {
            $this->info('Помечено как expired: 0');

            return self::SUCCESS;
        }

        // Mass-update без Eloquent-событий — намеренно (не триггерим booted-хуки).
        PaymentPromise::query()
            ->whereIn('id', $promises->pluck('id'))
            ->update(['status' => PaymentPromise::STATUS_EXPIRED]);

        foreach ($promises as $promise) {
            // Обновляем локальный статус, чтобы лог/дайджест были консистентны.
            $promise->status = PaymentPromise::STATUS_EXPIRED;
            PromiseEvent::log($promise, PromiseEvent::EVENT_EXPIRED);
        }

        // Утренний дайджест кураторам: сколько истекло и худшие по сумме.
        $curator->promisesExpiredDigest($promises);

        $this->info('Помечено как expired: '.$promises->count());

        return self::SUCCESS;
    }
}
