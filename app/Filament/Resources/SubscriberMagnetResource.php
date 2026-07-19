<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\SubscriberMagnetResource\Pages;
use App\Models\SubscriberMagnet;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * «Полка подписчика» (H324): админ-редактируемые лид-магниты рассылки. Ресурс
 * виден только при включённом фича-флаге newsletter_subscribe и только админам.
 */
class SubscriberMagnetResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SubscriberMagnet::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 135;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Полка подписчика';

    protected static ?string $pluralModelLabel = 'Магниты подписчика';

    protected static ?string $modelLabel = 'Магнит';

    public static function canViewAny(): bool
    {
        return config('features.newsletter_subscribe') && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.newsletter_subscribe') && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Название')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('description')
                ->label('Описание')->rows(2)->columnSpanFull(),
            Forms\Components\FileUpload::make('file_path')
                ->label('Файл (PDF/материал)')
                ->disk('public')
                ->directory('subscriber-magnets')
                // H1345: поле было без maxSize и без acceptedFileTypes — то есть
                // принимало файл любого размера и любого типа (включая
                // исполняемый) в публично отдаваемый каталог. Лид-магнит — это
                // документ, а не медиатека.
                ->acceptedFileTypes([
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/epub+zip',
                ])
                ->maxSize(20480) // 20 МБ
                ->helperText('PDF, DOC/DOCX или EPUB, до 20 МБ. Либо укажите внешнюю ссылку ниже.'),
            Forms\Components\TextInput::make('url')
                ->label('Внешняя ссылка')->url()->maxLength(2048)
                ->helperText('Если заполнено — используется вместо файла.'),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Toggle::make('is_active')->label('Активен')->default(true),
                Forms\Components\TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Магнит')->searchable()->weight('bold')->wrap(),
                Tables\Columns\IconColumn::make('is_active')->label('Активен')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('Порядок')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y')->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriberMagnets::route('/'),
            'create' => Pages\CreateSubscriberMagnet::route('/create'),
            'edit' => Pages\EditSubscriberMagnet::route('/{record}/edit'),
        ];
    }
}
