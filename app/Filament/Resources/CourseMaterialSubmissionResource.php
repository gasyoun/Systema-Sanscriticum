<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Pages\MyMaterials;
use App\Filament\Resources\CourseMaterialSubmissionResource\Pages\ListCourseMaterialSubmissions;
use App\Models\CourseMaterialSubmission;
use App\Services\CourseMaterialSubmissionService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Куратор-очередь заявок «Мои материалы» (H4325, H4310 3/3): препод шлёт
 * видео-анонс/бейдж 4:3/конспект через {@see MyMaterials},
 * здесь куратор ведёт заявку accepted → in_progress → published. Публикация —
 * единственное место, которое реально пишет в courses/course_design_assets
 * (App\Services\CourseMaterialSubmissionService::publish()) — до этого момента
 * присланное препода нигде на витрине не появляется.
 */
class CourseMaterialSubmissionResource extends Resource
{
    protected static ?string $model = CourseMaterialSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Мои материалы (очередь)';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 32;

    protected static ?string $modelLabel = 'заявка на материалы';

    protected static ?string $pluralModelLabel = 'Заявки на материалы';

    protected static ?string $slug = 'course-material-submissions';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        // Заявки создаёт препод со страницы «Мои материалы», не куратор в админке.
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

    public static function getNavigationBadge(): ?string
    {
        $open = CourseMaterialSubmission::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(CourseMaterialSubmission::query()->with(['course', 'submittedBy', 'reviewedBy']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('course.title')->label('Курс')->searchable()->wrap()->limit(60),

                TextColumn::make('submittedBy.name')->label('Препод')->searchable(),

                BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (CourseMaterialSubmission $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        CourseMaterialSubmission::STATUS_ACCEPTED => 'warning',
                        CourseMaterialSubmission::STATUS_IN_PROGRESS => 'info',
                        CourseMaterialSubmission::STATUS_PUBLISHED => 'success',
                        default => 'gray',
                    }),

                IconColumn::make('has_video')->label('Видео')->boolean()->alignCenter()
                    ->getStateUsing(fn (CourseMaterialSubmission $record): bool => filled($record->video_announce_url)),

                IconColumn::make('has_badge')->label('Бейдж')->boolean()->alignCenter()
                    ->getStateUsing(fn (CourseMaterialSubmission $record): bool => $record->hasBadge()),

                IconColumn::make('has_notes')->label('Конспект')->boolean()->alignCenter()
                    ->getStateUsing(fn (CourseMaterialSubmission $record): bool => filled($record->notes)),

                TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable(),

                TextColumn::make('reviewedBy.name')->label('Куратор')->toggleable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')
                    ->options(CourseMaterialSubmission::STATUSES),
            ])
            ->actions([
                Action::make('openVideo')
                    ->label('Видео')
                    ->icon('heroicon-o-play-circle')
                    ->visible(fn (CourseMaterialSubmission $record): bool => filled($record->video_announce_url))
                    ->url(fn (CourseMaterialSubmission $record): ?string => $record->video_announce_url, shouldOpenInNewTab: true),

                Action::make('openBadge')
                    ->label('Бейдж')
                    ->icon('heroicon-o-photo')
                    ->visible(fn (CourseMaterialSubmission $record): bool => $record->hasBadge())
                    ->url(fn (CourseMaterialSubmission $record): ?string => $record->badgeUrl(), shouldOpenInNewTab: true),

                Action::make('viewNotes')
                    ->label('Конспект')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (CourseMaterialSubmission $record): bool => filled($record->notes))
                    ->modalHeading('Конспект от препода')
                    ->modalContent(fn (CourseMaterialSubmission $record) => view('filament.pages.course-material-submission-notes', ['notes' => $record->notes]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),

                Action::make('toInProgress')
                    ->label('В работе')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('info')
                    ->visible(fn (CourseMaterialSubmission $record): bool => $record->status === CourseMaterialSubmission::STATUS_ACCEPTED)
                    ->action(function (CourseMaterialSubmission $record, CourseMaterialSubmissionService $service): void {
                        $service->setStatus($record, CourseMaterialSubmission::STATUS_IN_PROGRESS, auth()->user());
                        Notification::make()->title('Заявка взята в работу')->success()->send();
                    }),

                Action::make('publish')
                    ->label('Опубликовать')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CourseMaterialSubmission $record): bool => $record->status === CourseMaterialSubmission::STATUS_IN_PROGRESS)
                    ->requiresConfirmation()
                    ->modalHeading('Опубликовать материалы курса')
                    ->modalDescription('Присланное препода уедет на витрину курса: видео-анонс в hero, бейдж — в course_design_assets (слот 4:3), конспект — в карточку курса. Пустые поля заявки не затирают уже опубликованное.')
                    ->modalSubmitActionLabel('Опубликовать')
                    ->action(function (CourseMaterialSubmission $record, CourseMaterialSubmissionService $service): void {
                        try {
                            $service->publish($record, auth()->user());
                        } catch (\Throwable $e) {
                            Notification::make()->title('Не опубликовали')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Материалы опубликованы')->success()->send();
                    }),

                Action::make('backToAccepted')
                    ->label('Вернуть в очередь')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (CourseMaterialSubmission $record): bool => $record->status === CourseMaterialSubmission::STATUS_IN_PROGRESS)
                    ->action(function (CourseMaterialSubmission $record, CourseMaterialSubmissionService $service): void {
                        $service->setStatus($record, CourseMaterialSubmission::STATUS_ACCEPTED, auth()->user());
                        Notification::make()->title('Заявка возвращена в очередь')->success()->send();
                    }),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100, 'all']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseMaterialSubmissions::route('/'),
        ];
    }
}
