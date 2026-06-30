<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentGroupResource\Pages;
use App\Filament\Resources\StudentGroupResource\RelationManagers\StudentsRelationManager;
use App\Models\Group;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only витрина «Мои ученики» для преподавателей: список своих учебных
 * групп (привязанных к курсам преподавателя) и состав учеников каждой группы.
 *
 * Отдельно от админского GroupResource: тот правит состав групп вручную, а
 * членство тут payment-driven (см. PaymentObserver) — преподаватель только
 * смотрит, без CRUD. Скоупится по teacher_id курса группы.
 */
class StudentGroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Мои ученики';

    protected static ?string $modelLabel = 'группа';

    protected static ?string $pluralModelLabel = 'Мои ученики';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    /** Пункт в меню — только преподавателям; у админа есть полноценный GroupResource. */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isTeacher() === true;
    }

    public static function canView($record): bool
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
            && $record->courses()->where('teacher_id', $user->teacher_id)->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Считаем только активный состав (вышедшие сохраняют доступ, но в счётчик
        // «Учеников» не входят). Алиас сохраняет ключ колонки users_count.
        $query = parent::getEloquentQuery()->withCount('activeUsers as users_count');

        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            if (! $user->teacher_id) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('courses', function (Builder $q) use ($user) {
                    $q->where('teacher_id', $user->teacher_id);
                });
            }
        }

        return $query;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(2)
            ->schema([
                TextEntry::make('name')
                    ->label('Группа')
                    ->weight('bold'),

                TextEntry::make('courses.title')
                    ->label('Курс')
                    ->badge()
                    ->placeholder('—'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Группа')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('courses.title')
                    ->label('Курс')
                    ->badge()
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Учеников')
                    ->badge()
                    ->color('info')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Список учеников'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudentGroups::route('/'),
            'view' => Pages\ViewStudentGroup::route('/{record}'),
        ];
    }
}
