<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarRubricResource\Pages;

use App\Filament\Resources\ContentCalendarRubricResource;
use App\Models\ContentCalendarRubric;
use App\Services\Content\RubricScheduler;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Смена расписания пересобирает очередь: невышедшие посты встают на новые
 * моменты подряд.
 */
class EditContentCalendarRubric extends EditRecord
{
    protected static string $resource = ContentCalendarRubricResource::class;

    /** @var list<array{days: list<int>, time: string}> */
    private array $scheduleBefore = [];

    protected function beforeSave(): void
    {
        /** @var ContentCalendarRubric $rubric */
        $rubric = $this->getRecord();
        $this->scheduleBefore = $rubric->scheduleEntries();
    }

    protected function afterSave(): void
    {
        /** @var ContentCalendarRubric $rubric */
        $rubric = $this->getRecord()->refresh();
        if ($rubric->scheduleEntries() === $this->scheduleBefore) {
            return;
        }

        $n = app(RubricScheduler::class)->reflow($rubric);
        if ($n > 0) {
            Notification::make()->title("Расписание изменилось — новое время получили постов: {$n}")->success()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
