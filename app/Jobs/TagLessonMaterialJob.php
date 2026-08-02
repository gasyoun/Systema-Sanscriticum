<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\LessonMaterialTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Авторазметка материала урока после загрузки стенограммы
 * (LessonController::storeTranscript → этот job → LessonMaterialTagger).
 * Вынесено в очередь: разбор стенограммы не должен задерживать ответ n8n.
 */
class TagLessonMaterialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $lessonId) {}

    public function handle(LessonMaterialTagger $tagger): void
    {
        $lesson = Lesson::find($this->lessonId);
        if ($lesson) {
            $tagger->tagLesson($lesson);
        }
    }
}
