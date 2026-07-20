<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomeworkSubmissionResource\Pages;
use App\Models\HomeworkSubmission;
use App\Services\HomeworkService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * Шаблоны частых комментариев куратора — разные для «принять» и «вернуть».
     *
     * @return array<string, string>
     */
    public static function commentTemplates(string $status): array
    {
        return $status === HomeworkSubmission::STATUS_ACCEPTED
            ? [
                'Отлично, работа принята! 🙏' => 'Отлично, работа принята! 🙏',
                'Хорошо, зачтено — двигаемся дальше.' => 'Хорошо, зачтено — двигаемся дальше.',
                'Принято. Обратите внимание на мелкие неточности в транслитерации.' => 'Принято. Обратите внимание на мелкие неточности в транслитерации.',
            ]
            : [
                'Проверьте, пожалуйста, транслитерацию (IAST) — есть ошибки.' => 'Проверьте, пожалуйста, транслитерацию (IAST) — есть ошибки.',
                'Не хватает части задания — дополните и пришлите снова.' => 'Не хватает части задания — дополните и пришлите снова.',
                'Посмотрите ещё раз склонение/спряжение в выделенных местах.' => 'Посмотрите ещё раз склонение/спряжение в выделенных местах.',
                'Деванагари местами неверно — сверьтесь с уроком.' => 'Деванагари местами неверно — сверьтесь с уроком.',
            ];
    }

    /**
     * Поля «шаблон + комментарий» для форм проверки (массовой и одиночной).
     *
     * @return array<int, Component>
     */
    public static function reviewCommentFields(string $status, bool $bodyRequired): array
    {
        return [
            Forms\Components\Select::make('template')
                ->label('Шаблон ответа')
                ->options(static::commentTemplates($status))
                ->placeholder('— выбрать готовый текст —')
                ->live()
                ->afterStateUpdated(fn ($state, Forms\Set $set) => filled($state) ? $set('body', $state) : null)
                ->dehydrated(false),
            Forms\Components\Textarea::make('body')
                ->label($status === HomeworkSubmission::STATUS_ACCEPTED
                    ? 'Комментарий студенту (необязательно)'
                    : 'Что нужно исправить')
                ->rows(4)
                ->required($bodyRequired),
        ];
    }

    /**
     * Применить вердикт к набору выбранных работ. Пропускает то, что нельзя
     * проверять или что уже в целевом статусе; возвращает уведомление с итогом.
     */
    public static function runBulkReview(Collection $records, string $status, array $data): void
    {
        $reviewer = auth()->user();
        $service = app(HomeworkService::class);
        $done = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! static::canReview($record) || $record->status === $status) {
                $skipped++;

                continue;
            }

            $service->recordReview($record, $reviewer, $status, $data['body'] ?? null);
            $done++;
        }

        $title = $status === HomeworkSubmission::STATUS_ACCEPTED
            ? "Принято работ: {$done}"
            : "Возвращено на доработку: {$done}";

        Notification::make()
            ->success()
            ->title($skipped > 0 ? "{$title} (пропущено: {$skipped})" : $title)
            ->send();
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
            // Очередь проверки: дольше всех ждущие работы — сверху.
            ->defaultSort('last_activity_at', 'asc')
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
                    ->label('Ждёт')
                    ->since()
                    ->description(fn ($record): ?string => $record->last_activity_at?->format('d.m.Y H:i'))
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

                Tables\Filters\SelectFilter::make('lesson')
                    ->label('Урок')
                    ->relationship('lesson', 'title', function (Builder $query) {
                        $user = auth()->user();
                        if ($user && $user->isTeacher() && ! $user->isAdminLike() && $user->teacher_id) {
                            $query->whereHas('course', fn (Builder $q) => $q->where('teacher_id', $user->teacher_id));
                        }
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Проверить'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('accept')
                    ->label('Принять')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Принять выбранные работы')
                    ->form(static::reviewCommentFields(HomeworkSubmission::STATUS_ACCEPTED, bodyRequired: false))
                    ->action(fn (Collection $records, array $data) => static::runBulkReview(
                        $records, HomeworkSubmission::STATUS_ACCEPTED, $data
                    ))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('return')
                    ->label('Вернуть на доработку')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Вернуть выбранные работы на доработку')
                    ->form(static::reviewCommentFields(HomeworkSubmission::STATUS_NEEDS_REVISION, bodyRequired: true))
                    ->action(fn (Collection $records, array $data) => static::runBulkReview(
                        $records, HomeworkSubmission::STATUS_NEEDS_REVISION, $data
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomeworkSubmissions::route('/'),
            'view' => Pages\ViewHomeworkSubmission::route('/{record}'),
        ];
    }
}
