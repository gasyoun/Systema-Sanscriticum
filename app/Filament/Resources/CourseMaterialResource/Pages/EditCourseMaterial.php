<?php

namespace App\Filament\Resources\CourseMaterialResource\Pages;

use App\Filament\Resources\CourseMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourseMaterial extends EditRecord
{
    protected static string $resource = CourseMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Проверить ссылку глазами до публикации — главный шаг фазы 1:
            // файл живёт не у нас и может исчезнуть без предупреждения.
            Actions\Action::make('open')
                ->label('Открыть ссылку')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => $this->getRecord()->effectiveUrl())
                ->openUrlInNewTab(),

            Actions\DeleteAction::make(),
        ];
    }
}
