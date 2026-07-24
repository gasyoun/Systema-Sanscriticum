<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\Content\ArticleDraftGenerator;
use Illuminate\Console\Command;

/**
 * Compose article ContentCandidate drafts from lecture transcripts (H1550,
 * Wave 4). Draft-only — staff review in Filament «Контент-кандидаты»; no
 * auto-publish path for type=article.
 */
class ComposeLectureArticleDrafts extends Command
{
    protected $signature = 'content:compose-article-drafts
                            {lesson_id? : ID урока; без аргумента — weekly pack или batch}
                            {--weekly : Собрать один weekly-pack за последние --days}
                            {--days=7 : Окно weekly-pack / batch}
                            {--limit=20 : Макс. уроков в batch-режиме (не --weekly)}';

    protected $description = 'Собрать черновики статей (type=article) из стенограмм лекций — без полной расшифровки.';

    public function handle(ArticleDraftGenerator $generator): int
    {
        if ($this->option('weekly')) {
            $days = max(1, (int) $this->option('days'));
            $article = $generator->composeWeeklyPack(days: $days);
            if ($article === null) {
                $this->info("За последние {$days} дн. нет опубликованных уроков с транскриптом — pack не создан.");

                return self::SUCCESS;
            }

            $this->info("Weekly pack #{$article->id} «{$article->title}» ({$article->channel_target}) — примите/правьте в Filament.");

            return self::SUCCESS;
        }

        $lessonId = $this->argument('lesson_id');
        if ($lessonId !== null) {
            $lesson = Lesson::query()->find($lessonId);
            if ($lesson === null) {
                $this->error("Урок #{$lessonId} не найден.");

                return self::FAILURE;
            }

            $article = $generator->draftForLesson($lesson);
            if ($article === null) {
                $this->warn("Урок #{$lesson->id}: статья не собрана (пустой/отсутствующий транскрипт).");

                return self::SUCCESS;
            }

            $this->info("Урок #{$lesson->id}: article #{$article->id} «{$article->title}».");

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
            $article = $generator->draftForLesson($lesson);
            if ($article !== null) {
                $total++;
                $this->line("Урок #{$lesson->id}: article #{$article->id}.");
            }
        }
        $this->info("Всего article-черновиков (создано/обновлено): {$total}.");

        return self::SUCCESS;
    }
}
