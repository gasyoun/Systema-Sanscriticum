<?php

namespace App\Filament\Resources\CourseQuizResource\Pages;

use App\Filament\Resources\CourseQuizResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourseQuiz extends EditRecord
{
    protected static string $resource = CourseQuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
