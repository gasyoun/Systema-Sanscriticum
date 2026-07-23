<?php

declare(strict_types=1);

namespace App\Filament\Resources\LectureClipResource\Pages;

use App\Filament\Resources\LectureClipResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLectureClip extends EditRecord
{
    protected static string $resource = LectureClipResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
