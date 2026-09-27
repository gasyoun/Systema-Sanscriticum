<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramBusinessStoryPublicationResource\Pages;
use App\Models\TelegramBusinessStoryPublication;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only delivery receipts for the automatic editorial-channel Story lane. */
final class TelegramBusinessStoryPublicationResource extends Resource
{
    protected static ?string $model = TelegramBusinessStoryPublication::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 27;

    protected static ?string $navigationLabel = 'Автосториз';

    protected static ?string $modelLabel = 'автосториз';

    protected static ?string $pluralModelLabel = 'Автосториз';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::canViewAny()) {
            return null;
        }

        $count = self::attentionQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('source_chat_id')->label('Источник')->searchable(),
                Tables\Columns\TextColumn::make('source_message_id')->label('Пост')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'duplicate' => 'gray',
                        'failed', 'uncertain' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('part_count')->label('Частей'),
                Tables\Columns\TextColumn::make('story_ids')->label('Story ID по порядку')
                    ->getStateUsing(fn (TelegramBusinessStoryPublication $record): string => implode(', ', $record->story_ids ?? []))
                    ->wrap(),
                Tables\Columns\TextColumn::make('error')->label('Ошибка / предупреждение')
                    ->limit(100)->tooltip(fn (TelegramBusinessStoryPublication $record): ?string => $record->error),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлено')->dateTime('d-m-Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'received' => 'Получено',
                    'partial' => 'Публикуется / частично',
                    'published' => 'Опубликовано',
                    'duplicate' => 'Дубликат',
                    'failed' => 'Ошибка',
                    'uncertain' => 'Исход неизвестен — проверить вручную',
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTelegramBusinessStoryPublications::route('/')];
    }

    private static function attentionQuery(): Builder
    {
        return TelegramBusinessStoryPublication::query()
            ->where(function (Builder $query): void {
                $query->whereIn('status', ['failed', 'uncertain'])
                    ->orWhere(fn (Builder $partial): Builder => $partial->where('status', 'partial')
                        ->where('updated_at', '<', now()->subMinutes(15)))
                    ->orWhere(fn (Builder $warning): Builder => $warning->where('status', 'published')
                        ->where('error', 'like', 'Source video exceeded 600 seconds%'));
            });
    }
}
