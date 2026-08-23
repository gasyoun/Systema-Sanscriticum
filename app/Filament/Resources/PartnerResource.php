<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Pages;
use App\Filament\Resources\PartnerResource\RelationManagers\ConversionsRelationManager;
use App\Models\Partner;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Админка партнёрской (агентской) программы: модерация партнёров, ставка
 * вознаграждения, реквизиты выплат и сводка «клиентов / к выплате / выплачено».
 * Детальный учёт начислений — во вкладке «Клиенты и выплаты» (relation manager).
 */
class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 75;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Партнёры';

    protected static ?string $modelLabel = 'Партнёр';

    protected static ?string $pluralModelLabel = 'Партнёры';

    public static function canViewAny(): bool
    {
        // Прячем раздел целиком, пока программа выключена.
        return (bool) config('partner.enabled', false)
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Partner::where('status', Partner::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Партнёр')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Имя')->required()->maxLength(255),
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(Partner::STATUSES)
                        ->default(Partner::STATUS_PENDING)
                        ->required(),
                    Forms\Components\TextInput::make('telegram_username')->label('Telegram')->placeholder('@username'),
                    Forms\Components\TextInput::make('email')->label('Email')->email(),
                    Forms\Components\TextInput::make('phone')->label('Телефон'),
                    Forms\Components\TextInput::make('code')
                        ->label('Партнёрский код')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Генерируется автоматически при создании.'),
                ])->columns(2),

            Forms\Components\Section::make('Выплаты')
                ->schema([
                    Forms\Components\TextInput::make('reward_amount_override')
                        ->label('Индивидуальная ставка, ₽')
                        ->numeric()
                        ->helperText('Пусто — используется глобальная ставка из config/partner.php ('.(int) config('partner.reward_amount', 1000).' ₽).'),
                    Forms\Components\TextInput::make('reward_percent')
                        ->label('Процент первого платежа, %')
                        ->numeric()
                        ->minValue(0)->maxValue(100)->step(0.1)
                        ->helperText('Ратифицировано MG 23-08 (схема А: 10 %). Заполненный процент приоритетнее фикс-ставки.'),
                    Forms\Components\TextInput::make('payout_method')->label('Способ выплаты')->placeholder('карта / СБП'),
                    Forms\Components\Textarea::make('payout_details')->label('Реквизиты')->rows(2)->columnSpanFull(),
                    Forms\Components\Textarea::make('notes')->label('Заметки')->rows(2)->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Имя')->searchable()->sortable(),

                Tables\Columns\TextColumn::make('code')->label('Код')->searchable()->copyable()->fontFamily('mono'),

                Tables\Columns\TextColumn::make('telegram_username')->label('Telegram')->searchable()->placeholder('—')->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Partner::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Partner::STATUS_ACTIVE => 'success',
                        Partner::STATUS_PENDING => 'warning',
                        Partner::STATUS_SUSPENDED => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversions_count')
                    ->label('Клиентов')
                    ->counts('conversions')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('owed')
                    ->label('К выплате, ₽')
                    ->state(fn (Partner $r): string => number_format($r->amountOwed(), 0, '.', ' '))
                    ->badge()
                    ->color(fn (Partner $r): string => $r->amountOwed() > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('paid_out')
                    ->label('Выплачено, ₽')
                    ->state(fn (Partner $r): string => number_format($r->amountPaidOut(), 0, '.', ' '))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')->label('Заявка')->dateTime('d.m.Y')->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(Partner::STATUSES),
            ])
            ->actions([
                self::activateAction(),
                self::suspendAction(),
                Tables\Actions\EditAction::make(),
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
            ConversionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
        ];
    }

    /** Активировать партнёра (модерация пройдена): статус active + approved_at. */
    protected static function activateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('activate')
            ->label('Активировать')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Partner $r): bool => $r->status !== Partner::STATUS_ACTIVE)
            ->requiresConfirmation()
            ->action(function (Partner $r): void {
                $r->update([
                    'status' => Partner::STATUS_ACTIVE,
                    'approved_at' => $r->approved_at ?? now(),
                ]);
                Notification::make()->title('Партнёр активирован')->success()->send();
            });
    }

    /** Приостановить партнёра — новые клиенты к нему больше не привязываются. */
    protected static function suspendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('suspend')
            ->label('Приостановить')
            ->icon('heroicon-o-pause-circle')
            ->color('gray')
            ->visible(fn (Partner $r): bool => $r->status === Partner::STATUS_ACTIVE)
            ->requiresConfirmation()
            ->action(function (Partner $r): void {
                $r->update(['status' => Partner::STATUS_SUSPENDED]);
                Notification::make()->title('Партнёр приостановлен')->warning()->send();
            });
    }
}
