<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\Content\FaqDraftGenerator;
use Illuminate\Console\Command;

/**
 * Compose faq_draft ContentCandidate rows from one lesson (or all published
 * lessons with a transcript). Draft-only — staff Accept in Filament writes
 * the knowledge file via KnowledgeFaqPublisher (H1549 Wave 3).
 */
class ComposeLectureFaqDrafts extends Command
{
    protected $signature = 'content:compose-faq-drafts
                            {lesson_id? : ID урока; без аргумента — все опубликованные с транскриптом}
                            {--limit=50 : Макс. уроков в batch-режиме}';

    protected $description = 'Собрать черновики FAQ (faq_draft) из стенограммы лекции — без SupportTopic.';

    public function handle(FaqDraftGenerator $generator): int
    {
        $lessonId = $this->argument('lesson_id');

        if ($lessonId !== null) {
            $lesson = Lesson::query()->find($lessonId);
            if ($lesson === null) {
                $this->error("Урок #{$lessonId} не найден.");

                return self::FAILURE;
            }

            return $this->runForLesson($generator, $lesson);
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
            $n = count($generator->draftForLesson($lesson));
            $total += $n;
            $this->line("Урок #{$lesson->id}: {$n} черновик(ов).");
        }
        $this->info("Всего черновиков (создано/обновлено): {$total}.");

        return self::SUCCESS;
    }

    private function runForLesson(FaqDraftGenerator $generator, Lesson $lesson): int
    {
        $drafts = $generator->draftForLesson($lesson);
        if ($drafts === []) {
            $this->warn("Урок #{$lesson->id}: FAQ-пар не найдено (пустой транскрипт или нет вопросительных/спанов).");

            return self::SUCCESS;
        }

        $this->info("Урок #{$lesson->id}: ".count($drafts).' faq_draft — примите в Filament «Контент-кандидаты», чтобы записать в knowledge.');

        return self::SUCCESS;
    }
}
