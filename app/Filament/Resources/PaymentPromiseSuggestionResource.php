<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PaymentPromiseSuggestionResource\Pages;
use App\Models\Course;
use App\Models\PaymentPromiseSuggestion;
use App\Services\ConditionalAccessGranter;
use App\Services\Promises\PaymentPromiseSuggestionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Очередь предложений «студент просит отсрочку оплаты» (promises:detect-deferrals).
 * Approve → PaymentPromise active; grant_access default OFF (H2156).
 */
class PaymentPromiseSuggestionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PaymentPromiseSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 52;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Предложения отсрочек';

    protected static ?string $pluralModelLabel = 'Предложения отсрочек';

    protected static ?string $modelLabel = 'Предложение отсрочки';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    private static function statusOptions(): array
    {
        return [
            PaymentPromiseSuggestion::STATUS_PENDING => 'Ожидает',
            PaymentPromiseSuggestion::STATUS_APPROVED => 'Подтверждено',
            PaymentPromiseSuggestion::STATUS_DISMISSED => 'Отклонено',
            PaymentPromiseSuggestion::STATUS_EXPIRED => 'Просрочено',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('detected_text')
                ->label('Обнаруженный текст / причина')
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\DatePicker::make('suggested_date')
                ->label('Предполагаемая дата оплаты')
                ->native(false),

            Forms\Components\TextInput::make('suggested_amount')
                ->label('Предполагаемая сумма')
                ->numeric(),

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
                Tables\Columns\TextColumn::make('course.title')->label('Курс')->placeholder('— выбрать при approve'),
                Tables\Columns\TextColumn::make('detected_text')->label('Текст/причина')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('suggested_date')->label('Дата')->date('d.m.Y')->placeholder('не распознана'),
                Tables\Columns\TextColumn::make('suggested_amount')->label('Сумма')->placeholder('—'),
                Tables\Columns\TextColumn::make('confidence')->label('Уверенность')
                    ->formatStateUsing(fn (float $state): string => number_format($state * 100, 0).'%'),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PaymentPromiseSuggestion::STATUS_APPROVED => 'success',
                        PaymentPromiseSuggestion::STATUS_DISMISSED => 'gray',
                        PaymentPromiseSuggestion::STATUS_EXPIRED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(self::statusOptions())
                    ->default(PaymentPromiseSuggestion::STATUS_PENDING),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (PaymentPromiseSuggestion $record): bool => $record->status === PaymentPromiseSuggestion::STATUS_PENDING)
                    ->modalHeading(fn (PaymentPromiseSuggestion $record) => 'Обещание оплаты — '.($record->user?->name ?? '#'.$record->user_id))
                    ->modalSubmitActionLabel('Создать обещание')
                    ->modalWidth('lg')
                    ->form(fn (PaymentPromiseSuggestion $record): array => [
                        Forms\Components\DatePicker::make('promised_at')
                            ->label('Дата обещанной оплаты')
                            ->required()
                            ->native(false)
                            ->minDate(now()->subDays(1))
                            ->default($record->suggested_date?->toDateString() ?? now()->addDays(7)->toDateString()),

                        Forms\Components\Select::make('course_id')
                            ->label('Курс')
                            ->required()
                            ->searchable()
                            ->options(function () use ($record) {
                                $candidates = $record->candidate_course_ids ?? [];
                                $query = Course::query()->where('is_active', true)->orderBy('title');
                                if ($candidates !== []) {
                                    $query->whereIn('id', $candidates);
                                }

                                return $query->pluck('title', 'id')->all();
                            })
                            ->default($record->course_id),

                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма (₽)')
                            ->numeric()
                            ->minValue(0)
                            ->default($record->suggested_amount !== null ? (float) $record->suggested_amount : null)
                            ->helperText('Можно оставить пустым.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Комментарий')
                            ->rows(3)
                            ->default($record->detected_text),

                        Forms\Components\Toggle::make('grant_access')
                            ->label('Открыть доступ под обещание')
                            ->helperText('По умолчанию выкл. — только запись даты в «Мои долги».')
                            ->default(false)
                            ->live()
                            ->dehydrated(true),

                        Forms\Components\Radio::make('access_mode')
                            ->label('Что открыть')
                            ->options([
                                ConditionalAccessGranter::MODE_FULL => 'Весь курс',
                                ConditionalAccessGranter::MODE_BLOCKS => 'Конкретные блоки',
                            ])
                            ->default(ConditionalAccessGranter::MODE_BLOCKS)
                            ->inline()
                            ->live()
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('grant_access')),

                        Forms\Components\TextInput::make('access_blocks')
                            ->label('Номера блоков (через запятую)')
                            ->placeholder('5, 6, 7')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('grant_access')
                                && $get('access_mode') === ConditionalAccessGranter::MODE_BLOCKS),
                    ])
                    ->action(function (PaymentPromiseSuggestion $record, array $data) {
                        try {
                            app(PaymentPromiseSuggestionService::class)->approve($record, [
                                'promised_at' => $data['promised_at'],
                                'course_id' => (int) $data['course_id'],
                                'amount' => $data['amount'] ?? null,
                                'note' => $data['note'] ?? null,
                                'grant_access' => ! empty($data['grant_access']),
                                'access_mode' => $data['access_mode'] ?? ConditionalAccessGranter::MODE_BLOCKS,
                                'access_blocks' => $data['access_blocks'] ?? null,
                            ], auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Не удалось подтвердить')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Обещание создано / обновлено')->success()->send();
                    }),

                Tables\Actions\Action::make('dismiss')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (PaymentPromiseSuggestion $record): bool => $record->status === PaymentPromiseSuggestion::STATUS_PENDING)
                    ->action(function (PaymentPromiseSuggestion $record) {
                        app(PaymentPromiseSuggestionService::class)->dismiss($record, auth()->user());

                        Notification::make()->title('Предложение отклонено')->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentPromiseSuggestions::route('/'),
            'edit' => Pages\EditPaymentPromiseSuggestion::route('/{record}/edit'),
        ];
    }
}
