<?php

namespace App\Filament\Resources\CourseMaterialResource\Pages;

use App\Filament\Resources\CourseMaterialResource;
use App\Filament\Resources\CourseMaterialResource\LessonFilesOnLibraryWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCourseMaterials extends ListRecords
{
    protected static string $resource = CourseMaterialResource::class;

    public function getSubheading(): ?string
    {
        return 'Это библиотека ссылок на литературу. Еженедельные файлы (хинди и другие) лежат в уроках — таблица ниже.';
    }

    protected function getHeaderWidgets(): array
    {
        return [LessonFilesOnLibraryWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
