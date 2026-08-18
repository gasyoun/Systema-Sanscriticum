<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\FinanceAccess;
use App\Filament\Resources\TeacherPayoutAttributionSuggestionResource\Pages;
use App\Models\TeacherPayoutAttributionSuggestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * H3084 — очередь «этот «Расход» — выплата преподавателю?».
 *
 * Подтверждает бухгалтер (`RoleGate::accounting()`): на строках видно
 * вознаграждение конкретного человека, и это не для менеджера.
 *
 * Подтверждение меняет ТОЛЬКО статус предложения. В `teacher_payouts` и
 * `payments` ресурс не пишет ничего — перенос в выплатной реестр остаётся
 * отдельным действием человека. Единственное следствие подтверждения: сумма
 * входит в «выплачено» на экране «Потоки курса», и когда неразобранных
 * «Расходов» по семье не остаётся, слово «предварительно» уходит само.
 */
class TeacherPayoutAttributionSuggestionResource extends Resource
{
    use FinanceAccess;

    protected static ?string $model = TeacherPayoutAttributionSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 46;

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Подтверждение выплат преподавателям';

    protected static ?string $pluralModelLabel = 'Подтверждение выплат преподавателям';

    protected static ?string $modelLabel = 'Предложение разметки';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return [
            TeacherPayoutAttributionSuggestion::STATUS_PENDING => 'Ожидает',
            TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED => 'Подтверждено',
            TeacherPayoutAttributionSuggestion::STATUS_REJECTED => 'Отклонено',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('what')
                ->label('Что решается')
                ->content(
                    'Подтверждение говорит только одно: этот платёж — выплата преподавателю, '
                    .'а не аренда или реклама. Строки в реестре выплат оно не создаёт.'
                )
                ->columnSpanFull(),

            Forms\Components\Textarea::make('reason')
                ->label('Основание')
                ->rows(3)
                ->disabled()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('amount')->label('Сумма, ₽')->disabled(),
            Forms\Components\DatePicker::make('paid_on')->label('Дата платежа')->disabled(),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options(self::statusOptions())
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_on', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('payment_id')->label('Платёж')->sortable()
                    ->tooltip('Идентификатор платежа: по нему идёт дедупликация, не по сумме'),
                Tables\Columns\TextColumn::make('paid_on')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('teacher.name')->label('Преподаватель')->searchable(),
                Tables\Columns\TextColumn::make('course.title')->label('Курс')->limit(34)->wrap(),
                Tables\Columns\TextColumn::make('course_family')->label('Семья')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('amount')->label('Сумма, ₽')->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ')),
                Tables\Columns\TextColumn::make('confidence')->label('Уверенность')
                    ->formatStateUsing(fn (float $state): string => number_format($state * 100, 0).' %'),
                Tables\Columns\TextColumn::make('reason')->label('Основание')->limit(60)->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED => 'success',
                        TeacherPayoutAttributionSuggestion::STATUS_REJECTED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
                Tables\Columns\TextColumn::make('resolver.name')->label('Решил')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(self::statusOptions())
                    ->default(TeacherPayoutAttributionSuggestion::STATUS_PENDING),
                Tables\Filters\SelectFilter::make('teacher_id')->label('Преподаватель')->relationship('teacher', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Это выплата')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Подтвердить разметку')
                    ->modalDescription(
                        'Сумма войдёт в «выплачено» на экране «Потоки курса». '
                        .'Строка в реестре выплат при этом НЕ создаётся.'
                    )
                    ->modalSubmitActionLabel('Да, это выплата')
                    ->modalCancelActionLabel('Отмена')
                    ->visible(fn (TeacherPayoutAttributionSuggestion $record): bool => $record->status === TeacherPayoutAttributionSuggestion::STATUS_PENDING)
                    ->action(function (TeacherPayoutAttributionSuggestion $record): void {
                        $record->update([
                            'status' => TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED,
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ]);

                        Notification::make()->title('Разметка подтверждена')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Не выплата')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Отклонить разметку')
                    ->modalDescription('Платёж останется расходом (аренда, реклама и т.п.) и в сверку выплат не войдёт.')
                    ->modalSubmitActionLabel('Да, это не выплата')
                    ->modalCancelActionLabel('Отмена')
                    ->visible(fn (TeacherPayoutAttributionSuggestion $record): bool => $record->status === TeacherPayoutAttributionSuggestion::STATUS_PENDING)
                    ->action(function (TeacherPayoutAttributionSuggestion $record): void {
                        $record->update([
                            'status' => TeacherPayoutAttributionSuggestion::STATUS_REJECTED,
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ]);

                        Notification::make()->title('Предложение отклонено')->send();
                    }),

                Tables\Actions\Action::make('reopen')
                    ->label('Вернуть в очередь')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Вернуть строку в очередь')
                    ->modalDescription('Решение будет снято, строка снова станет «Ожидает».')
                    ->modalSubmitActionLabel('Вернуть')
                    ->modalCancelActionLabel('Отмена')
                    ->visible(fn (TeacherPayoutAttributionSuggestion $record): bool => $record->status !== TeacherPayoutAttributionSuggestion::STATUS_PENDING)
                    ->action(function (TeacherPayoutAttributionSuggestion $record): void {
                        $record->update([
                            'status' => TeacherPayoutAttributionSuggestion::STATUS_PENDING,
                            'resolved_by' => null,
                            'resolved_at' => null,
                        ]);

                        Notification::make()->title('Строка вернулась в очередь')->send();
                    }),

                Tables\Actions\ViewAction::make()->label('Подробнее'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherPayoutAttributionSuggestions::route('/'),
            'view' => Pages\ViewTeacherPayoutAttributionSuggestion::route('/{record}'),
        ];
    }
}
