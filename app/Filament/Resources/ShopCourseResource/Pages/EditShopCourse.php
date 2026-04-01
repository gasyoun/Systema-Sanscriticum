<?php

namespace App\Filament\Resources\ShopCourseResource\Pages;

use App\Filament\Resources\ShopCourseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopCourse extends EditRecord
{
    protected static string $resource = ShopCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
