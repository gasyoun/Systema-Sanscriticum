<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MoneySli\MoneySliAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Money channel for the sentinel_breaker ALARM_CMD (H4930, E002 layer 1).
 *
 * SentinelBreakerGate wraps EVERY unattended access/grant/refund mutator;
 * its ALARM_CMD points here on freeze AND on auto-unfreeze (MG ruling 5:
 * scream via Better Stack page + TG, the money channel — never the generic
 * cabinet-probe chat guards:breaker-alarm uses for MTProto-sync freezes,
 * because a money freeze is a different severity class with a different
 * on-call reader). Reuses {@see MoneySliAlerter} — one TG+heartbeat
 * implementation for the whole money axis, no second copy.
 *
 * Exit code is always 0 — a failed scream must not make the guardian that
 * called it look like it failed its own check.
 */
class MoneyBreakerAlarm extends Command
{
    protected $signature = 'guards:money-breaker-alarm
        {label : Guardian label, as sentinel_breaker.py named it}
        {body : Alarm text (freeze or auto-unfreeze)}';

    protected $description = 'Money-channel delivery for a sentinel_breaker freeze/unfreeze on an unattended money mutator (H4930).';

    public function handle(MoneySliAlerter $alerter): int
    {
        $label = (string) $this->argument('label');
        $body = (string) $this->argument('body');

        Log::critical('money sentinel breaker alarm', ['label' => $label, 'body' => $body]);

        $alerter->heartbeat(
            (string) config('money_sli.breaker_ping_url', ''),
            false,
            "Money mutator breaker: {$label} — {$body}",
            false,
        );

        $sent = $alerter->alert(
            'money_mutation_breaker',
            "Предохранитель денежного мутатора «{$label}»",
            [$body, 'H4930 money-axis layer 1 breaker — проверить N+1 попыток за окно, причина в логах.'],
            true,
            false,
        );

        $this->line('telegram_sent='.($sent ? '1' : '0'));

        return self::SUCCESS;
    }
}
