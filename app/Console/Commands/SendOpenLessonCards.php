<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendOpenLessonCardJob;
use App\Models\BotSubscriber;
use App\Models\OpenLessonCardSend;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\OpenLessonCards\OpenLessonOccasions;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Карточки открытых занятий в @samskrte_bot по стадиям:
 *  announce — за 2..announce_days дня всем подписчикам;
 *  morning  — в день занятия (09:00) или накануне вечером (19:00) для ранних;
 *  zoom     — за zoom_lead_minutes до начала ссылка нажавшим «✅ Я иду».
 * Только занятия с галочкой bot_card_enabled. Дедуп — журнал
 * open_lesson_card_sends, поэтому повторный прогон безопасен.
 */
class SendOpenLessonCards extends Command
{
    protected $signature = 'open-lessons:cards
        {--stage= : announce|morning|zoom}
        {--dry-run : только показать занятия и число получателей}
        {--only-chat= : отправить одному chat_id (проверка)}';

    protected $description = 'Карточки открытых занятий в @samskrte_bot: анонс, утро, ссылка Zoom';

    /** Пауза между сообщениями в очереди: ~25 в секунду, ниже лимита Telegram 30/с. */
    private const SPACING_MS = 40;

    public function handle(OpenLessonOccasions $occasions): int
    {
        $stage = (string) $this->option('stage');
        if (! in_array($stage, OpenLessonCardSend::STAGES, true)) {
            $this->error('--stage: announce, morning или zoom');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! config('features.open_lesson_cards', false)) {
            $this->info('features.open_lesson_cards выключен (OPEN_LESSON_CARDS_ENABLED) — ничего не шлём.');

            return self::SUCCESS;
        }

        $lessons = $this->lessonsFor($stage, $occasions);
        $queued = 0;

        foreach ($lessons as $schedule) {
            if ($stage === OpenLessonCardSend::STAGE_ZOOM && $occasions->joinUrl($schedule) === null) {
                $this->alertNoLink($schedule);
                $this->warn("#{$schedule->id}: нет ссылки на подключение — ссылки не отправлены");

                continue;
            }

            $recipients = $this->recipientsFor($stage, $schedule);
            $this->line(sprintf('#%d %s «%s» — получателей: %d', $schedule->id, $schedule->start->format('d.m H:i'), $schedule->title, $recipients->count()));

            if ($dryRun) {
                continue;
            }

            foreach ($recipients as $chatId) {
                SendOpenLessonCardJob::dispatch((int) $schedule->id, (string) $chatId, $stage)
                    ->delay(now()->addMilliseconds($queued * self::SPACING_MS));
                $queued++;
            }
        }

        $this->info(($dryRun ? 'Сухой прогон. ' : '')."Занятий: {$lessons->count()}, в очередь: {$queued}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Schedule> */
    private function lessonsFor(string $stage, OpenLessonOccasions $occasions): Collection
    {
        $now = now();
        $earlyHour = (int) config('open_lesson_cards.early_start_hour', 12);

        return match ($stage) {
            OpenLessonCardSend::STAGE_ANNOUNCE => $occasions->between(
                $now->copy()->addDays(min(2, (int) config('open_lesson_cards.announce_days', 3)))->startOfDay(),
                $now->copy()->addDays((int) config('open_lesson_cards.announce_days', 3))->endOfDay(),
            ),
            // Утренний прогон (до 15:00) — сегодняшние с началом от early_start_hour;
            // вечерний — завтрашние ранние, чтобы карточка не пришла за час до старта.
            OpenLessonCardSend::STAGE_MORNING => $now->hour < 15
                ? $occasions->between($now, $now->copy()->endOfDay())
                    ->filter(fn (Schedule $s): bool => $s->start->hour >= $earlyHour)->values()
                : $occasions->between($now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay())
                    ->filter(fn (Schedule $s): bool => $s->start->hour < $earlyHour)->values(),
            OpenLessonCardSend::STAGE_ZOOM => $occasions->between(
                $now,
                $now->copy()->addMinutes((int) config('open_lesson_cards.zoom_lead_minutes', 30)),
            ),
        };
    }

    /** @return Collection<int, string> */
    private function recipientsFor(string $stage, Schedule $schedule): Collection
    {
        if ($stage === OpenLessonCardSend::STAGE_ZOOM) {
            $chatIds = OpenLessonRsvp::query()
                ->where('schedule_id', $schedule->id)
                ->whereNotNull('going_at')
                ->whereNull('zoom_sent_at')
                ->pluck('chat_id');
        } else {
            $chatIds = BotSubscriber::query()->active()->orderBy('id')->pluck('chat_id');

            if ($stage === OpenLessonCardSend::STAGE_MORNING) {
                $declined = OpenLessonRsvp::query()
                    ->where('schedule_id', $schedule->id)
                    ->whereNotNull('declined_at')
                    ->pluck('chat_id');
                $chatIds = $chatIds->diff($declined);
            }
        }

        $only = (string) $this->option('only-chat');
        $testChats = (array) config('open_lesson_cards.test_chat_ids', []);

        if ($only !== '') {
            // Проверка на одном чате: для zoom — только если он нажал «Я иду».
            return $stage === OpenLessonCardSend::STAGE_ZOOM ? $chatIds->intersect([$only])->values() : collect([$only]);
        }
        if ($testChats !== []) {
            return $stage === OpenLessonCardSend::STAGE_ZOOM
                ? $chatIds->intersect($testChats)->values()
                : collect($testChats)->map(fn ($id): string => (string) $id)->values();
        }

        return $chatIds->map(fn ($id): string => (string) $id)->unique()->values();
    }

    private function alertNoLink(Schedule $schedule): void
    {
        if (! Cache::add('olc:no-link-alert:'.$schedule->id, true, now()->addHours(3))) {
            return;
        }

        app(TelegramAdminNotifier::class)->notifyAdmins(
            "⚠️ Открытое занятие #{$schedule->id} «{$schedule->title}» ({$schedule->start->format('d.m H:i')}): "
            .'нет ссылки Zoom — нажавшим «Я иду» в @samskrte_bot ссылка не ушла. Добавьте ссылку в расписание.'
        );
    }
}
