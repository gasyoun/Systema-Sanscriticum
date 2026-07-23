<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** The segment_* form fields are dehydrated(false) — assembled into the `segment` json column here. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['segment'] = CampaignResource::buildSegmentFromRawState($this->form->getRawState());

        return $data;
    }
}
