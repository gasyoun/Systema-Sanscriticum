<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsDeckResource\Pages;

use App\Filament\Resources\SrsDeckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSrsDeck extends CreateRecord
{
    protected static string $resource = SrsDeckResource::class;
}
