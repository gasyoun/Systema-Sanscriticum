<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\TeacherResource\Pages;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherAccountService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class TeacherResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Teacher::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Личные данные')
                    ->schema([
                        TextInput::make('name')
                            ->label('ФИО Преподавателя')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? 'Будет логином преподавателя в админке.'
                                : null)
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel(),
                        FileUpload::make('photo_path')
                            ->label('Фото (для страницы курса)')
                            ->image()
                            ->directory('teachers')
                            ->imageEditor()
                            ->maxSize(4096)
                            ->helperText('Показывается в блоке «Преподаватель» на продающей странице курса.')
                            ->columnSpanFull(),
                    ])->columns(3),

                Section::make('Аккаунт для входа в админку')
                    ->description('Создаётся автоматически с ролью «Преподаватель». Email из блока выше будет логином. После сохранения преподавателю уходит письмо-приглашение с доступами.')
                    ->schema([
                        TextInput::make('account_password')
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
                    ->visible(fn (?Teacher $record, string $operation) => $operation === 'create' || ($record && $record->email)
                    )
                    ->columns(1),

                Section::make('Каникулы / отпуск')
                    ->description('H4253: окно покрывает все группы преподавателя — аннотация «выход из каникул» в публичном фиде, напоминания @zapisi_ORSbot в окне не уходят. Преподаватель может выставить то же сам командой в Telegram-чате группы.')
                    ->schema([
                        DatePicker::make('on_vacation_from')
                            ->label('Начало каникул'),
                        DatePicker::make('on_vacation_until')
                            ->label('Конец каникул')
                            ->helperText('Пусто = дата выхода неизвестна («уточняется») — окно бессрочно до снятия.'),
                    ])
                    ->columns(2),

                Section::make('Соцсети и Реквизиты')
                    ->schema([
                        TextInput::make('telegram')
                            ->label('Telegram (Ник)'),
                        TextInput::make('vk')
                            ->label('ВКонтакте (Ссылка)'),
                        Textarea::make('requisites')
                            ->label('Реквизиты для выплаты ЗП')
                            ->columnSpanFull(),
                        Select::make('payout_currency')
                            ->label('Валюта выплаты (PayPal)')
                            ->options([
                                'EUR' => 'Евро (€)',
                                'USD' => 'Доллары ($)',
                                'INR' => 'Рупии (₹)',
                            ])
                            ->placeholder('Рубли (по умолчанию)')
                            ->helperText('В какой валюте преподаватель получает на PayPal. Остаток в таблице всегда в рублях: перевод делаем в ₽.'),
                        RichEditor::make('bio')
                            ->label('Биография / Регалии')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('telegram')
                    ->label('Telegram')
                    ->icon('heroicon-m-paper-airplane'),

                TextColumn::make('courses_count')
                    ->counts('courses')
                    ->label('Курсов')
                    ->badge()
                    ->color('info'),

                // Есть ли у карточки активный аккаунт для входа. Старые карточки,
                // заведённые до авто-аккаунтов, висят без доступа — их видно по «нет».
                IconColumn::make('has_account')
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
                TextColumn::make('balance')
                    ->label('Сейчас к выплате')
                    ->tooltip('Остаток: начислено за всё время минус уже выплачено. Это не годовой заработок и не «получил на руки». Учёт всегда в рублях; PayPal может быть в другой валюте.')
                    ->state(function (Teacher $record) {
                        $earned = $record->calculateEarnings();
                        $paid = $record->payouts()->sum('amount');
                        $owed = number_format($earned - $paid, 0, '.', ' ').' ₽';
                        $paypal = $record->payout_currency
                            ? ' · PayPal '.TeacherPayout::currencySymbol($record->payout_currency)
                            : '';

                        return $owed.$paypal;
                    })
                    ->badge()
                    ->color(fn (string $state) => str_contains($state, '-') || str_starts_with($state, '0 ₽') ? 'success' : 'warning')
                    ->icon('heroicon-m-wallet')
                    ->action(
                        // ДЕЙСТВИЕ ПРИ КЛИКЕ: Открываем окно статистики и выплат
                        Action::make('manage_finances')
                            ->modalHeading(fn (Teacher $record) => 'Финансы: '.$record->name)
                            ->modalWidth('md')
                            ->form([
                                // Интерактивная статистика с фильтрами
                                Section::make('Детальная статистика')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                DatePicker::make('filter_start')
                                                    ->label('От даты')
                                                    ->default(now()->startOfMonth())
                                                    ->live(),

                                                DatePicker::make('filter_end')
                                                    ->label('До даты')
                                                    ->default(now()->endOfMonth())
                                                    ->live(),
                                            ]),

                                        Placeholder::make('custom_period_stats')
                                            ->label('📊 Заработано за выбранный период:')
                                            ->content(function (Get $get, Teacher $record) {
                                                $start = $get('filter_start');
                                                $end = $get('filter_end');

                                                if (! $start || ! $end) {
                                                    return 'Выберите даты';
                                                }

                                                $earned = $record->calculateEarnings(
                                                    Carbon::parse($start)->startOfDay(),
                                                    Carbon::parse($end)->endOfDay()
                                                );

                                                return number_format($earned, 0, '.', ' ').' ₽';
                                            }),
                                    ]),

                                // Общие цифры за всё время
                                Section::make('Общие показатели')
                                    ->schema([
                                        Placeholder::make('total_stats')
                                            ->label('💰 Заработано за всё время:')
                                            ->content(fn (Teacher $record) => number_format($record->calculateEarnings(), 0, '.', ' ').' ₽'
                                            ),

                                        Placeholder::make('paid_stats')
                                            ->label('✅ Уже выплачено вами:')
                                            ->content(fn (Teacher $record) => number_format($record->payouts()->sum('amount'), 0, '.', ' ').' ₽'
                                            ),
                                    ])->columns(2),

                                // Блок 3: Форма для новой выплаты
                                Section::make('Зафиксировать новую выплату')
                                    ->description('Внесите сюда сумму, которую вы перевели преподавателю.')
                                    ->schema([
                                        TextInput::make('payout_amount')
                                            ->label('Сумма выплаты (₽)')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('comment')
                                            ->label('Комментарий (необязательно)')
                                            ->placeholder('Например: ЗП за март'),
                                    ]),
                            ])
                            // Что делаем, когда админ нажал "Сохранить"
                            ->action(function (Teacher $record, array $data) {
                                $record->payouts()->create([
                                    'amount' => $data['payout_amount'],
                                    'comment' => $data['comment'],
                                ]);
                                Notification::make()
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
                Action::make('invite')
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
                            Notification::make()
                                ->title('У карточки не указан email')
                                ->body('Добавьте email преподавателю, чтобы выслать доступ.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Приглашение отправлено')
                            ->body('Новый пароль и ссылка на вход высланы на '.$user->email)
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Массовая выдача доступов: сгенерировать пароли и разослать
                    // приглашения сразу нескольким преподавателям.
                    BulkAction::make('invite')
                        ->label('Выдать доступ и выслать письмо')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Выдать доступ выбранным преподавателям?')
                        ->modalDescription('Каждому из выбранных будет создан/обновлён аккаунт с новым паролем и отправлено письмо-приглашение. Карточки без email будут пропущены.')
                        ->modalSubmitActionLabel('Сгенерировать и разослать')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $service = app(TeacherAccountService::class);
                            $sent = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Teacher $record */
                                $service->resetPasswordAndInvite($record) ? $sent++ : $skipped++;
                            }

                            Notification::make()
                                ->title('Готово')
                                ->body("Отправлено приглашений: {$sent}".($skipped > 0 ? " · Пропущено без email: {$skipped}" : ''))
                                ->success()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
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
