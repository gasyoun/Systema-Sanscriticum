<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Filament\Notifications\Notification;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Современная иконка для бокового меню
    protected static ?string $navigationIcon = 'heroicon-o-users'; 
    protected static ?int $navigationSort = 50;
    protected static ?string $navigationGroup = 'Пользователи';
    protected static ?string $navigationLabel = 'Студенты';
    protected static ?string $pluralModelLabel = 'Студенты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->description('Личные данные студента и параметры входа')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),
                            
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),

                        // --- НОВОЕ ПОЛЕ: Телефон ---
                        Forms\Components\TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(255),
                            
                        Forms\Components\TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                    ])->columns(2),

                // --- НОВЫЙ БЛОК: Статус и Примечания ---
                Forms\Components\Section::make('Дополнительная информация')
                    ->schema([
                        Forms\Components\Select::make('global_status')
                            ->label('Глобальный статус')
                            ->options([
                                'Обычный студент' => 'Обычный студент',
                                'Техподдержка' => 'Техподдержка',
                                'VIP' => 'VIP',
                                'Занимается бесплатно' => 'Занимается бесплатно',
                                'Бартер' => 'Бартер',
                            ])
                            ->default('Обычный студент')
                            ->required(),

                        Forms\Components\Textarea::make('note')
                            ->label('Примечание куратора')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Обучение и Права')
                    ->schema([
                        Forms\Components\Select::make('groups')
                            ->label('Программы / Доступы')
                            ->multiple()
                            ->relationship('groups', 'name')
                            ->preload(),
                        
                        Forms\Components\Toggle::make('is_admin')
                            ->label('Права администратора')
                            ->helperText('Дает полный доступ в панель управления')
                            ->onColor('success')
                            ->offColor('danger')
                            ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),  
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. АВАТАРКА (Генерируется автоматически из имени)
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (User $record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&color=FFFFFF&background=E85C24&bold=true')
                    ->circular()
                    ->size(40)
                    ->grow(false), 

                // 2. ИМЯ И EMAIL (Крупнее и чище)
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->searchable(['name', 'email', 'phone']) // Добавили поиск по телефону
                    ->sortable()
                    ->weight('bold')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Medium) 
                    ->description(fn (User $record): string => $record->email)
                    ->copyable()
                    ->copyMessage('Данные скопированы'),

                // --- НОВАЯ КОЛОНКА: Статус (с красивыми цветами) ---
                Tables\Columns\TextColumn::make('global_status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'VIP' => 'warning',
                        'Техподдержка' => 'info',
                        'Занимается бесплатно' => 'success',
                        'Бартер' => 'primary',
                        default => 'gray',
                    })
                    ->searchable(),
                    
                // 3. ДОСТУПЫ (Нейтральные современные бейджи)
                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Доступы')
                    ->badge()
                    ->color('gray') 
                    ->separator(' • ')
                    ->searchable()
                    ->placeholder('—'), 

                // 4. МЕССЕНДЖЕРЫ (Умное отображение)
                Tables\Columns\ColumnGroup::make('Мессенджеры', [
                    Tables\Columns\TextColumn::make('telegram_id')
                        ->label('Telegram')
                        ->formatStateUsing(fn ($state) => $state ? 'Подключен' : '—')
                        ->badge(fn ($state): bool => filled($state)) 
                        ->color('info')
                        ->icon(fn ($state) => $state ? 'heroicon-m-paper-airplane' : null)
                        ->alignment('center'),

                    Tables\Columns\TextColumn::make('vk_id')
                        ->label('ВКонтакте')
                        ->formatStateUsing(fn ($state) => $state ? 'Подключен' : '—')
                        ->badge(fn ($state): bool => filled($state)) 
                        ->color('info') 
                        ->icon(fn ($state) => $state ? 'heroicon-m-chat-bubble-oval-left-ellipsis' : null)
                        ->alignment('center'),
                ]),

                // --- НОВАЯ КОЛОНКА: Телефон (скрыта по умолчанию, чтобы не засорять экран) ---
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Админ')
                    ->boolean()
                    ->alignment('center')
                    ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),  
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                // --- НОВЫЙ ФИЛЬТР ПО СТАТУСУ ---
                Tables\Filters\SelectFilter::make('global_status')
                    ->label('Статус студента')
                    ->options([
                        'Обычный студент' => 'Обычный студент',
                        'Техподдержка' => 'Техподдержка',
                        'VIP' => 'VIP',
                        'Занимается бесплатно' => 'Занимается бесплатно',
                        'Бартер' => 'Бартер',
                    ]),

                Tables\Filters\Filter::make('has_telegram')
                    ->label('Есть Telegram-бот')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('telegram_id')),

                Tables\Filters\Filter::make('has_vk')
                    ->label('Есть ВК-бот')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('vk_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Редактировать'),
                
                Tables\Actions\Action::make('send_password')
                    ->iconButton()
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->tooltip('Сбросить и выслать пароль')
                    ->requiresConfirmation()
                    ->modalHeading('Выслать новый пароль?')
                    ->modalDescription('Текущий пароль студента будет сброшен. Новый случайный пароль будет немедленно отправлен ему на почту.')
                    ->modalSubmitActionLabel('Да, выслать')
                    ->action(function (User $record) {
                        $newPassword = Str::random(8);
                        
                        $record->update([
                            'password' => Hash::make($newPassword)
                        ]);

                        $emailText = "Намасте, {$record->name}!\n\nВаш пароль для доступа к личному кабинету Академии был сброшен администратором.\n\nВаш новый пароль: {$newPassword}\n\nС уважением,\nАкадемия Санскрита.";
                        
                        Mail::raw($emailText, function ($message) use ($record) {
                            $message->to($record->email)
                                    ->subject('Ваш новый пароль от личного кабинета');
                        });

                        Notification::make()
                            ->title('Новый пароль успешно отправлен на почту студента!')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    // --- НОВАЯ КНОПКА: МАССОВАЯ РАССЫЛКА ДОСТУПОВ ---
                    Tables\Actions\BulkAction::make('send_bulk_access')
                        ->label('Разослать доступы')
                        ->icon('heroicon-o-envelope')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Разослать доступы выбранным студентам?')
                        ->modalDescription('Система сгенерирует уникальные пароли и отправит письма. Студенты, которым доступ уже отправлялся (есть отметка в примечании), будут пропущены для защиты от спама.')
                        ->modalSubmitActionLabel('Да, отправить')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $sentCount = 0;
                            $skippedCount = 0;

                            foreach ($records as $record) {
                                // ЗАЩИТА ОТ СПАМА: Если в примечании уже есть штамп, пропускаем студента
                                if (str_contains($record->note ?? '', '[Доступ отправлен')) {
                                    $skippedCount++;
                                    continue;
                                }

                                // 1. Генерируем новый пароль
                                $newPassword = Str::random(8);
                                
                                // 2. Сохраняем пароль и ставим штамп в примечание
                                $record->update([
                                    'password' => Hash::make($newPassword),
                                    'note' => trim($record->note . "\n\n[Доступ отправлен: " . now()->format('d.m.Y H:i') . "]")
                                ]);

                                // 3. Отправляем письмо
                                $emailText = "Намасте, {$record->name}!\n\nВаш доступ к личному кабинету обучающей платформы открыт.\n\nСсылка для входа: " . url('/login') . "\nВаш логин (email): {$record->email}\nВаш пароль: {$newPassword}\n\nС уважением,\nКоманда Академии Санскрита.";

                                Mail::raw($emailText, function ($message) use ($record) {
                                    $message->to($record->email)
                                            ->subject('Ваш доступ к обучающей платформе');
                                });

                                $sentCount++;
                            }

                            // Показываем куратору красивый отчет в углу экрана
                            Notification::make()
                                ->title('Рассылка завершена!')
                                ->body("Успешно отправлено: {$sentCount} шт.\nПропущено (уже отправлялось): {$skippedCount} шт.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // ВОТ ЗДЕСЬ ИСПРАВЛЕНИЕ: добавили UserResource\
            UserResource\RelationManagers\CoursesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            // --- ДОБАВЛЯЕМ МАРШРУТ ДЛЯ СОЗДАННОЙ СТРАНИЦЫ ---
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}