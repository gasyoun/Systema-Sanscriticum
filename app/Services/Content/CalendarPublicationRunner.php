<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Одна площадка одного слота: строка content_calendar_publications, клейм
 * TelegramSendGuard и публикатор по платформе. Общий для автопилота
 * (PublishCalendarSlotJob) и кнопки «Опубликовать сторис» в админке —
 * повтор опубликованной площадки невозможен ни там, ни там.
 */
class CalendarPublicationRunner
{
    /** Клейм «в полёте»: параллельный прогон не шлёт тот же пост. */
    private const IN_FLIGHT_TTL = 900;

    /** После успеха клейм держится месяц (повторный диспатч — no-op). */
    private const DONE_TTL = 2592000;

    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly CalendarTelegramPublisher $telegram,
        private readonly CalendarVkPublisher $vk,
        private readonly CalendarBusinessStoryPublisher $story,
    ) {}

    /**
     * @param  array<string, mixed>  $destination
     * @return array{outcome: string, line: ?string} outcome: published|pending|failed|skipped;
     *                                               line — строка отчёта админам (успех с warning или окончательный отказ)
     */
    public function run(ContentCalendarSlot $slot, string $key, array $destination): array
    {
        $publication = ContentCalendarPublication::firstOrCreate([
            'content_calendar_slot_id' => $slot->id,
            'destination' => $key,
        ]);
        if ($publication->isDone()) {
            return ['outcome' => self::SKIPPED, 'line' => null];
        }

        $claim = "content-calendar:{$slot->id}:{$key}";
        if (! TelegramSendGuard::claimKey($claim, self::IN_FLIGHT_TTL)) {
            return ['outcome' => self::SKIPPED, 'line' => null]; // уже в полёте в параллельном прогоне
        }

        $publication->increment('attempts');

        try {
            $result = match ($destination['platform']) {
                'vk' => $this->vk->publish($slot, $destination),
                'vk_story' => $this->vk->publishStory($slot, $destination),
                'tg_business_story' => $this->story->publish($slot, $destination),
                default => $this->telegram->publish($slot, $destination),
            };
        } catch (Throwable $e) {
            $permanent = $e instanceof CalendarPublishException && $e->permanent;
            $failed = $permanent || $publication->attempts >= (int) config('content_calendar.max_attempts', 3);
            $publication->update([
                'status' => $failed ? ContentCalendarPublication::STATUS_FAILED : ContentCalendarPublication::STATUS_PENDING,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            Log::warning('CalendarPublicationRunner: площадка не опубликована', [
                'calendar_slot_id' => $slot->id,
                'destination' => $key,
                'attempt' => $publication->attempts,
                'failed' => $failed,
                'error' => $e->getMessage(),
            ]);

            return $failed
                ? ['outcome' => ContentCalendarPublication::STATUS_FAILED, 'line' => "❌ {$destination['label']}: {$e->getMessage()}"]
                : ['outcome' => ContentCalendarPublication::STATUS_PENDING, 'line' => null];
        }

        TelegramSendGuard::extendKey($claim, self::DONE_TTL);
        $publication->update([
            'status' => ContentCalendarPublication::STATUS_PUBLISHED,
            'external_id' => $result['external_id'],
            'permalink' => $result['permalink'],
            'warning' => $result['warning'],
            'error' => null,
            'published_at' => now(),
        ]);

        return [
            'outcome' => ContentCalendarPublication::STATUS_PUBLISHED,
            'line' => $result['warning'] !== null ? "⚠️ {$destination['label']}: {$result['warning']}" : null,
        ];
    }
}
