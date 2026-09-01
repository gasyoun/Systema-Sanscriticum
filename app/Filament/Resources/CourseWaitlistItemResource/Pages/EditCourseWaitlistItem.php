<?php

namespace App\Filament\Resources\CourseWaitlistItemResource\Pages;

use App\Filament\Resources\CourseWaitlistItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourseWaitlistItem extends EditRecord
{
    protected static string $resource = CourseWaitlistItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
