<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use Illuminate\Console\Command;

/**
 * Очередь уроков на распознавание: у урока есть запись, но нет стенограммы.
 *
 * Команда ТОЛЬКО ЧИТАЕТ. Её вывод забирает конвейер на станции
 * (scripts/transcribe_lessons.py): он качает запись по ссылке, гонит через
 * локальный whisper и возвращает стенограмму обратно через
 * POST /api/lessons/{lesson}/transcript.
 *
 * RuTube впереди YouTube: из России он открывается без VPN, а качать сотни
 * часов через туннель — лишний источник обрывов.
 */
class ListTranscriptQueue extends Command
{
    protected $signature = 'lessons:transcript-queue
        {--teacher= : Только курсы этого преподавателя (teachers.id)}
        {--course=* : Только эти курсы (courses.id), можно несколько}
        {--limit= : Не больше N уроков}
        {--format=json : json или table}';

    protected $description = 'Уроки с записью, но без стенограммы — очередь для распознавания.';

    public function handle(): int
    {
        $lessons = Lesson::query()
            ->whereRaw("COALESCE(transcript_file, '') = ''")
            ->where(function ($q) {
                $q->whereRaw("COALESCE(rutube_url, '') <> ''")
                    ->orWhereRaw("COALESCE(youtube_url, '') <> ''");
            })
            ->when($this->option('teacher'), fn ($q, $teacher) => $q->whereHas('course', fn ($c) => $c->where('teacher_id', (int) $teacher)))
            ->when($this->option('course'), fn ($q, $courses) => $q->whereIn('course_id', array_map('intval', (array) $courses)))
            ->with('course:id,title,teacher_id')
            ->orderBy('course_id')
            ->orderBy('lesson_date')
            ->orderBy('id')
            ->when($this->option('limit'), fn ($q, $limit) => $q->limit((int) $limit))
            ->get();

        $rows = $lessons->map(fn (Lesson $lesson): array => [
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'course' => $lesson->course?->title,
            'title' => $lesson->title,
            'date' => $lesson->lesson_date?->format('Y-m-d'),
            // Порядок предпочтения источника — он же порядок ключей.
            'rutube_url' => $lesson->rutube_url ?: null,
            'youtube_url' => $lesson->youtube_url ?: null,
        ])->values();

        if ($this->option('format') === 'table') {
            $this->table(
                ['Урок', 'Курс', 'Дата', 'Название', 'Источник'],
                $rows->map(fn (array $r): array => [
                    $r['lesson_id'],
                    mb_substr((string) $r['course'], 0, 34),
                    $r['date'],
                    mb_substr((string) $r['title'], 0, 40),
                    $r['rutube_url'] ? 'rutube' : 'youtube',
                ])->all(),
            );
            $this->info('Всего: '.$rows->count());

            return self::SUCCESS;
        }

        $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
