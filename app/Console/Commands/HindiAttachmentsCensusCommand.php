<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiAttachmentTextExtractor;
use App\Services\ProgrammeShellGraph;
use Illuminate\Console\Command;

/**
 * H2444 — read-only census of Hindi lesson files and extractable drills.
 *
 * Works with the flag OFF. Does not write, fetch Telegram, or OCR.
 */
class HindiAttachmentsCensusCommand extends Command
{
    protected $signature = 'hindi:attachments-census {--json : Print JSON}';

    protected $description = 'Read-only census of Hindi lesson attachments and extractable drills';

    public function handle(
        ProgrammeShellGraph $graph,
        HindiAttachmentDrills $drills,
        HindiAttachmentTextExtractor $files,
    ): int {
        $ids = $graph->orderedShells('hindi')->pluck('id');
        $lessons = Lesson::query()
            ->whereIn('course_id', $ids)
            ->get(['id', 'course_id', 'title', 'attachments', 'homework_attachments']);

        $exts = [];
        $reasons = [];
        $withFiles = 0;
        $extractableLessons = 0;
        $itemLessons = 0;
        $fileCount = 0;

        foreach ($lessons as $lesson) {
            $listed = $files->listedPaths($lesson);
            if ($listed === []) {
                continue;
            }
            $withFiles++;
            $inspected = $files->filesFor($lesson);
            $hadExtractable = false;
            foreach ($inspected as $file) {
                $fileCount++;
                $ext = $file['ext'] === '' ? '(none)' : $file['ext'];
                $exts[$ext] = ($exts[$ext] ?? 0) + 1;
                if ($file['extractable']) {
                    $hadExtractable = true;
                } else {
                    $reason = (string) ($file['reason'] ?? 'unknown');
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
            if ($hadExtractable) {
                $extractableLessons++;
            }
            if ($drills->hasItems($lesson)) {
                $itemLessons++;
            }
        }

        ksort($exts);
        ksort($reasons);

        $report = [
            'hindi_shell_ids' => $ids->values()->all(),
            'lessons' => $lessons->count(),
            'lessons_with_files' => $withFiles,
            'file_count' => $fileCount,
            'lessons_extractable' => $extractableLessons,
            'lessons_with_items' => $itemLessons,
            'exts' => $exts,
            'skip_reasons' => $reasons,
            'flag' => $drills->enabled(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('lessons='.$report['lessons'].' with_files='.$withFiles.' extractable='.$extractableLessons.' items='.$itemLessons);

        return self::SUCCESS;
    }
}
