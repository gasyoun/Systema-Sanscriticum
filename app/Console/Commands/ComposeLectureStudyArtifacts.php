<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\Content\StudyArtifactGenerator;
use Illuminate\Console\Command;

/**
 * Compose study_artifact ContentCandidate drafts from lecture transcripts
 * (H1551, Wave 5). Staff review in Filament «Контент-кандидаты»; never on
 * pilot auto-publish (channel staff_study; jobs type-guard).
 */
class ComposeLectureStudyArtifacts extends Command
{
    protected $signature = 'content:compose-study-artifacts
                            {lesson_id? : ID урока; без аргумента — batch опубликованных с транскриптом}
                            {--limit=20 : Макс. уроков в batch-режиме}';

    protected $description = 'Собрать учебные черновики (type=study_artifact: summary/cards/homework) из стенограмм — staff-only, без auto-publish.';

    public function handle(StudyArtifactGenerator $generator): int
    {
        $lessonId = $this->argument('lesson_id');
        if ($lessonId !== null) {
            $lesson = Lesson::query()->find($lessonId);
            if ($lesson === null) {
                $this->error("Урок #{$lessonId} не найден.");

                return self::FAILURE;
            }

            $created = $generator->draftForLesson($lesson);
            if ($created === []) {
                $this->warn("Урок #{$lesson->id}: study artifacts не собраны (пустой/отсутствующий транскрипт).");

                return self::SUCCESS;
            }

            foreach ($created as $row) {
                $kind = $row->meta['kind'] ?? '?';
                $this->line("Урок #{$lesson->id}: study_artifact #{$row->id} [{$kind}] «{$row->title}».");
            }
            $this->info('Всего: '.count($created).' (summary + card_seeds + homework).');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $lessons = Lesson::query()
            ->where('is_published', true)
            ->whereRaw("COALESCE(transcript_file, '') <> ''")
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($lessons->isEmpty()) {
            $this->info('Нет опубликованных уроков с транскриптом.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($lessons as $lesson) {
            $created = $generator->draftForLesson($lesson);
            $total += count($created);
            if ($created !== []) {
                $this->line("Урок #{$lesson->id}: ".count($created).' study_artifact.');
            }
        }
        $this->info("Всего study_artifact строк (создано/обновлено): {$total}.");

        return self::SUCCESS;
    }
}
