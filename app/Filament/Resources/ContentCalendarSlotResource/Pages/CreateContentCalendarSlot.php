<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Pages;

use App\Filament\Resources\ContentCalendarSlotResource;
use App\Models\ContentCalendarSlot;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

/**
 * Слот, созданный вручную, сразу запланирован: Keep не нужен, дата слота
 * берётся из времени публикации.
 */
class CreateContentCalendarSlot extends CreateRecord
{
    protected static string $resource = ContentCalendarSlotResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $publishAt = Carbon::parse($data['publish_at'], 'Europe/Moscow');

        return array_merge($data, [
            'publish_at' => $publishAt,
            'slot_date' => $publishAt->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_MANUAL,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'source_kind' => 'admin',
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
