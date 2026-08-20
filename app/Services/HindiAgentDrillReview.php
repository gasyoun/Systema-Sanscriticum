<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * H3206 — one list of agent-derived Hindi drills for the teacher cabinet.
 *
 * Live from transcripts + attachments (YouTube nova-3 included). Students
 * never hit this class. No PII.
 */
final class HindiAgentDrillReview
{
    public function __construct(
        private readonly HindiTranscriptDrills $transcript,
        private readonly HindiAttachmentDrills $attachments,
        private readonly ProgrammeShellGraph $graph,
    ) {}

    /**
     * @return list<array{
     *     lesson_id: int,
     *     course_id: int,
     *     course: string,
     *     slug: string,
     *     lesson: string,
     *     source: string,
     *     items: list<array{id: string, type: string, prompt: string, answer: string, lemma: string}>
     * }>
     */
    public function lessons(): array
    {
        $out = [];
        foreach ($this->graph->orderedShells('hindi') as $course) {
            $lessons = Lesson::query()
                ->where('course_id', $course->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            foreach ($lessons as $lesson) {
                $tItems = $this->transcript->itemsFor($lesson, true);
                $aItems = $this->attachments->itemsFor($lesson);
                if ($tItems === [] && $aItems === []) {
                    continue;
                }
                $source = $this->transcript->transcriptSource($lesson);
                if ($source === '') {
                    $source = $tItems !== [] ? 'zoom-or-n8n' : 'attachment';
                }
                $out[] = [
                    'lesson_id' => (int) $lesson->id,
                    'course_id' => (int) $course->id,
                    'course' => (string) $course->title,
                    'slug' => (string) $course->slug,
                    'lesson' => (string) $lesson->title,
                    'source' => $source,
                    'items' => array_map(
                        static fn (array $i): array => [
                            'id' => (string) ($i['id'] ?? ''),
                            'type' => (string) ($i['type'] ?? ''),
                            'prompt' => (string) ($i['prompt'] ?? ''),
                            'answer' => (string) ($i['answer'] ?? ''),
                            'lemma' => (string) ($i['lemma'] ?? ''),
                        ],
                        array_merge($tItems, $aItems),
                    ),
                ];
            }
        }

        return $out;
    }
}
