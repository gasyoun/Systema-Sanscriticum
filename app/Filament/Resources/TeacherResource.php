<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\TeacherResource\Pages;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherAccountService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class TeacherResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Teacher::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Личные данные')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')
                            ->label('ФИО Преподавателя')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? 'Будет логином преподавателя в админке.'
                                : null)
                            ->maxLength(255),
                        \Filament\Forms\Components\TextInput::make('phone')
                            ->label('Телефон')
                            ->tel(),
                    ])->columns(3),

                \Filament\Forms\Components\Section::make('Аккаунт для входа в админку')
                    ->description('Создаётся автоматически с ролью «Преподаватель». Email из блока выше будет логином. После сохранения преподавателю уходит письмо-приглашение с доступами.')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('account_password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->maxLength(255)
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? 'Минимум 6 символов. Будет отправлен преподавателю в письме-приглашении.'
                                : 'Заполните, чтобы сбросить пароль — новый уйдёт преподавателю на почту. Оставьте пустым — текущий пароль не изменится.')
                            ->dehydrated(false),
                    ])
                    ->visible(fn (?\App\Models\Teacher $record, string $operation) => $operation === 'create' || ($record && $record->email)
                    )
                    ->columns(1),

                \Filament\Forms\Components\Section::make('Соцсети и Реквизиты')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('telegram')
                            ->label('Telegram (Ник)'),
                        \Filament\Forms\Components\TextInput::make('vk')
                            ->label('ВКонтакте (Ссылка)'),
                        \Filament\Forms\Components\Textarea::make('requisites')
                            ->label('Реквизиты для выплаты ЗП')
                            ->columnSpanFull(),
                        \Filament\Forms\Components\RichEditor::make('bio')
                            ->label('Биография / Регалии')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('telegram')
                    ->label('Telegram')
                    ->icon('heroicon-m-paper-airplane'),

                \Filament\Tables\Columns\TextColumn::make('courses_count')
                    ->counts('courses')
                    ->label('Курсов')
                    ->badge()
                    ->color('info'),

                // Есть ли у карточки активный аккаунт для входа. Старые карточки,
                // заведённые до авто-аккаунтов, висят без доступа — их видно по «нет».
                \Filament\Tables\Columns\IconColumn::make('has_account')
                    ->label('Доступ')
                    ->state(fn (Teacher $record): bool => User::query()
                        ->where('teacher_id', $record->id)
                        ->orWhere('email', $record->email)
                        ->exists())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                // КОЛОНКА "БАЛАНС" С ВЫПАДАЮЩИМ ОКНОМ
                \Filament\Tables\Columns\TextColumn::make('balance')
                    ->label('К выплате (Баланс)')
                    ->state(function (\App\Models\Teacher $record) {
                        $earned = $record->calculateEarnings();
                        $paid = $record->payouts()->sum('amount');

                        return number_format($earned - $paid, 0, '.', ' ').' ₽';
                    })
                    ->badge()
                    ->color(fn (string $state) => str_contains($state, '-') || $state === '0 ₽' ? 'success' : 'warning')
                    ->icon('heroicon-m-wallet')
                    ->action(
                        // ДЕЙСТВИЕ ПРИ КЛИКЕ: Открываем окно статистики и выплат
                        \Filament\Tables\Actions\Action::make('manage_finances')
                            ->modalHeading(fn (\App\Models\Teacher $record) => 'Финансы: '.$record->name)
                            ->modalWidth('md')
                            ->form([
                                // Интерактивная статистика с фильтрами
                                \Filament\Forms\Components\Section::make('Детальная статистика')
                                    ->schema([
                                        \Filament\Forms\Components\Grid::make(2)
                                            ->schema([
                                                \Filament\Forms\Components\DatePicker::make('filter_start')
                                                    ->label('От даты')
                                                    ->default(now()->startOfMonth())
                                                    ->live(),

                                                \Filament\Forms\Components\DatePicker::make('filter_end')
                                                    ->label('До даты')
                                                    ->default(now()->endOfMonth())
                                                    ->live(),
                                            ]),

                                        \Filament\Forms\Components\Placeholder::make('custom_period_stats')
                                            ->label('📊 Заработано за выбранный период:')
                                            ->content(function (\Filament\Forms\Get $get, \App\Models\Teacher $record) {
                                                $start = $get('filter_start');
                                                $end = $get('filter_end');

                                                if (! $start || ! $end) {
                                                    return 'Выберите даты';
                                                }

                                                $earned = $record->calculateEarnings(
                                                    \Carbon\Carbon::parse($start)->startOfDay(),
                                                    \Carbon\Carbon::parse($end)->endOfDay()
                                                );

                                                return number_format($earned, 0, '.', ' ').' ₽';
                                            }),
                                    ]),

                                // Общие цифры за всё время
                                \Filament\Forms\Components\Section::make('Общие показатели')
                                    ->schema([
                                        \Filament\Forms\Components\Placeholder::make('total_stats')
                                            ->label('💰 Заработано за всё время:')
                                            ->content(fn (\App\Models\Teacher $record) => number_format($record->calculateEarnings(), 0, '.', ' ').' ₽'
                                            ),

                                        \Filament\Forms\Components\Placeholder::make('paid_stats')
                                            ->label('✅ Уже выплачено вами:')
                                            ->content(fn (\App\Models\Teacher $record) => number_format($record->payouts()->sum('amount'), 0, '.', ' ').' ₽'
                                            ),
                                    ])->columns(2),

                                // Блок 3: Форма для новой выплаты
                                \Filament\Forms\Components\Section::make('Зафиксировать новую выплату')
                                    ->description('Внесите сюда сумму, которую вы перевели преподавателю.')
                                    ->schema([
                                        \Filament\Forms\Components\TextInput::make('payout_amount')
                                            ->label('Сумма выплаты (₽)')
                                            ->numeric()
                                            ->required(),
                                        \Filament\Forms\Components\TextInput::make('comment')
                                            ->label('Комментарий (необязательно)')
                                            ->placeholder('Например: ЗП за март'),
                                    ]),
                            ])
                            // Что делаем, когда админ нажал "Сохранить"
                            ->action(function (\App\Models\Teacher $record, array $data) {
                                $record->payouts()->create([
                                    'amount' => $data['payout_amount'],
                                    'comment' => $data['comment'],
                                ]);
                                \Filament\Notifications\Notification::make()
                                    ->title('Выплата успешно записана!')
                                    ->success()
                                    ->send();
                            })
                            ->modalSubmitActionLabel('Записать выплату')
                    ),
            ])
            ->filters([
                //
            ])
            ->actions([
                // Сгенерировать пароль и выслать приглашение. Работает и для старых
                // карточек без аккаунта, и для сброса доступа существующему.
                \Filament\Tables\Actions\Action::make('invite')
                    ->label('Доступ')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (Teacher $record): bool => filled($record->email))
                    ->requiresConfirmation()
                    ->modalHeading('Сгенерировать пароль и отправить приглашение?')
                    ->modalDescription(fn (Teacher $record): string => 'Преподавателю '.$record->name.' ('.$record->email.') будет создан/обновлён аккаунт с новым паролем, а на почту уйдёт письмо со ссылкой на вход в панель.')
                    ->modalSubmitActionLabel('Сгенерировать и отправить')
                    ->action(function (Teacher $record): void {
                        $user = app(TeacherAccountService::class)->resetPasswordAndInvite($record);

                        if (! $user) {
                            \Filament\Notifications\Notification::make()
                                ->title('У карточки не указан email')
                                ->body('Добавьте email преподавателю, чтобы выслать доступ.')
                                ->danger()
                                ->send();

                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Приглашение отправлено')
                            ->body('Новый пароль и ссылка на вход высланы на '.$user->email)
                            ->success()
                            ->send();
                    }),

                \Filament\Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\BulkActionGroup::make([
                    // Массовая выдача доступов: сгенерировать пароли и разослать
                    // приглашения сразу нескольким преподавателям.
                    \Filament\Tables\Actions\BulkAction::make('invite')
                        ->label('Выдать доступ и выслать письмо')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Выдать доступ выбранным преподавателям?')
                        ->modalDescription('Каждому из выбранных будет создан/обновлён аккаунт с новым паролем и отправлено письмо-приглашение. Карточки без email будут пропущены.')
                        ->modalSubmitActionLabel('Сгенерировать и разослать')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $service = app(TeacherAccountService::class);
                            $sent = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Teacher $record */
                                $service->resetPasswordAndInvite($record) ? $sent++ : $skipped++;
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Готово')
                                ->body("Отправлено приглашений: {$sent}".($skipped > 0 ? " · Пропущено без email: {$skipped}" : ''))
                                ->success()
                                ->send();
                        }),

                    \Filament\Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeachers::route('/'),
            'create' => Pages\CreateTeacher::route('/create'),
            'edit' => Pages\EditTeacher::route('/{record}/edit'),
        ];
    }
}
