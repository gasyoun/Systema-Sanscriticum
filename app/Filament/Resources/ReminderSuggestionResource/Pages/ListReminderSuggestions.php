<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReminderSuggestionResource\Pages;

use App\Filament\Resources\ReminderSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListReminderSuggestions extends ListRecords
{
    protected static string $resource = ReminderSuggestionResource::class;
}
