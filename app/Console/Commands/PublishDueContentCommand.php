<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\CalendarPublishService;
use Illuminate\Console\Command;

/**
 * Hourly auto-pilot tick (H1568, content calendar W5): posts every due
 * `scheduled` ContentCalendarSlot to VK via the n8n webhook. Prod-inert
 * while features.content_calendar_autopilot is OFF (default) — early
 * return, no HTTP, same flag-gate shape as PublishSocialPostJob (E1).
 */
class PublishDueContentCommand extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Post every due scheduled content-calendar slot to VK via n8n';

    public function handle(CalendarPublishService $service): int
    {
        if (! config('features.content_calendar_autopilot')) {
            $this->warn('content_calendar_autopilot flag is OFF — no-op.');

            return self::SUCCESS;
        }

        $result = $service->publishDue();

        if ($result['skipped_no_webhook']) {
            $this->warn('N8N_CALENDAR_POST_WEBHOOK не настроен — отправка пропущена.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'publish-due: published=%d failed=%d',
            $result['published'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
