<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookClubOrderResource\Pages;

use App\Filament\Resources\BookClubOrderResource;
use Filament\Resources\Pages\EditRecord;

class EditBookClubOrder extends EditRecord
{
    protected static string $resource = BookClubOrderResource::class;
}
