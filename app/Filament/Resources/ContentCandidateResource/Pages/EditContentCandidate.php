<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCandidateResource\Pages;

use App\Filament\Resources\ContentCandidateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContentCandidate extends EditRecord
{
    protected static string $resource = ContentCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
