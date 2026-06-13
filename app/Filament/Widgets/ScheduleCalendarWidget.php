<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ScheduleResource;
use App\Models\Schedule;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Actions;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Календарь расписания (Google-Calendar-like) поверх модели Schedule.
 *
 * Скоуп преподавателя зеркалит ScheduleResource::getEloquentQuery(): учитель
 * видит/правит только события своих курсов. Форма модалок — общая со ScheduleResource
 * (ScheduleResource::formSchema()), поэтому валидация course_id по teacher_id одинакова.
 */
class ScheduleCalendarWidget extends FullCalendarWidget
{
    public Model|string|null $model = Schedule::class;

    // false → виджет не выводится на Dashboard при авто-обнаружении. Рендерится явно
    // на странице «Календарь» (CalendarPage), доступ к которой уже гейтится по роли.
    public static function canView(): bool
    {
        return false;
    }

    /** Тот же teacher-scope, что и в ресурсе: events и резолв записей по ссылке. */
    protected function getEloquentQuery(): Builder
    {
        $query = Schedule::query();

        $user = auth()->user();
        if ($user && $user->isTeacher()) {
            if (! $user->teacher_id) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('course', function (Builder $q) use ($user): void {
                    $q->where('teacher_id', $user->teacher_id);
                });
            }
        }

        return $query;
    }

    public function fetchEvents(array $info): array
    {
        return $this->getEloquentQuery()
            ->where('start', '>=', $info['start'])
            ->where('start', '<=', $info['end'])
            ->get()
            ->map(fn (Schedule $s): array => EventData::make()
                ->id($s->id)
                ->title($s->title)
                ->start($s->start)
                ->end($s->end)
                ->backgroundColor($s->color ?: '#3788d8')
                ->borderColor($s->color ?: '#3788d8')
                ->toArray())
            ->all();
    }

    public function getFormSchema(): array
    {
        return ScheduleResource::formSchema();
    }

    protected function headerActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Новое событие'),
        ];
    }

    protected function modalActions(): array
    {
        return [
            Actions\EditAction::make()
                // При drag&drop / resize FullCalendar присылает новые даты в arguments.event —
                // подставляем их в форму поверх данных записи, чтобы модалка открылась уже с
                // обновлённым временем (пользователь подтверждает сохранение).
                ->mountUsing(function (Schedule $record, Form $form, array $arguments): void {
                    $event = $arguments['event'] ?? [];

                    $form->fill([
                        ...$record->attributesToArray(),
                        'start' => $event['start'] ?? $record->start,
                        'end' => $event['end'] ?? $record->end,
                    ]);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
