<?php

namespace App\Filament\Resources\StoryPostResource\Pages;

use App\Filament\Resources\StoryPostResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStoryPost extends EditRecord
{
    protected static string $resource = StoryPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
