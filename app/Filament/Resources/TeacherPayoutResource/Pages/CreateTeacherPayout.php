<?php

namespace App\Filament\Resources\TeacherPayoutResource\Pages;

use App\Filament\Resources\TeacherPayoutResource;
use App\Services\TeacherPayoutPoster;
use Filament\Resources\Pages\CreateRecord;

class CreateTeacherPayout extends CreateRecord
{
    protected static string $resource = TeacherPayoutResource::class;

    protected function afterCreate(): void
    {
        if ($this->data['post_to_finance'] ?? true) {
            app(TeacherPayoutPoster::class)->post($this->record);
        }
    }
}
