<?php

declare(strict_types=1);

namespace App\Filament\Resources\LessonAiEventResource\Pages;

use App\Filament\Resources\LessonAiEventResource;
use Filament\Resources\Pages\ListRecords;

class ListLessonAiEvents extends ListRecords
{
    protected static string $resource = LessonAiEventResource::class;

    protected static ?string $title = 'Запросы к ИИ-помощнику занятия';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
