<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Jobs\SendLeadMagnet;
use App\Models\Lead;

/**
 * Решает, выдать лид-магнит сразу или отложить до окна «за N минут до вебинара».
 *
 * - лендинг без запланированного вебинара → сразу (как раньше);
 * - окно выдачи уже открыто (поздний /start в пределах [старт−offset; старт+грейс]) → сразу;
 * - до окна → ничего не делаем: выдаст команда magnets:deliver-due, когда окно откроется;
 * - после закрытия окна (старт + грейс) → не выдаём.
 *
 * Двойной выдачи не будет: SendLeadMagnet идемпотентен по magnet_delivered_at.
 */
final class LeadMagnetDispatcher
{
    public static function deliverOrDefer(Lead $lead, string $channel): void
    {
        $landing = $lead->landingPage;

        // H3339: magnet_token бывает и у подписчиков статусов (без файла) —
        // в файловый конвейер идут только лендинги с настоящим магнитом.
        if (! $landing || ! $landing->hasLeadMagnet()) {
            return;
        }

        if (! $landing->hasScheduledWebinar() || $landing->isMagnetWindowOpen()) {
            SendLeadMagnet::dispatch($lead->id, $channel);
        }
        // иначе: до окна — выдаст планировщик; после старта — не выдаём.
    }
}
