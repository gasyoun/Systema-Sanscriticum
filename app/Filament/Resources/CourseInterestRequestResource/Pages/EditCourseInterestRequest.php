<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseInterestRequestResource\Pages;

use App\Filament\Resources\CourseInterestRequestResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditCourseInterestRequest extends EditRecord
{
    protected static string $resource = CourseInterestRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
