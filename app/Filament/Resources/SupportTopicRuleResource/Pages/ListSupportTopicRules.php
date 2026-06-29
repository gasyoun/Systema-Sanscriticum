<?php

namespace App\Filament\Resources\SupportTopicRuleResource\Pages;

use App\Filament\Resources\SupportTopicRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportTopicRules extends ListRecords
{
    protected static string $resource = SupportTopicRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
