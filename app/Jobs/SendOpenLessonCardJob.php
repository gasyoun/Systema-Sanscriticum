<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OpenLessonCardSend;
use App\Models\Schedule;
use App\Services\OpenLessonCards\OpenLessonCardSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/** Одна стадия карточки открытого занятия одному чату (см. open-lessons:cards). */
final class SendOpenLessonCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $scheduleId,
        public readonly string $chatId,
        public readonly string $stage,
    ) {
        $this->onQueue('messages');
    }

    public function handle(OpenLessonCardSender $sender): void
    {
        if (! config('features.open_lesson_cards', false)) {
            return;
        }

        $schedule = Schedule::query()->where('bot_card_enabled', true)->find($this->scheduleId);
        if ($schedule === null) {
            return;
        }

        $outcome = $this->stage === OpenLessonCardSend::STAGE_ZOOM
            ? $sender->sendZoom($schedule, $this->chatId)
            : $sender->sendCard($schedule, $this->chatId, $this->stage);

        // Telegram отказал (429/5xx и т.п.) — клейм снят, бэкофф-ретрай безопасен.
        if ($outcome === OpenLessonCardSender::FAILED) {
            throw new RuntimeException("Open lesson card refused: schedule {$this->scheduleId}, stage {$this->stage}");
        }
    }
}
