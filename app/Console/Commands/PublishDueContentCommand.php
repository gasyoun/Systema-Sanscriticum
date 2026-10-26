<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\CalendarPublishService;
use Illuminate\Console\Command;

/**
 * Hourly auto-pilot tick (H1568, content calendar W5): crossposts every due
 * `scheduled` ContentCalendarSlot to its TG channels and VK communities
 * (PublishCalendarSlotJob, с 22-10-2026; раньше — одна VK-стена через n8n). Prod-inert
 * while features.content_calendar_autopilot is OFF (default) — early
 * return, no HTTP, same flag-gate shape as PublishSocialPostJob (E1).
 */
class PublishDueContentCommand extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Crosspost every due scheduled content-calendar slot to its TG channels and VK communities';

    public function handle(CalendarPublishService $service): int
    {
        if (! config('features.content_calendar_autopilot')) {
            $this->warn('content_calendar_autopilot flag is OFF — no-op.');

            return self::SUCCESS;
        }

        $result = $service->publishDue();

        if ($result['skipped_no_destinations']) {
            $this->warn('Площадки CONTENT_CAL_* не заданы — публиковать некуда, слоты не тронуты.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'publish-due: dispatched=%d held=%d',
            $result['dispatched'],
            $result['held'],
        ));

        return self::SUCCESS;
    }
}
