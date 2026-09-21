<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Console\Commands\MirrorRecordingLessons;
use App\Enums\RecordingKind;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\HomeworkAutoOpener;
use App\Services\Membership\RecordingAccessPolicy;
use App\Services\Srs\LessonFlashCardsSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Копия урока в другой курс — кнопка «Копировать в курс…» в /admin/lessons.
 *
 * Зачем. Эфир клуба, записанный в курсе вебинара, получал метку `club_efir`
 * прямо там (20-09-2026, «Грамматика санскрита»). Так подписчик урок не
 * находит — кабинет показывает курсы из групп и `club_included`, — а при
 * enforce покупатели вебинара без клуба теряют запись
 * ({@see RecordingAccessPolicy::decide()}). Правильно —
 * отдельный урок в курсе «Клуб» с теми же ссылками, а оригинал не трогать.
 *
 * Переносится то же, что делает урок записью у {@see MirrorRecordingLessons::CARRIED},
 * плюс содержимое урока (вложения, карточки, текст задания). Не переносятся:
 * стенограмма (иначе LessonObserver заказал бы для копии клипы и черновики),
 * пробный урок (он один на курс), «на главной», номер занятия Кочергиной и
 * включённое ДЗ — это вход в автооткрытие приёма работ, у курса-приёмника
 * может не быть проверяющего.
 *
 * Сохраняем БЕЗ событий модели: хук `saved` у Lesson открыл бы ДЗ копии
 * в generic-пилоте и написал бы об этом в чат группы. Всё, что делают
 * хуки и нужно копии (порядок, метка записи, SRS-колода), делается здесь явно.
 */
final class LessonCopier
{
    /** «Класс записи как у оригинала». */
    public const KEEP = 'keep';

    private const CONTENT = [
        'attachments', 'flash_cards', 'homework_prompt', 'homework_attachments',
        'material_tag', 'material_tag_source',
    ];

    private const NOT_CARRIED = ['sort_order', 'is_preview', 'recording_attached_at'];

    /**
     * @param  iterable<Lesson>  $lessons
     * @return Collection<int, Lesson>
     *
     * @throws LessonCopyRefused
     */
    public function copy(
        iterable $lessons,
        Course $target,
        ?int $groupId = null,
        string $recordingKind = self::KEEP,
        ?int $blockNumber = null,
    ): Collection {
        $sources = collect($lessons)
            ->sortBy([['course_id', 'asc'], ['block_number', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        if ($sources->isEmpty()) {
            throw new LessonCopyRefused('Не выбрано ни одного урока.');
        }

        if ($sources->contains(fn (Lesson $l) => (int) $l->course_id === (int) $target->id)) {
            throw new LessonCopyRefused("Урок уже в курсе #{$target->id} — выберите другой курс-приёмник.");
        }

        if ($recordingKind !== self::KEEP && RecordingKind::tryFrom($recordingKind) === null) {
            throw new LessonCopyRefused('Неизвестный класс записи.');
        }

        if ($groupId !== null && ! $target->groups()->whereKey($groupId)->exists()) {
            throw new LessonCopyRefused("Группа #{$groupId} не относится к курсу #{$target->id}.");
        }

        // Как у catalog:mirror-recording-lessons: урок с block_N в курсе, где
        // такого блока нет, получает ключ, которого нет ни в блоках, ни в
        // тарифах, — купившие его не увидят. Курс без блоков (клуб) не проверяем.
        $targetBlocks = $target->blocks()->pluck('number')->map(fn ($n) => (int) $n)->all();
        if ($targetBlocks !== []) {
            $needed = $blockNumber !== null
                ? [$blockNumber]
                : $sources->map(fn (Lesson $l) => (int) ($l->block_number ?: 1))->unique()->all();
            $missing = array_values(array_diff($needed, $targetBlocks));

            if ($missing !== []) {
                throw new LessonCopyRefused(sprintf(
                    'У курса #%d нет блоков %s — копия осталась бы недоступной купившим. Выберите блок приёмника или заведите блоки.',
                    $target->id,
                    implode(', ', $missing),
                ));
            }
        }

        $fields = array_values(array_diff(
            array_merge(MirrorRecordingLessons::CARRIED, self::CONTENT),
            self::NOT_CARRIED,
        ));

        $copies = DB::transaction(function () use ($sources, $target, $groupId, $recordingKind, $blockNumber, $fields) {
            // Хук `creating` ставит урок в конец СВОЕЙ группы — повторяем это
            // здесь, потому что сохраняем без событий.
            $sortOrder = (int) Lesson::query()
                ->where('course_id', $target->id)
                ->when($groupId === null, fn ($q) => $q->whereNull('group_id'), fn ($q) => $q->where('group_id', $groupId))
                ->max('sort_order');

            return $sources->map(function (Lesson $source) use ($target, $groupId, $recordingKind, $blockNumber, $fields, &$sortOrder): Lesson {
                $copy = new Lesson;
                foreach ($fields as $field) {
                    $copy->setAttribute($field, $source->getAttribute($field));
                }

                $copy->forceFill([
                    'course_id' => $target->id,
                    'group_id' => $groupId,
                    'block_number' => $blockNumber ?? ($source->block_number ?: 1),
                    'sort_order' => ++$sortOrder,
                    'slug' => MirrorRecordingLessons::slugFor($source, $target),
                    'is_preview' => false,
                    'show_on_main' => false,
                    'homework_enabled' => false,
                    'recording_kind' => $recordingKind === self::KEEP ? $source->recording_kind : $recordingKind,
                ]);

                // Инвариант хука `saving`: запись есть ⇒ есть точка отсчёта.
                // Ставим её при создании — тогда и последующие правки копии не
                // примут урок за «только что получивший запись».
                if ($copy->hasVideo()) {
                    $attachedAt = $source->recording_attached_at ?? now();
                    $copy->forceFill([
                        'recording_attached_at' => $attachedAt,
                        'homework_opens_at' => HomeworkAutoOpener::opensAtFor($attachedAt),
                    ]);
                }

                $copy->saveQuietly();

                return $copy;
            });
        });

        foreach ($copies as $copy) {
            if (filled($copy->flash_cards)) {
                try {
                    LessonFlashCardsSync::sync($copy);
                } catch (\Throwable $e) {
                    Log::warning('LessonCopier — flash_cards SRS sync failed', [
                        'lesson_id' => $copy->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $copies;
    }
}
