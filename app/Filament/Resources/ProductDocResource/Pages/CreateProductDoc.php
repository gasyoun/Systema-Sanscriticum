<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDocResource\Pages;

use App\Filament\Resources\ProductDocResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductDoc extends CreateRecord
{
    protected static string $resource = ProductDocResource::class;
}
