<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\LectureClipResource\Pages;
use App\Models\LectureClip;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Клипы, нарезанные n8n/ffmpeg из AI-таймкодов лекций (H1452, Wave 4). Строки
 * приходят из LectureClipCallbackWebhookController — здесь куратор только
 * отмечает ~3 бесплатных фрагмента на лекцию. Ресурс виден только при
 * включённом флаге clip_marketing, тот же паттерн что SubscriberMagnetResource.
 */
class LectureClipResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = LectureClip::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?int $navigationSort = 136;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Клипы лекций';

    protected static ?string $pluralModelLabel = 'Клипы лекций';

    protected static ?string $modelLabel = 'Клип';

    public static function canViewAny(): bool
    {
        return config('features.clip_marketing') === true && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.clip_marketing') === true && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('lesson_id')
                ->relationship('lesson', 'title')
                ->label('Лекция')
                ->disabled()
                ->dehydrated(false),
            Forms\Components\TextInput::make('title')
                ->label('Заголовок')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('start_seconds')->label('Начало, сек')->numeric()->required(),
                Forms\Components\TextInput::make('end_seconds')->label('Конец, сек')->numeric()->required(),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('vk_video_id')->label('VK video id'),
                Forms\Components\TextInput::make('vk_owner_id')->label('VK owner id'),
            ]),
            Forms\Components\Toggle::make('is_free')->label('Бесплатный (для витрины)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('lesson.title')->label('Лекция')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('title')->label('Клип')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('start_seconds')->label('Начало')->formatStateUsing(
                    fn (int $state): string => gmdate('i:s', $state)
                ),
                Tables\Columns\TextColumn::make('end_seconds')->label('Конец')->formatStateUsing(
                    fn (int $state): string => gmdate('i:s', $state)
                ),
                Tables\Columns\IconColumn::make('vk_video_id')->label('В VK')
                    ->boolean()
                    ->getStateUsing(fn (LectureClip $record): bool => filled($record->vk_video_id)),
                Tables\Columns\ToggleColumn::make('is_free')->label('Бесплатный'),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_free')->label('Бесплатный'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLectureClips::route('/'),
            'edit' => Pages\EditLectureClip::route('/{record}/edit'),
        ];
    }
}
