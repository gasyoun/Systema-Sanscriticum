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
    // --- КОЛОНКА 1: СТУДЕНТ ---
    // name основное, email и id — подписи под ним
    Tables\Columns\TextColumn::make('name')
        ->label('Студент')
        ->searchable()
        ->sortable()
        ->weight('bold')
        ->description(fn ($record) => $record->email . ' · ID: ' . $record->id)
        ->wrap(),

    // --- КОЛОНКА 2: КОНТАКТЫ ---
    // Телефон + мелкие иконки TG/VK под ним
    Tables\Columns\TextColumn::make('phone')
        ->label('Контакты')
        ->copyable()
        ->copyMessage('Телефон скопирован')
        ->icon('heroicon-m-phone')
        ->iconColor('gray')
        ->placeholder('—')
        ->description(function ($record): string {
            $tg = $record->telegram_id ? '✈ TG' : '';
            $vk = $record->vk_id ? '💬 VK' : '';
            $parts = array_filter([$tg, $vk]);
            return !empty($parts) ? implode(' · ', $parts) : 'Нет мессенджеров';
        }),

    // --- КОЛОНКА 3: СТАТУС ---
    Tables\Columns\TextColumn::make('global_status')
        ->label('Статус')
        ->badge()
        ->color(fn (string $state): string => match ($state) {
            'VIP'                   => 'warning',
            'Техподдержка'          => 'danger',
            'Занимается бесплатно'  => 'info',
            'Бартер'                => 'info',
            default                 => 'success',
        })
        ->searchable()
        ->sortable(),

    // --- КОЛОНКА 4: АКТИВНОСТЬ ---
    // last_activity_at основное, мелкая подстрочная статистика под ним
    Tables\Columns\TextColumn::make('last_activity_at')
        ->label('Последний визит')
        ->sortable()
        ->formatStateUsing(function ($state): string {
            if ($state === null) return 'Никогда';
            return \Carbon\Carbon::parse($state)->diffForHumans();
        })
        ->tooltip(fn ($state): ?string => $state
            ? \Carbon\Carbon::parse($state)->translatedFormat('d.m.Y H:i:s')
            : null)
        ->color(function ($state): string {
            if ($state === null) return 'gray';
            $d = \Carbon\Carbon::parse($state);
            if ($d->gt(now()->subMinutes(5))) return 'success';
            if ($d->gt(now()->subHour()))    return 'warning';
            if ($d->gt(now()->subDays(7)))   return 'gray';
            return 'danger';
        })
        ->icon(function ($state): string {
            if ($state === null) return 'heroicon-m-minus-circle';
            $d = \Carbon\Carbon::parse($state);
            if ($d->gt(now()->subMinutes(5))) return 'heroicon-m-signal';
            return 'heroicon-m-clock';
        })
        ->weight('medium')
        ->description(function ($record): string {
            $lessons = (int) $record->total_lessons_opened;
            $seconds = (int) $record->total_time_spent;
            $visits  = (int) $record->login_count;

            $hours = intdiv($seconds, 3600);
            $mins  = intdiv($seconds % 3600, 60);
            $time  = $hours > 0 ? "{$hours}ч {$mins}м" : "{$mins}м";

            return "📚 {$lessons} · ⏱ {$time} · 🔑 {$visits}";
        }),

    // --- КОЛОНКА 5: АДМИН (только для суперадмина) ---
    Tables\Columns\IconColumn::make('is_admin')
        ->label('Админ')
        ->boolean()
        ->alignment('center')
        ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),
])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([
                
                // --- НОВЫЙ ФИЛЬТР: Наличие настоящего email ---
    Tables\Filters\TernaryFilter::make('has_real_email')
        ->label('Email')
        ->placeholder('Все студенты')
        ->trueLabel('Только с настоящим email')
        ->falseLabel('Только с заглушкой @no-email.com')
        ->queries(
            true: fn (Builder $query) => $query->where('email', 'not like', '%@no-email.com'),
            false: fn (Builder $query) => $query->where('email', 'like', '%@no-email.com'),
            blank: fn (Builder $query) => $query, // показываем всех
        ),
        
        // --- НОВЫЙ ФИЛЬТР: Отправлено ли письмо с доступом ---
    Tables\Filters\TernaryFilter::make('access_sent')
        ->label('Письмо с доступом')
        ->placeholder('Все студенты')
        ->trueLabel('Доступ уже отправлен')
        ->falseLabel('Доступ ещё не отправлен')
        ->queries(
            true: fn (Builder $query) => $query->where('note', 'like', '%[Доступ отправлен%'),
            false: fn (Builder $query) => $query->where(function (Builder $q) {
                $q->whereNull('note')
                  ->orWhere('note', 'not like', '%[Доступ отправлен%');
            }),
            blank: fn (Builder $query) => $query,
        ),
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
                    
                // --- ФИЛЬТРЫ ПО АКТИВНОСТИ ---

Tables\Filters\Filter::make('online_now')
    ->label('Онлайн сейчас')
    ->query(fn (Builder $query): Builder => $query
        ->where('last_activity_at', '>=', now()->subMinutes(5))
    )
    ->indicator('Онлайн сейчас'),

Tables\Filters\Filter::make('active_today')
    ->label('Активные сегодня')
    ->query(fn (Builder $query): Builder => $query
        ->whereDate('last_activity_at', today())
    )
    ->indicator('Активные сегодня'),

Tables\Filters\Filter::make('inactive_7_days')
    ->label('Неактивные 7+ дней')
    ->query(fn (Builder $query): Builder => $query
        ->where(function (Builder $q) {
            $q->whereNull('last_activity_at')
              ->orWhere('last_activity_at', '<', now()->subDays(7));
        })
        ->whereNotNull('last_login_at') // только те, кто вообще заходил
    )
    ->indicator('Неактивные 7+ дней'),

Tables\Filters\Filter::make('inactive_30_days')
    ->label('Неактивные 30+ дней')
    ->query(fn (Builder $query): Builder => $query
        ->where(function (Builder $q) {
            $q->whereNull('last_activity_at')
              ->orWhere('last_activity_at', '<', now()->subDays(30));
        })
        ->whereNotNull('last_login_at')
    )
    ->indicator('Неактивные 30+ дней'),

Tables\Filters\Filter::make('never_logged_in')
    ->label('Никогда не заходили')
    ->query(fn (Builder $query): Builder => $query
        ->whereNull('last_login_at')
    )
    ->indicator('Никогда не заходили'),    
                    
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
    $email = trim((string) $record->email);

    // Ранняя проверка, чтобы не сбросить пароль на "битом" юзере
    if (
        $email === ''
        || str_ends_with($email, '@no-email.com')
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        Notification::make()
            ->title('Некорректный email')
            ->body("У студента указан невалидный адрес: «{$email}». Сначала обновите email в карточке.")
            ->danger()
            ->send();
        return;
    }

    $newPassword = Str::random(8);
    $emailText = "Намасте, {$record->name}!\n\n"
        . "Ваш пароль для доступа к личному кабинету Академии был сброшен администратором.\n\n"
        . "Ваш новый пароль: {$newPassword}\n\n"
        . "С уважением,\nОбщество ревнителей санскрита.";

    try {
        // Сначала — письмо, потом — обновление пароля
        Mail::raw($emailText, function ($message) use ($email) {
            $message->to($email)
                    ->subject('Ваш новый пароль от личного кабинета');
        });

        $record->update(['password' => Hash::make($newPassword)]);

        Notification::make()
            ->title('Новый пароль успешно отправлен на почту студента!')
            ->success()
            ->send();
    } catch (\Symfony\Component\Mime\Exception\RfcComplianceException $e) {
        Notification::make()
            ->title('Некорректный email')
            ->body("Symfony Mailer отклонил адрес «{$email}». Пароль не сброшен.")
            ->danger()
            ->send();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Send password failed', [
            'user_id' => $record->id,
            'email'   => $email,
            'error'   => $e->getMessage(),
        ]);
        Notification::make()
            ->title('Ошибка отправки письма')
            ->body('Пароль не сброшен. Подробности в логах: ' . $e->getMessage())
            ->danger()
            ->send();
    }
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
    $invalidEmails = []; // битые адреса
    $failedEmails  = []; // валидные с виду, но mailer упал

    foreach ($records as $record) {
        // 1. Защита от спама
        if (str_contains($record->note ?? '', '[Доступ отправлен')) {
            $skippedCount++;
            continue;
        }

        // 2. Отсекаем заглушки и заведомо невалидные адреса
        $email = trim((string) $record->email);

        if (
            $email === ''
            || str_ends_with($email, '@no-email.com')
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $invalidEmails[] = "#{$record->id} {$record->name} ({$email})";
            continue;
        }

        // 3. Генерируем пароль заранее, но НЕ сохраняем до успешной отправки
        $newPassword = \Illuminate\Support\Str::random(8);

        $emailText = "Намасте, {$record->name}!\n\n"
            . "Ваш доступ к личному кабинету обучающей платформы открыт.\n\n"
            . "Ссылка для входа: " . url('/login') . "\n"
            . "Ваш логин (email): {$email}\n"
            . "Ваш пароль: {$newPassword}\n\n"
            . "С уважением,\nКоманда Общества ревнителей санскрита.";

        try {
            // 4. Сначала пытаемся отправить письмо
            \Illuminate\Support\Facades\Mail::raw($emailText, function ($message) use ($email) {
                $message->to($email)
                        ->subject('Ваш доступ к обучающей платформе');
            });

            // 5. И только если почта ушла — сохраняем пароль и ставим штамп
            $record->update([
                'password' => \Illuminate\Support\Facades\Hash::make($newPassword),
                'note'     => trim(($record->note ?? '') . "\n\n[Доступ отправлен: " . now()->format('d.m.Y H:i') . "]"),
            ]);

            $sentCount++;
        } catch (\Symfony\Component\Mime\Exception\RfcComplianceException $e) {
            // RFC-невалидный адрес, который filter_var всё-таки пропустил
            $invalidEmails[] = "#{$record->id} {$record->name} ({$email})";
        } catch (\Throwable $e) {
            // SMTP упал, таймаут, что угодно — логируем и идём дальше
            \Illuminate\Support\Facades\Log::error('Bulk access mail failed', [
                'user_id' => $record->id,
                'email'   => $email,
                'error'   => $e->getMessage(),
            ]);
            $failedEmails[] = "#{$record->id} {$record->name} ({$email})";
        }
    }

    // 6. Собираем отчёт
    $body = "Успешно отправлено: {$sentCount} шт.\n"
          . "Пропущено (уже отправлялось): {$skippedCount} шт.\n"
          . "Некорректный email: " . count($invalidEmails) . " шт.\n"
          . "Ошибка отправки: " . count($failedEmails) . " шт.";

    if (!empty($invalidEmails)) {
        $body .= "\n\nНевалидные:\n" . implode("\n", array_slice($invalidEmails, 0, 10));
        if (count($invalidEmails) > 10) {
            $body .= "\n… и ещё " . (count($invalidEmails) - 10);
        }
    }

    \Filament\Notifications\Notification::make()
        ->title('Рассылка завершена')
        ->body($body)
        ->{ (count($invalidEmails) + count($failedEmails)) > 0 ? 'warning' : 'success' }()
        ->persistent() // чтобы куратор точно прочитал отчёт
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