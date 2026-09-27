<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBusinessStoryPublicationResource\Pages;

use App\Filament\Resources\TelegramBusinessStoryPublicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListTelegramBusinessStoryPublications extends ListRecords
{
    protected static string $resource = TelegramBusinessStoryPublicationResource::class;
}
