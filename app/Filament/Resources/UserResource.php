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
    protected static ?string $navigationLabel = 'Студенты';

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
                            
                        Forms\Components\TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
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
                    ->grow(false), // Запрещаем колонке растягиваться

                // 2. ИМЯ И EMAIL (Крупнее и чище)
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->weight('bold')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Medium) // Чуть увеличенный шрифт
                    ->description(fn (User $record): string => $record->email)
                    ->copyable()
                    ->copyMessage('Данные скопированы'),
                    
                // 3. ДОСТУПЫ (Нейтральные современные бейджи)
                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Доступы')
                    ->badge()
                    ->color('gray') // Нейтральный цвет не отвлекает внимание
                    ->separator(' • ')
                    ->searchable()
                    ->placeholder('—'), // Если курсов нет, показываем тире

                // 4. МЕССЕНДЖЕРЫ (Умное отображение)
                Tables\Columns\ColumnGroup::make('Мессенджеры', [
                    Tables\Columns\TextColumn::make('telegram_id')
                        ->label('Telegram')
                        ->formatStateUsing(fn ($state) => $state ? 'Подключен' : '—')
                        ->badge(fn ($state): bool => filled($state)) // Бейдж ТОЛЬКО если бот подключен
                        ->color('info')
                        ->icon(fn ($state) => $state ? 'heroicon-m-paper-airplane' : null)
                        ->alignment('center'),

                    Tables\Columns\TextColumn::make('vk_id')
                        ->label('ВКонтакте')
                        ->formatStateUsing(fn ($state) => $state ? 'Подключен' : '—')
                        ->badge(fn ($state): bool => filled($state)) // Бейдж ТОЛЬКО если бот подключен
                        ->color('info') // В Filament info - это приятный синий цвет
                        ->icon(fn ($state) => $state ? 'heroicon-m-chat-bubble-oval-left-ellipsis' : null)
                        ->alignment('center'),
                ]),
                    
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Админ')
                    ->boolean()
                    ->alignment('center')
                    ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),    
            ])
            ->defaultSort('id', 'desc')
            ->filters([
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
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}