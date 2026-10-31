<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Черновик рубрики → очередь (31-10-2026). Импортированные посты (например,
 * copy_packet) лежат в рубрике черновиками без времени и автопилотом не
 * публикуются. enqueue() делает с ними то же, что форма при сохранении
 * поста рубрики: ближайший свободный выход, префикс, сторис из обложки, —
 * и переводит в scheduled.
 */
class RubricQueue
{
    /** Статусы, из которых пост можно поставить в очередь. */
    public const ENQUEUEABLE = [
        ContentCalendarSlot::STATUS_DRAFT,
        ContentCalendarSlot::STATUS_EMPTY,
        ContentCalendarSlot::STATUS_KEPT,
    ];

    public function __construct(
        private readonly RubricScheduler $scheduler,
        private readonly StoryImageComposer $composer,
    ) {}

    public static function canEnqueue(ContentCalendarSlot $slot): bool
    {
        return $slot->rubric_id !== null && in_array($slot->status, self::ENQUEUEABLE, true);
    }

    /**
     * @return array{ok: bool, at: ?Carbon, note: ?string}
     */
    public function enqueue(ContentCalendarSlot $slot): array
    {
        $rubric = $slot->rubric;
        if ($rubric === null || ! self::canEnqueue($slot)) {
            return ['ok' => false, 'at' => null, 'note' => "слот #{$slot->id}: не черновик рубрики"];
        }

        $at = $this->scheduler->nextFreeAt($rubric, $slot->id);
        if ($at === null) {
            return ['ok' => false, 'at' => null, 'note' => "рубрика «{$rubric->name}»: нет расписания"];
        }

        $story = blank($slot->story_media_path) ? $this->storyFor($rubric, $slot->media_path) : ['path' => null, 'note' => null];

        $slot->forceFill([
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => $at,
            'slot_date' => $at->toDateString(),
            'body' => $rubric->applyPrefix($slot->body),
            'story_media_path' => $story['path'] ?? $slot->story_media_path,
        ])->save();

        return ['ok' => true, 'at' => $at, 'note' => $story['note'] === null ? null : "слот #{$slot->id}: {$story['note']}"];
    }

    /**
     * Черновики рубрики по порядку (дата слота, id) — в очередь.
     *
     * @return array{done: int, notes: list<string>}
     */
    public function enqueueDrafts(ContentCalendarRubric $rubric): array
    {
        $done = 0;
        $notes = [];
        $drafts = $rubric->slots()->whereIn('status', self::ENQUEUEABLE)->orderBy('slot_date')->orderBy('id')->get();
        foreach ($drafts as $slot) {
            $slot->setRelation('rubric', $rubric);
            $result = $this->enqueue($slot);
            $done += $result['ok'] ? 1 : 0;
            if ($result['note'] !== null) {
                $notes[] = $result['note'];
            }
        }

        return ['done' => $done, 'notes' => array_values(array_unique($notes))];
    }

    /**
     * Картинка для сторис из картинки поста, если у рубрики включены сторис.
     *
     * @return array{path: ?string, note: ?string}
     */
    public function storyFor(ContentCalendarRubric $rubric, mixed $mediaPath): array
    {
        if (! $rubric->with_story || blank($mediaPath)) {
            return ['path' => null, 'note' => null];
        }

        if ((new ContentCalendarSlot(['media_path' => $mediaPath]))->mediaType() !== ContentCalendarSlot::MEDIA_PHOTO) {
            return ['path' => null, 'note' => 'сторис из видео не собирается — загрузите вертикальную картинку'];
        }

        try {
            return ['path' => $this->composer->compose((string) $mediaPath), 'note' => null];
        } catch (Throwable $e) {
            report($e);

            return ['path' => null, 'note' => 'картинку для сторис собрать не удалось — загрузите вручную'];
        }
    }
}
