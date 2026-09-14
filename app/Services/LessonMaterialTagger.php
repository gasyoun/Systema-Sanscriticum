<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CertificateMilestone;
use App\Models\Lesson;
use App\Support\TranscriptParser;
use Illuminate\Support\Facades\Log;

/**
 * Авторазметка материала занятия по стенограмме (для material_count-вех):
 * считает упоминания ключевых слов вехи (CertificateMilestone.material_keywords)
 * в Deepgram-стенограмме урока и проставляет lessons.material_tag при
 * >= config('certificates.material_min_mentions') упоминаниях.
 *
 * Ручная разметка куратора (material_tag_source = manual) НЕ перезаписывается —
 * автоматика дополняет человека, не подменяет. При нескольких подходящих вехах
 * побеждает та, чьих ключевых слов в стенограмме больше.
 */
class LessonMaterialTagger
{
    /**
     * Разметить один урок. Возвращает присвоенный тег или null.
     * Идемпотентно: повторный прогон по тем же данным даёт тот же результат.
     */
    public function tagLesson(Lesson $lesson): ?string
    {
        if ($lesson->material_tag_source === 'manual') {
            return $lesson->material_tag;
        }

        if (empty($lesson->transcript_file) || ! $lesson->course_id) {
            return null;
        }

        $milestones = CertificateMilestone::query()
            ->where('course_id', $lesson->course_id)
            ->where('trigger_type', CertificateMilestone::TRIGGER_MATERIAL)
            ->where('is_active', true)
            ->whereNotNull('material_tag')
            ->get()
            ->filter(fn (CertificateMilestone $m) => $m->materialKeywords() !== []);

        if ($milestones->isEmpty()) {
            return null;
        }

        $threshold = max(1, (int) config('certificates.material_min_mentions', 3));

        $best = $milestones
            ->map(fn (CertificateMilestone $m) => [
                'tag' => $m->material_tag,
                'mentions' => $this->mentionCount($lesson, $m->materialKeywords()),
            ])
            ->filter(fn (array $r) => $r['mentions'] >= $threshold)
            ->sortByDesc('mentions')
            ->first();

        $newTag = $best['tag'] ?? null;

        if ($newTag !== $lesson->material_tag) {
            $lesson->forceFill([
                'material_tag' => $newTag,
                'material_tag_source' => $newTag === null ? null : 'auto',
            ])->save();

            Log::info('LessonMaterialTagger: урок размечен', [
                'lesson_id' => $lesson->id,
                'material_tag' => $newTag,
                'mentions' => $best['mentions'] ?? 0,
            ]);
        }

        return $newTag;
    }

    /** Число упоминаний ключевых слов в стенограмме урока. */
    public function mentionCount(Lesson $lesson, array $keywords): int
    {
        $sentences = TranscriptParser::sentencesFromStoredFile($lesson->transcript_file);
        if ($sentences === [] || $keywords === []) {
            return 0;
        }

        $text = mb_strtolower(implode(' ', array_column($sentences, 'text')));

        $count = 0;
        foreach ($keywords as $keyword) {
            $needle = mb_strtolower($keyword);
            if ($needle !== '') {
                $count += substr_count($text, $needle);
            }
        }

        return $count;
    }

    /**
     * Страховочный догон (ежедневная команда): уроки курсов с material-вехами,
     * у которых есть стенограмма, но тег ещё не проставлен автоматом.
     * Возвращает число размеченных уроков.
     */
    public function catchUp(): int
    {
        $courseIds = CertificateMilestone::query()
            ->where('trigger_type', CertificateMilestone::TRIGGER_MATERIAL)
            ->where('is_active', true)
            ->distinct()
            ->pluck('course_id');

        if ($courseIds->isEmpty()) {
            return 0;
        }

        $tagged = 0;
        Lesson::query()
            ->whereIn('course_id', $courseIds)
            ->whereRaw("COALESCE(transcript_file, '') <> ''")
            ->whereNull('material_tag')
            ->where(fn ($q) => $q->whereNull('material_tag_source')->orWhere('material_tag_source', '!=', 'manual'))
            ->orderBy('id')
            ->chunkById(100, function ($lessons) use (&$tagged): void {
                foreach ($lessons as $lesson) {
                    if ($this->tagLesson($lesson) !== null) {
                        $tagged++;
                    }
                }
            });

        return $tagged;
    }
}
