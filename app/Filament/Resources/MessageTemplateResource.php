<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\MessageTemplateResource\Pages;
use App\Models\MessageTemplate;
use App\Support\MessagePlaceholders;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Админ-CRUD общей библиотеки шаблонов сообщений (H221). Один текст питает
 * реактивацию, follow-up лидов и канреплаи поддержки. За флагом crm_cockpit —
 * пока OFF, ресурс скрыт из навигации и роут закрыт.
 */
class MessageTemplateResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = MessageTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?int $navigationSort = 125;

    protected static ?string $navigationLabel = 'Шаблоны сообщений';

    protected static ?string $modelLabel = 'шаблон';

    protected static ?string $pluralModelLabel = 'Шаблоны сообщений';

    /**
     * Весь ресурс живёт за фича-флагом кокпита + AdminOnly. NB: AdminOnly —
     * trait, «влитый» в класс, поэтому parent:: ушёл бы мимо гейта в
     * Resource::* — проверяем роль напрямую через RoleGate.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return config('features.crm_cockpit') && RoleGate::adminOnly();
    }

    public static function canViewAny(): bool
    {
        return config('features.crm_cockpit') && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Select::make('category')
                    ->label('Категория')
                    ->options(MessageTemplate::categories())
                    ->required()
                    ->native(false),

                Forms\Components\Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),

                Forms\Components\Textarea::make('body')
                    ->label('Текст сообщения')
                    ->required()
                    ->rows(8)
                    ->helperText('Плейсхолдеры: '.implode(', ', MessagePlaceholders::KEYS).'. Подставятся под получателя при отправке.')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Категория')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MessageTemplate::categories()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        MessageTemplate::CATEGORY_LEAD => 'info',
                        MessageTemplate::CATEGORY_REACTIVATION => 'warning',
                        MessageTemplate::CATEGORY_SUPPORT => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('category')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Категория')
                    ->options(MessageTemplate::categories()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активен'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageTemplates::route('/'),
            'create' => Pages\CreateMessageTemplate::route('/create'),
            'edit' => Pages\EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
