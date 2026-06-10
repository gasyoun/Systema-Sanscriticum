<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lesson;

/**
 * Сводка покрытия уроков материалами для дашборда суперадмина.
 * «Есть материалы» = видео ИЛИ вложения ИЛИ транскрипт (см. Lesson::scopeWithCoreMaterials).
 * ДЗ и флешкарты считаются отдельно и в покрытие не входят.
 */
final class LessonMaterialsReport
{
    /** @return array{total:int,withMaterials:int,percent:int,video:int,attachments:int,transcript:int,homework:int,flashcards:int} */
    public static function overall(): array
    {
        $total = Lesson::query()->count();
        $withMaterials = Lesson::query()->withCoreMaterials()->count();

        return [
            'total' => $total,
            'withMaterials' => $withMaterials,
            'percent' => $total > 0 ? (int) round($withMaterials / $total * 100) : 0,
            'video' => Lesson::query()->withVideo()->count(),
            'attachments' => Lesson::query()->withAttachments()->count(),
            'transcript' => Lesson::query()->withTranscript()->count(),
            'homework' => Lesson::query()->withHomework()->count(),
            'flashcards' => Lesson::query()->withFlashcards()->count(),
        ];
    }
}
