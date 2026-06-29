<?php

namespace App\Filament\Resources\SupportTopicRuleResource\Pages;

use App\Filament\Resources\SupportTopicRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportTopicRule extends EditRecord
{
    protected static string $resource = SupportTopicRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
