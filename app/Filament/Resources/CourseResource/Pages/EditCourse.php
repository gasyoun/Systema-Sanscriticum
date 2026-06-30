<?php

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /** Подтянуть со-преподов из pivot в репитер формы. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['co_teachers'] = $this->record->teachers->map(fn ($t) => [
            'teacher_id' => $t->id,
            'salary_type' => $t->pivot->salary_type,
            'salary_value' => $t->pivot->salary_value,
        ])->all();

        return $data;
    }

    protected function afterSave(): void
    {
        CourseResource::syncCoTeachers($this->record, $this->data['co_teachers'] ?? []);
    }
}
