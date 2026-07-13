<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\AccessAttemptResource\Pages;
use App\Models\AccessAttempt;
use App\Services\Access\StudentUnblockService;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Лента «Проблемы со входом» (H849): кто пытался войти/восстановить доступ и не
 * смог — неудачные логины + запросы ссылки (не найден / троттл / отправлено).
 * Read-only (пишется слушателями/контроллером), плюс одно действие —
 * «Разблокировать» — прямо из строки.
 */
class AccessAttemptResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = AccessAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Проблемы со входом';

    protected static ?string $pluralModelLabel = 'Проблемы со входом';

    protected static ?string $modelLabel = 'попытка входа';

    /** Бейдж в меню = сколько «застрявших» ждут разбора (свежие, неразобранные). */
    public static function getNavigationBadge(): ?string
    {
        $count = AccessAttempt::query()->unhandled()->recent(14)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Проблема')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AccessAttempt::kindLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        AccessAttempt::KIND_RESET_THROTTLED, AccessAttempt::KIND_LOCKOUT => 'danger',
                        AccessAttempt::KIND_FAILED_LOGIN, AccessAttempt::KIND_RESET_NOT_FOUND => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->placeholder('— нет аккаунта —')
                    ->url(fn (AccessAttempt $record): ?string => $record->user_id
                        ? UserResource::getUrl('edit', ['record' => $record->user_id])
                        : null),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('handled_at')
                    ->label('Разобрано')
                    ->boolean()
                    ->getStateUsing(fn (AccessAttempt $record): bool => $record->handled_at !== null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Тип')
                    ->options(AccessAttempt::kindLabels()),
                Tables\Filters\Filter::make('unhandled')
                    ->label('Только неразобранные')
                    ->query(fn ($query) => $query->whereNull('handled_at'))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('unblock')
                    ->label('Разблокировать')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (AccessAttempt $record): bool => $record->user_id !== null
                        && $record->handled_at === null
                        && RoleGate::adminOnly())
                    ->form([
                        \Filament\Forms\Components\Toggle::make('reset_password')
                            ->label('Также сбросить пароль')
                            ->default(false),
                    ])
                    ->modalHeading('Разблокировать студента?')
                    ->modalDescription('Снимем троттл и создадим одноразовую ссылку для входа (24 ч) для передачи студенту напрямую.')
                    ->modalSubmitActionLabel('Разблокировать')
                    ->action(function (AccessAttempt $record, array $data) {
                        $user = $record->user;
                        if ($user === null) {
                            Notification::make()
                                ->title('Нет аккаунта')
                                ->body('К этой попытке не привязан пользователь — разблокировать нечего.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(StudentUnblockService::class)
                            ->unblock($user, auth()->id(), (bool) ($data['reset_password'] ?? false));

                        $body = "Одноразовая ссылка для входа (24 ч) — передайте студенту:\n{$result['login_link']}";
                        if ($result['password'] !== null) {
                            $body .= "\n\nВременный пароль: {$result['password']}";
                        }

                        Notification::make()
                            ->title('Готово — скопируйте и передайте студенту')
                            ->body($body)
                            ->persistent()
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccessAttempts::route('/'),
        ];
    }
}
