<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CourseInterestRequestResource;
use App\Models\Course;
use App\Models\CourseInterestRequest;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «Спрос есть — запустим» (H5066): счётчик заявок интереса по курсам с тремя
 * интентами. Порог возобновления (revive_threshold) подсвечивается, когда
 * заявок «revive» набралось достаточно. Курс-анонсы без карточки (course_title
 * без course_id) в эту таблицу не попадают — они видны в ленте заявок.
 */
class CourseInterestByCourseWidget extends TableWidget
{
    protected static ?string $heading = 'Спрос по курсам';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Course::query()
                    ->whereHas('courseInterestRequests')
                    ->withCount([
                        'courseInterestRequests as join_count' => fn ($q) => $q->where('intent', CourseInterestRequest::INTENT_JOIN),
                        'courseInterestRequests as recording_count' => fn ($q) => $q->where('intent', CourseInterestRequest::INTENT_RECORDING),
                        'courseInterestRequests as revive_count' => fn ($q) => $q->where('intent', CourseInterestRequest::INTENT_REVIVE),
                    ])
                    ->orderByDesc('join_count')
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label('Курс')
                    ->url(fn (Course $record): string => CourseInterestRequestResource::getUrl('index'))
                    ->description(fn (Course $record): string => $record->revive_threshold !== null
                        ? 'Порог возобновления: '.$record->revive_threshold
                        : ''),
                TextColumn::make('join_count')
                    ->label('В набор')
                    ->alignCenter(),
                TextColumn::make('recording_count')
                    ->label('Запись')
                    ->alignCenter(),
                TextColumn::make('revive_count')
                    ->label('Возобновить')
                    ->alignCenter()
                    ->color(fn (Course $record): string => $record->revive_threshold !== null
                        && $record->revive_count >= $record->revive_threshold
                        ? 'success'
                        : 'gray')
                    ->description(fn (Course $record): string => $record->revive_threshold !== null
                        && $record->revive_count >= $record->revive_threshold
                        ? 'Порог достигнут!'
                        : ''),
                TextColumn::make('interests_total')
                    ->label('Всего')
                    ->alignCenter()
                    ->state(fn (Course $record): string => (string) ($record->join_count + $record->recording_count + $record->revive_count)),
            ]);
    }
}
