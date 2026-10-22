<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Pages;

use App\Filament\Resources\ContentCalendarSlotResource;
use App\Services\Content\PostFormatter;
use Filament\Resources\Pages\EditRecord;

class EditContentCalendarSlot extends EditRecord
{
    protected static string $resource = ContentCalendarSlotResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['body'] = PostFormatter::toEditorHtml($data['body'] ?? null);

        return $data;
    }
}
