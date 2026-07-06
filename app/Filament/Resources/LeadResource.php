<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeadResource\Pages;
use App\Jobs\SendLeadMessenger;
use App\Mail\LeadAdHocMail;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
// --- ИМПОРТЫ ДЛЯ EXCEL (ВАЖНО!) ---
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Заявки (Лиды)';

    protected static ?string $pluralModelLabel = 'Заявки';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Имя'),
                        Forms\Components\TextInput::make('contact')->label('Телефон / TG'),
                        Forms\Components\TextInput::make('email')->label('Email')->email(),
                        Forms\Components\Select::make('landing_page_id')
                            ->relationship('landingPage', 'title')
                            ->label('Лендинг'),

                        Forms\Components\TextInput::make('social')
                            ->label('Telegram / VK / Instagram')
                            ->maxLength(255)
                            ->placeholder('@username или ссылка')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_promo_agreed')
                            ->label('Согласие на рассылку'),

                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(Lead::STATUSES)
                            ->default('new'),

                        Forms\Components\Select::make('assigned_to')
                            ->label('Ответственный')
                            ->options(fn () => self::staffOptions())
                            ->searchable(),

                        Forms\Components\DatePicker::make('next_contact_at')
                            ->label('Следующий контакт')
                            ->native(false),
                    ])->columns(2),

                Forms\Components\Section::make('Маркетинг и Аналитика')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('utm_source')->label('Source'),
                            Forms\Components\TextInput::make('utm_medium')->label('Medium'),
                            Forms\Components\TextInput::make('utm_campaign')->label('Campaign'),
                            Forms\Components\TextInput::make('utm_content')->label('Content'),
                            Forms\Components\TextInput::make('utm_term')->label('Term'),
                            Forms\Components\TextInput::make('click_id')->label('Click ID'),
                        ]),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('ip_address')->label('IP'),
                            Forms\Components\TextInput::make('referrer')->label('Referrer'),
                        ]),
                        Forms\Components\Textarea::make('user_agent')->label('UA')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('landingPage.title')
                    ->label('Лендинг')
                    ->searchable()
                    ->limit(20),

                Tables\Columns\SelectColumn::make('status')
                    ->label('Статус')
                    ->options(Lead::STATUSES)
                    ->selectablePlaceholder(false)
                    ->width('1%'),

                Tables\Columns\IconColumn::make('converted_at')
                    ->label('Оплата')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->converted_at
                        ? 'Оплатил: '.$record->converted_at->format('d.m.Y H:i')
                        : 'Не оплатил')
                    ->sortable(),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Ответственный')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                Tables\Columns\TextColumn::make('contact')
                    ->label('Контакты')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('social')
                    ->label('Соц. сеть')
                    ->searchable()
                    ->limit(30)
                    ->copyable()
                    ->copyMessage('Скопировано')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('magnet_channel')
                    ->label('Канал')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'telegram' => 'info',
                        'vk' => 'danger',
                        'max' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'telegram' => 'TG',
                        'vk' => 'VK',
                        'max' => 'Max',
                        default => '—',
                    })
                    ->toggleable(),

                Tables\Columns\IconColumn::make('magnet_delivered_at')
                    ->label('Магнит')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->magnet_delivered_at
                        ? 'Доставлен: '.$record->magnet_delivered_at->format('d.m.Y H:i')
                        : 'Не доставлен')
                    ->toggleable(),

                // В LeadResource.php -> table() -> columns([...])
                Tables\Columns\IconColumn::make('is_promo_agreed')
                    ->label('Рассылка')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),

                Tables\Columns\TextColumn::make('utm_source')
                    ->label('Источник')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'yandex' => 'warning',
                        'vk', 'vkontakte' => 'info',
                        'google' => 'success',
                        'tg', 'telegram' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('landing_page_id')
                    ->label('Лендинг')
                    ->relationship('landingPage', 'title')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(Lead::STATUSES),

                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Ответственный')
                    ->relationship('assignee', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('converted')
                    ->label('Оплата')
                    ->placeholder('Все заявки')
                    ->trueLabel('Оплатившие')
                    ->falseLabel('Без оплаты')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('converted_at'),
                        false: fn ($query) => $query->whereNull('converted_at'),
                        blank: fn ($query) => $query,
                    ),

                // Лиды без привязки к лендингу (лид-магниты статей и т.п.)
                Tables\Filters\TernaryFilter::make('has_landing')
                    ->label('Есть лендинг')
                    ->placeholder('Все заявки')
                    ->trueLabel('С лендингом')
                    ->falseLabel('Без лендинга')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('landing_page_id'),
                        false: fn ($query) => $query->whereNull('landing_page_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                self::emailAction(),
                self::messengerAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    self::bulkSetStatusAction(),
                    self::bulkAssignAction(),
                    self::bulkMessengerAction(),
                    Tables\Actions\DeleteBulkAction::make(),

                    // === НАСТРОЙКА ПОЛНОЙ ВЫГРУЗКИ ===
                    ExportBulkAction::make()
                        ->label('Скачать полный отчет (Excel)')
                        ->exports([
                            ExcelExport::make()
                                ->withFilename('leads_full_'.date('Y-m-d'))
                                ->withColumns([
                                    // 1. Основное
                                    Column::make('id')->heading('ID'),
                                    Column::make('created_at')->heading('Дата создания'),
                                    Column::make('landingPage.title')->heading('Лендинг'),
                                    Column::make('name')->heading('Имя'),
                                    Column::make('contact')->heading('Телефон'),
                                    Column::make('email')->heading('Email'),

                                    // 2. Галочки (Форматируем True/False в Да/Нет)
                                    Column::make('is_promo_agreed')
                                        ->heading('Рассылка?')
                                        ->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет'),

                                    // 3. Маркетинг (UTM)
                                    Column::make('utm_source')->heading('Источник (Source)'),
                                    Column::make('utm_medium')->heading('Тип (Medium)'),
                                    Column::make('utm_campaign')->heading('Кампания'),
                                    Column::make('utm_content')->heading('Объявление'),
                                    Column::make('utm_term')->heading('Ключевое слово'),

                                    // 4. Технические данные
                                    Column::make('click_id')->heading('Click ID'),
                                    Column::make('ip_address')->heading('IP адрес'),
                                    Column::make('referrer')->heading('Пришел с сайта'),
                                    Column::make('user_agent')->heading('Информация об устройстве'),
                                ]),
                        ]),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LeadResource\RelationManagers\NotesRelationManager::class,
            LeadResource\RelationManagers\AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }

    /**
     * Сотрудники, которым можно назначить лид (админы и менеджеры).
     *
     * @return array<int,string>
     */
    protected static function staffOptions(): array
    {
        return User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Текст с плейсхолдером {name} → подставляем имя лида.
     */
    protected static function renderTemplate(string $template, Lead $lead): string
    {
        return strtr($template, ['{name}' => $lead->name ?: 'Друг']);
    }

    /**
     * Row-action: отправить лиду произвольное письмо на email.
     */
    protected static function emailAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('email_lead')
            ->label('Письмо')
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->visible(fn (Lead $r): bool => filled($r->email))
            ->modalHeading(fn (Lead $r): string => 'Письмо лиду — '.($r->name ?: $r->email))
            ->form([
                Forms\Components\TextInput::make('subject')
                    ->label('Тема')
                    ->required()
                    ->maxLength(255),
                Forms\Components\RichEditor::make('body')
                    ->label('Текст письма')
                    ->required()
                    ->toolbarButtons([
                        'bold', 'italic', 'underline', 'strike',
                        'h2', 'h3', 'bulletList', 'orderedList',
                        'link', 'blockquote', 'undo', 'redo',
                    ])
                    ->helperText('Плейсхолдер: {name}.'),
            ])
            ->action(function (Lead $r, array $data): void {
                $body = self::renderTemplate((string) $data['body'], $r);
                $subject = (string) $data['subject'];

                Mail::to($r->email)->send(new LeadAdHocMail($subject, $body, $r->name ?: null));

                LeadNote::create([
                    'lead_id' => $r->id,
                    'user_id' => auth()->id(),
                    'type' => LeadNote::TYPE_EMAIL,
                    'channel' => 'email',
                    'subject' => $subject,
                    // В таймлайн пишем текст без HTML-разметки — так читабельнее.
                    'body' => trim(strip_tags($body)),
                ]);

                Notification::make()->title('Письмо поставлено в очередь')->success()->send();
            });
    }

    /**
     * Доступные каналы мессенджеров у лида (есть ли собранный id).
     *
     * @return list<string>
     */
    protected static function availableChannels(Lead $lead): array
    {
        $channels = [];
        if (! empty($lead->telegram_chat_id)) {
            $channels[] = 'telegram';
        }
        if (! empty($lead->vk_user_id)) {
            $channels[] = 'vk';
        }
        if (! empty($lead->max_user_id)) {
            $channels[] = 'max';
        }

        return $channels;
    }

    /**
     * Row-action: написать лиду в мессенджер (TG/VK/Max).
     */
    protected static function messengerAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('message_lead')
            ->label('Сообщение')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Lead $r): bool => self::availableChannels($r) !== [])
            ->modalHeading(fn (Lead $r): string => 'Сообщение лиду — '.($r->name ?: $r->email))
            ->modalDescription('Отправится в выбранные мессенджеры, где у лида есть аккаунт. Плейсхолдер: {name}.')
            ->form(fn (Lead $r): array => [
                Forms\Components\Textarea::make('text')
                    ->label('Текст')
                    ->rows(6)
                    ->required(),
                Forms\Components\CheckboxList::make('channels')
                    ->label('Каналы')
                    ->options([
                        'telegram' => 'Telegram',
                        'vk' => 'VK',
                        'max' => 'Max',
                    ])
                    ->default(self::availableChannels($r))
                    ->disableOptionWhen(fn (string $value): bool => ! in_array($value, self::availableChannels($r), true))
                    ->required(),
            ])
            ->action(function (Lead $r, array $data): void {
                $channels = array_values(array_intersect(
                    (array) ($data['channels'] ?? []),
                    self::availableChannels($r),
                ));

                if ($channels === []) {
                    Notification::make()->title('Нет доступных каналов')->danger()->send();

                    return;
                }

                SendLeadMessenger::dispatch(
                    $r->id,
                    self::renderTemplate((string) $data['text'], $r),
                    $channels,
                    auth()->id(),
                );

                Notification::make()->title('Сообщение поставлено в очередь')->success()->send();
            });
    }

    /**
     * Bulk: сменить статус выбранным лидам.
     */
    protected static function bulkSetStatusAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('set_status')
            ->label('Сменить статус')
            ->icon('heroicon-o-flag')
            ->color('gray')
            ->form([
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options(Lead::STATUSES)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $records->each->update(['status' => $data['status']]);
                Notification::make()->title('Статус обновлён: '.$records->count())->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk: назначить ответственного выбранным лидам.
     */
    protected static function bulkAssignAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('assign')
            ->label('Назначить ответственного')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->form([
                Forms\Components\Select::make('assigned_to')
                    ->label('Ответственный')
                    ->options(fn () => self::staffOptions())
                    ->searchable(),
            ])
            ->action(function (Collection $records, array $data): void {
                $records->each->update(['assigned_to' => $data['assigned_to'] ?? null]);
                Notification::make()->title('Назначено: '.$records->count())->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk: разослать сообщение в мессенджер выбранным лидам.
     */
    protected static function bulkMessengerAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('bulk_message')
            ->label('Сообщение в мессенджер')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalDescription('Отправится тем лидам, у кого есть аккаунт в выбранных каналах. Плейсхолдер: {name}.')
            ->form([
                Forms\Components\Textarea::make('text')
                    ->label('Текст')
                    ->rows(6)
                    ->required(),
                Forms\Components\CheckboxList::make('channels')
                    ->label('Каналы')
                    ->options([
                        'telegram' => 'Telegram',
                        'vk' => 'VK',
                        'max' => 'Max',
                    ])
                    ->default(['telegram', 'vk', 'max'])
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $requested = (array) ($data['channels'] ?? []);
                $sent = 0;
                $noChannel = 0;

                foreach ($records as $record) {
                    /** @var Lead $record */
                    $channels = array_values(array_intersect($requested, self::availableChannels($record)));
                    if ($channels === []) {
                        $noChannel++;

                        continue;
                    }

                    SendLeadMessenger::dispatch(
                        $record->id,
                        self::renderTemplate((string) $data['text'], $record),
                        $channels,
                        auth()->id(),
                    );
                    $sent++;
                }

                Notification::make()
                    ->title('Поставлено в очередь: '.$sent)
                    ->body($noChannel > 0 ? "Без каналов пропущено: {$noChannel}" : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
