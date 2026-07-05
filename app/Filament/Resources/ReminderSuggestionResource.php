<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ReminderSuggestionResource\Pages;
use App\Models\ReminderSuggestion;
use App\Services\Reminders\ReminderSuggestionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Очередь предложений «напомнить студенту», найденных детектором
 * (reminders:detect-requests) в переписке. Одна и та же логика подтверждения/
 * отклонения, что и в инлайн-баннере Helpdesk — см. ReminderSuggestionService.
 */
class ReminderSuggestionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ReminderSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 51;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Предложения напоминаний';

    protected static ?string $pluralModelLabel = 'Предложения напоминаний';

    protected static ?string $modelLabel = 'Предложение напоминания';

    public static function canCreate(): bool
    {
        return false; // заводятся только детектором
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    private static function statusOptions(): array
    {
        return [
            ReminderSuggestion::STATUS_PENDING => 'Ожидает',
            ReminderSuggestion::STATUS_APPROVED => 'Подтверждено',
            ReminderSuggestion::STATUS_DISMISSED => 'Отклонено',
            ReminderSuggestion::STATUS_EXPIRED => 'Просрочено',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('detected_text')
                ->label('Обнаруженный текст/причина')
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\DateTimePicker::make('suggested_date')
                ->label('Предполагаемая дата')
                ->native(false)
                ->seconds(false),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options(self::statusOptions())
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Обнаружено')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Студент')->searchable(),
                Tables\Columns\TextColumn::make('detected_text')->label('Текст/причина')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('suggested_date')->label('Предполагаемая дата')->dateTime('d.m.Y H:i')->placeholder('не распознана'),
                Tables\Columns\TextColumn::make('confidence')->label('Уверенность')->formatStateUsing(fn (float $state): string => number_format($state * 100, 0).'%'),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReminderSuggestion::STATUS_APPROVED => 'success',
                        ReminderSuggestion::STATUS_DISMISSED => 'gray',
                        ReminderSuggestion::STATUS_EXPIRED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(self::statusOptions())
                    ->default(ReminderSuggestion::STATUS_PENDING),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ReminderSuggestion $record): bool => $record->status === ReminderSuggestion::STATUS_PENDING)
                    ->modalHeading(fn (ReminderSuggestion $record) => 'Напоминание для: '.$record->user?->name)
                    ->modalSubmitActionLabel('Запланировать')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Textarea::make('message')
                            ->label('Текст напоминания')
                            ->required()
                            ->rows(4)
                            ->maxLength(2000)
                            ->default(fn (ReminderSuggestion $record) => $record->detected_text),

                        Forms\Components\DateTimePicker::make('scheduled_for')
                            ->label('Отправить не раньше')
                            ->native(false)
                            ->seconds(false)
                            ->minDate(now())
                            ->default(fn (ReminderSuggestion $record) => $record->suggested_date ?? now()->addDay())
                            ->required(),

                        Forms\Components\CheckboxList::make('channels')
                            ->label('Каналы')
                            ->options([
                                'to_telegram' => 'Telegram',
                                'to_vk' => 'ВКонтакте',
                                'to_email' => 'Email',
                            ])
                            ->default(['to_telegram'])
                            ->required()
                            ->columns(3),
                    ])
                    ->action(function (ReminderSuggestion $record, array $data) {
                        app(ReminderSuggestionService::class)->approve($record, [
                            'message' => $data['message'],
                            'scheduled_for' => $data['scheduled_for'],
                            'channels' => $data['channels'] ?? [],
                        ], auth()->user());

                        Notification::make()->title('Напоминание запланировано')->success()->send();
                    }),

                Tables\Actions\Action::make('dismiss')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (ReminderSuggestion $record): bool => $record->status === ReminderSuggestion::STATUS_PENDING)
                    ->action(function (ReminderSuggestion $record) {
                        app(ReminderSuggestionService::class)->dismiss($record, auth()->user());

                        Notification::make()->title('Предложение отклонено')->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReminderSuggestions::route('/'),
            'edit' => Pages\EditReminderSuggestion::route('/{record}/edit'),
        ];
    }
}
