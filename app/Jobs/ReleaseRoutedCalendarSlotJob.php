<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentCalendarSlot;
use App\Services\Content\CalendarIngestRouting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Пост из TG-канала, по которому в служебном чате за отведённое время никто
 * не нажал кнопку (CalendarIngestRouting), — разносится по отмеченным
 * площадкам (по умолчанию все). Уже решённый слот — no-op.
 */
class ReleaseRoutedCalendarSlotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $slotId)
    {
        $this->onQueue('messages');
    }

    public function handle(CalendarIngestRouting $routing): void
    {
        Cache::lock('content-calendar:routing:'.$this->slotId, 10)->block(5, function () use ($routing): void {
            $slot = ContentCalendarSlot::find($this->slotId);
            if ($slot !== null && CalendarIngestRouting::isPending($slot)) {
                $routing->decide($slot, 'auto');
            }
        });
    }
}
