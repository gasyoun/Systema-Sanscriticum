<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CourseInterestRequestResource\Pages;
use App\Models\CourseInterestRequest;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Кураторская витрина заявок интереса на курс (H5066): кто хочет в следующий
 * набор, купить запись или возобновить занятия. Пишется публичной формой
 * /interest/{course} — здесь только разбор: статус new → done. Видит менеджер
 * (куратор) и админ; счётчик спроса по курсам — виджет рядом.
 */
class CourseInterestRequestResource extends Resource
{
    protected static ?string $model = CourseInterestRequest::class;

    /** Куратор (manager) разбирает заявки; create/delete — admin. */
    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canView($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::adminOnly();
    }

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 85;

    protected static ?string $navigationLabel = 'Заявки на курсы';

    protected static ?string $pluralModelLabel = 'Заявки на курсы';

    protected static ?string $modelLabel = 'заявка интереса';

    /** Бейдж в меню = сколько новых заявок ждёт разбора. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->where('status', CourseInterestRequest::STATUS_NEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Курс подгружается жадным способом: колонка «Курс» зовёт courseLabel(),
     * который без этого давал по запросу на строку (N+1).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('course');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        CourseInterestRequest::STATUS_NEW => 'Новая',
                        CourseInterestRequest::STATUS_DONE => 'Разобрана',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('course_label')
                    ->label('Курс')
                    ->state(fn (CourseInterestRequest $record): string => $record->courseLabel())
                    ->searchable(query: function ($query, string $search): mixed {
                        return $query->where(function ($q) use ($search): void {
                            $q->where('course_title', 'like', "%{$search}%")
                                ->orWhereHas('course', fn ($cq) => $cq->where('title', 'like', "%{$search}%"));
                        });
                    }),
                Tables\Columns\TextColumn::make('intent')
                    ->label('Интент')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CourseInterestRequest::intentLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        CourseInterestRequest::INTENT_JOIN => 'success',
                        CourseInterestRequest::INTENT_RECORDING => 'info',
                        CourseInterestRequest::INTENT_REVIVE => 'warning',
                        CourseInterestRequest::INTENT_TRANSFER => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('telegram')
                    ->label('Telegram')
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CourseInterestRequest::STATUS_NEW ? 'Новая' : 'Разобрана')
                    ->color(fn (string $state): string => $state === CourseInterestRequest::STATUS_NEW ? 'warning' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        CourseInterestRequest::STATUS_NEW => 'Новые',
                        CourseInterestRequest::STATUS_DONE => 'Разобранные',
                    ]),
                Tables\Filters\SelectFilter::make('intent')
                    ->label('Интент')
                    ->options(CourseInterestRequest::intentLabels()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourseInterestRequests::route('/'),
            'edit' => Pages\EditCourseInterestRequest::route('/{record}/edit'),
        ];
    }
}
