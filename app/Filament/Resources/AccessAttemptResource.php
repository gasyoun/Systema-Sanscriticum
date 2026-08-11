<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\AccessAttemptResource\Pages;
use App\Models\AccessAttempt;
use App\Services\Access\LoginLinkNotifier;
use App\Services\Access\StudentUnblockService;
use App\Support\RoleGate;
use Filament\Forms\Components\Toggle;
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

    /** Куратор (manager) видит ленту, чтобы выдать magic link; create/edit/delete — admin. */
    public static function canViewAny(): bool
    {
        return RoleGate::canIssueStudentLoginLink();
    }

    public static function canView($record): bool
    {
        return RoleGate::canIssueStudentLoginLink();
    }

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
                        && RoleGate::canIssueStudentLoginLink())
                    ->form([
                        Toggle::make('send_email')
                            ->label('Отправить ссылку на почту')
                            ->helperText('Снимите, если у студента как раз сломана почта.')
                            ->default(true),
                        Toggle::make('send_messengers')
                            ->label('Отправить ссылку в мессенджеры (Telegram / VK / Max)')
                            ->helperText('Уйдёт в те каналы, которые привязаны к аккаунту.')
                            ->default(true),
                        Toggle::make('reset_password')
                            ->label('Также сбросить пароль')
                            ->helperText('Обычно не нужно: ссылка входит без пароля. Пароль студенту не отправляется — передайте лично.')
                            ->default(false),
                    ])
                    ->modalHeading('Разблокировать студента?')
                    ->modalDescription('Снимем троттл и создадим одноразовую ссылку для входа (24 ч): отправим её студенту сами, а копия останется у вас — на случай, если ни один канал не сработает.')
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

                        $report = app(LoginLinkNotifier::class)->notify(
                            $user,
                            $result['login_link'],
                            (bool) ($data['send_email'] ?? false),
                            (bool) ($data['send_messengers'] ?? false),
                        );

                        $body = 'Доставка: '.LoginLinkNotifier::reportSummary($report);
                        $body .= "\n\nОдноразовая ссылка для входа (24 ч) — если не дошла, передайте сами:\n{$result['login_link']}";
                        if ($result['password'] !== null) {
                            $body .= "\n\nВременный пароль (студенту не отправлен): {$result['password']}";
                        }

                        Notification::make()
                            ->title('Готово — ссылка выдана')
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
