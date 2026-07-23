<?php

declare(strict_types=1);

namespace App\Filament\Resources\LectureClipResource\Pages;

use App\Filament\Resources\LectureClipResource;
use Filament\Resources\Pages\ListRecords;

class ListLectureClips extends ListRecords
{
    protected static string $resource = LectureClipResource::class;
}
