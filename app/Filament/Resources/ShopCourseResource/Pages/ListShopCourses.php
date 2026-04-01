<?php

namespace App\Filament\Resources\ShopCourseResource\Pages;

use App\Filament\Resources\ShopCourseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShopCourses extends ListRecords
{
    protected static string $resource = ShopCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
