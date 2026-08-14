<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\HindiTranscriptDrills;
use Illuminate\Console\Command;

/**
 * H2443 — read-only dump of derived Hindi drill items for one lesson.
 *
 * Works with the flag OFF. Does not write payments or grants. Prompts only
 * (answers redacted) so a prod transcript can be sampled without leaking
 * a full key.
 */
class HindiDrillsProbeCommand extends Command
{
    protected $signature = 'hindi:drills-probe
        {lesson : Lesson id}
        {--json : Print JSON}';

    protected $description = 'Read-only Hindi transcript-drill items for one lesson';

    public function handle(HindiTranscriptDrills $drills): int
    {
        $lesson = Lesson::query()->with('course')->find((int) $this->argument('lesson'));
        if ($lesson === null) {
            $this->error('Lesson not found.');

            return self::FAILURE;
        }

        $items = $drills->itemsFor($lesson);
        $report = [
            'lesson_id' => (int) $lesson->id,
            'course_id' => (int) $lesson->course_id,
            'flag' => $drills->enabled(),
            'hindi_shell' => $drills->isHindiLesson($lesson),
            'transcript' => (string) ($lesson->transcript_file ?? ''),
            'item_count' => count($items),
            'types' => array_count_values(array_map(static fn (array $i): string => $i['type'], $items)),
            'items' => array_map(static fn (array $i): array => [
                'id' => $i['id'],
                'type' => $i['type'],
                'prompt' => $i['prompt'],
                'answer' => '***',
                'choices' => $i['choices'] === null ? null : count($i['choices']),
            ], $items),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('lesson_id='.$report['lesson_id'].' items='.$report['item_count'].' flag='.($report['flag'] ? 'on' : 'off'));
        foreach ($report['items'] as $row) {
            $this->line($row['type'].'  '.$row['prompt']);
        }

        return self::SUCCESS;
    }
}
