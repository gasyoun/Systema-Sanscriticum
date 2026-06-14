<?php

namespace App\Filament\Resources\TeacherPayoutResource\Pages;

use App\Filament\Resources\TeacherPayoutResource;
use App\Services\TeacherPayoutPoster;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTeacherPayout extends EditRecord
{
    protected static string $resource = TeacherPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $poster = app(TeacherPayoutPoster::class);

        if ($this->data['post_to_finance'] ?? false) {
            // Создаём или ресинкаем транзакцию-зеркало под изменённую выплату.
            $poster->post($this->record);
        } elseif ($this->record->payment_id) {
            // Тумблер выключили — убираем транзакцию из Финансов.
            $poster->unpost($this->record);
        }
    }
}
