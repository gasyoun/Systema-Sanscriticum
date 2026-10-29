<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Concerns;

use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use App\Services\Content\RubricScheduler;
use App\Services\Content\StoryImageComposer;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        if ($rubric->with_story && blank($data['story_media_path'] ?? null) && filled($media)) {
            $probe = new ContentCalendarSlot(['media_path' => $media]);
            if ($probe->mediaType() === ContentCalendarSlot::MEDIA_PHOTO) {
                try {
                    $data['story_media_path'] = app(StoryImageComposer::class)->compose((string) $media);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('Картинку для сторис собрать не удалось')
                        ->body('Загрузите её вручную в «Картинка для сторис».')->warning()->send();
                }
            } else {
                Notification::make()->title('Сторис из видео не собирается')
                    ->body('Загрузите вертикальную картинку в «Картинка для сторис», иначе пост уйдёт без сторис.')->warning()->send();
            }
        }

        return $data;
    }
}
