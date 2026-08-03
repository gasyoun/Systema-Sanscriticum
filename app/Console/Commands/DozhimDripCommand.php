<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DozhimDripLog;
use App\Models\MessageTemplate;
use App\Services\DozhimDripDispatcher;
use App\Services\WorkQueueReport;
use Illuminate\Console\Command;

/**
 * NOBORING dozhim Wave 1b (H2059, IMPLEMENTATION H-B п.5): линейный дрип по
 * недожатым open Deal через уже существующий Messaging (TG/VK/email) —
 * day0 (порог dozhim.unpaid_deal_hours) → day3 (+72ч) → day7 (+168ч).
 *
 * Один шаг за прогон на сделку (ORDER BY день ↑, первый ещё не отправленный
 * due-шаг — send, break): гарантирует линейный порядок, даже если команда
 * пропустила день. Идемпотентность — {@see DozhimDripLog} (deal_id, step)
 * уникален.
 *
 * Гейт — dozhim_drip, НЕЗАВИСИМО от dozhim_queue (тот флаг — только видимость
 * бакета оператору, см. WorkQueueReport::agedOpenDeals()). Пока OFF — no-op,
 * ни одного сообщения не уходит.
 */
class DozhimDripCommand extends Command
{
    protected $signature = 'dozhim:drip';

    protected $description = 'Линейный дрип по недожатым open Deal (day0/day3/day7) через существующий Messaging';

    /** @var array<string,int> шаг => смещение в часах поверх dozhim.unpaid_deal_hours */
    private const STEPS = [
        MessageTemplate::DOZHIM_STEP_DAY0 => 0,
        MessageTemplate::DOZHIM_STEP_DAY3 => 72,
        MessageTemplate::DOZHIM_STEP_DAY7 => 168,
    ];

    public function handle(WorkQueueReport $report, DozhimDripDispatcher $dispatcher): int
    {
        if (! config('features.dozhim_drip')) {
            $this->info('Дожим-дрип выключен (DOZHIM_DRIP) — пропуск.');

            return self::SUCCESS;
        }

        $baseHours = max(1, (int) config('dozhim.unpaid_deal_hours', 24));

        $sent = 0;
        $noTemplate = 0;
        $noChannel = 0;

        foreach ($report->agedOpenDeals() as $deal) {
            $ageHours = $deal->created_at->diffInHours(now());

            foreach (self::STEPS as $step => $offsetHours) {
                if ($ageHours < $baseHours + $offsetHours) {
                    break; // шаги идут по возрастанию — дальше только более поздние, тоже не наступили
                }

                $alreadySent = DozhimDripLog::query()
                    ->where('deal_id', $deal->id)
                    ->where('step', $step)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $template = MessageTemplate::query()
                    ->where('category', MessageTemplate::CATEGORY_DOZHIM)
                    ->where('dozhim_step', $step)
                    ->where('is_active', true)
                    ->first();

                if ($template === null) {
                    $noTemplate++;

                    break;
                }

                if ($dispatcher->send($deal, $template)) {
                    DozhimDripLog::create(['deal_id' => $deal->id, 'step' => $step, 'sent_at' => now()]);
                    $sent++;
                } else {
                    $noChannel++;
                }

                break; // один шаг за прогон на сделку — линейный порядок
            }
        }

        $this->info("Дожим-дрип: отправлено {$sent}, без активного шаблона {$noTemplate}, без канала связи {$noChannel}.");

        return self::SUCCESS;
    }
}
