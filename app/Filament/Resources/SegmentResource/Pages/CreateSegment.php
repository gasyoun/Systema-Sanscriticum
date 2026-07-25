<?php

declare(strict_types=1);

namespace App\Filament\Resources\SegmentResource\Pages;

use App\Filament\Resources\SegmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSegment extends CreateRecord
{
    protected static string $resource = SegmentResource::class;
}
