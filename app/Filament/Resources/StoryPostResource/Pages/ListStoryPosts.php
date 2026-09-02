<?php

namespace App\Filament\Resources\StoryPostResource\Pages;

use App\Filament\Resources\StoryPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStoryPosts extends ListRecords
{
    protected static string $resource = StoryPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
