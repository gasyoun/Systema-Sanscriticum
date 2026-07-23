<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /** The segment_* form fields are dehydrated(false) — assembled into the `segment` json column here. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['segment'] = CampaignResource::buildSegmentFromRawState($this->form->getRawState());

        return $data;
    }
}
