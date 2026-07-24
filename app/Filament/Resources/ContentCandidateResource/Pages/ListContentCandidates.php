<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCandidateResource\Pages;

use App\Filament\Resources\ContentCandidateResource;
use Filament\Resources\Pages\ListRecords;

class ListContentCandidates extends ListRecords
{
    protected static string $resource = ContentCandidateResource::class;
}
