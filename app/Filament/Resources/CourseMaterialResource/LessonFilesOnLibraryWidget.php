<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseMaterialResource;

use App\Filament\Resources\LessonResource;
use App\Models\Lesson;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class LessonFilesOnLibraryWidget extends Widget
{
    protected static string $view = 'filament.resources.course-material-resource.lesson-files-on-library';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, Lesson>
     */
    public function lessons(): Collection
    {
        return Lesson::query()
            ->withAttachments()
            ->with('course:id,title')
            ->orderByDesc('lesson_date')
            ->limit(40)
            ->get();
    }

    public function lessonEditUrl(Lesson $lesson): string
    {
        return LessonResource::getUrl('edit', ['record' => $lesson]);
    }
}
