<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomeworkSubmissionResource\Pages;
use App\Models\HomeworkSubmission;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HomeworkSubmissionResource extends Resource
{
    protected static ?string $model = HomeworkSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Домашние работы';

    protected static ?string $modelLabel = 'домашняя работа';

    protected static ?string $pluralModelLabel = 'Домашние работы';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canEdit($record): bool
    {
        return static::canReview($record);
    }

    public static function canCreate(): bool
    {
        // Работы создаются студентами из кабинета, а не в админке.
        return false;
    }

    /** Проверять может админ или преподаватель курса этой работы. */
    public static function canReview($record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isAdminLike()) {
            return true;
        }

        return $user->isTeacher()
            && $user->teacher_id
            && optional($record->course)->teacher_id === $user->teacher_id;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            // Черновики студентов в админку не показываем — только реально сданное.
            ->where('status', '!=', HomeworkSubmission::STATUS_DRAFT);

        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            if (! $user->teacher_id) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('course', function ($q) use ($user) {
                    $q->where('teacher_id', $user->teacher_id);
                });
            }
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', HomeworkSubmission::STATUS_SUBMITTED)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->limit(30)
                    ->sortable(),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->limit(30),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        HomeworkSubmission::STATUS_SUBMITTED => 'На проверке',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'На доработку',
                        HomeworkSubmission::STATUS_ACCEPTED => 'Принято',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        HomeworkSubmission::STATUS_SUBMITTED => 'info',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'danger',
                        HomeworkSubmission::STATUS_ACCEPTED => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Активность')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Проверено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        HomeworkSubmission::STATUS_SUBMITTED => 'На проверке',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'На доработку',
                        HomeworkSubmission::STATUS_ACCEPTED => 'Принято',
                    ])
                    ->default(HomeworkSubmission::STATUS_SUBMITTED),

                Tables\Filters\SelectFilter::make('course')
                    ->label('Курс')
                    ->relationship('course', 'title', function (Builder $query) {
                        $user = auth()->user();
                        if ($user && $user->isTeacher() && ! $user->isAdminLike() && $user->teacher_id) {
                            $query->where('teacher_id', $user->teacher_id);
                        }
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Проверить'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomeworkSubmissions::route('/'),
            'view' => Pages\ViewHomeworkSubmission::route('/{record}'),
        ];
    }
}
