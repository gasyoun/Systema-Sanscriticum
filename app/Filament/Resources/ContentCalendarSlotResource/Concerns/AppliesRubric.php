<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Concerns;

use App\Models\ContentCalendarRubric;
use App\Services\Content\RubricQueue;
use App\Services\Content\RubricScheduler;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Пост рубрики (28-10-2026) при сохранении: префикс рубрики в первую строку,
 * пустое время → ближайшее свободное время рубрики, нет картинки для сторис →
 * собирается из картинки поста (если у рубрики включены сторис).
 */
trait AppliesRubric
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyRubric(array $data, ?int $slotId = null): array
    {
        $rubric = filled($data['rubric_id'] ?? null) ? ContentCalendarRubric::find($data['rubric_id']) : null;
        if ($rubric === null) {
            return $data;
        }

        $data['body'] = $rubric->applyPrefix($data['body'] ?? null);

        if (blank($data['publish_at'] ?? null)) {
            $at = app(RubricScheduler::class)->nextFreeAt($rubric, $slotId);
            if ($at === null) {
                throw ValidationException::withMessages([
                    'data.publish_at' => "У рубрики «{$rubric->name}» нет расписания — укажите время публикации.",
                ]);
            }
            $data['publish_at'] = $at->toDateTimeString();
            $data['slot_date'] = $at->toDateString();
        }

        $media = is_array($data['media_path'] ?? null) ? reset($data['media_path']) : ($data['media_path'] ?? null);
        if (blank($data['story_media_path'] ?? null)) {
            $story = app(RubricQueue::class)->storyFor($rubric, $media);
            if ($story['path'] !== null) {
                $data['story_media_path'] = $story['path'];
            } elseif ($story['note'] !== null) {
                Notification::make()->title('Сторис: '.$story['note'])->warning()->send();
            }
        }

        return $data;
    }
}
