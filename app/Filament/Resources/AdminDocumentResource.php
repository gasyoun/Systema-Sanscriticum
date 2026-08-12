<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\AdminDocumentResource\Pages;
use App\Models\AdminDocument;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Важные файлы» (H2570) — библиотека рабочих документов суперадмина и админа.
 *
 * AdminOnly: RoleGate::adminOnly() = admin, а super_admin проходит через
 * RoleGate::any(). Менеджер, бухгалтер и преподаватель ресурс не видят вовсе —
 * это не «ещё один раздел материалов», а внутренние ссылки владельца.
 *
 * Второй контур — AdminDocument::scopeVisibleTo(): галочка «только суперадмин»
 * убирает строку у обычного админа не только из таблицы, но и из прямого
 * /admin/admin-documents/{id}/edit (getEloquentQuery сужает выборку, поэтому
 * Filament отдаёт 404, а не форму).
 */
class AdminDocumentResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = AdminDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'Управление';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Важные файлы';

    protected static ?string $modelLabel = 'важный файл';

    protected static ?string $pluralModelLabel = 'Важные файлы';

    protected static ?string $slug = 'admin-documents';

    /**
     * Строки, доступные текущему пользователю. Сужение делает модель —
     * условие super_admin_only живёт в одном месте (scopeVisibleTo).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Документ')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Название')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Как документ называют в работе, а не имя файла.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('source_url')
                        ->label('Ссылка')
                        ->url()
                        ->required()
                        ->helperText('Ссылку храним дословно, включая #gid вкладки. '
                            .'Тип и хост подставятся сами.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Зачем нужен')
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText('Одна строка для того, кто откроет библиотеку через год.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Место в библиотеке')
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('Раздел')
                        ->options(AdminDocument::CATEGORIES)
                        ->default('other')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('kind')
                        ->label('Тип')
                        ->options(AdminDocument::KINDS)
                        ->placeholder('Определить по ссылке')
                        ->helperText('Пусто — выведем из ссылки при сохранении.')
                        ->native(false),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок')
                        ->numeric()
                        ->default(0)
                        ->helperText('Меньше — выше в списке.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Актуален')
                        ->default(true)
                        ->helperText('Снять, когда документ устарел, но ссылку жаль потерять.'),

                    Forms\Components\Toggle::make('super_admin_only')
                        ->label('Только суперадмин')
                        ->helperText('Обычный админ не увидит строку ни в списке, ни по прямой ссылке.')
                        // Кто не суперадмин, тот не может и раздать себе доступ.
                        ->disabled(fn (): bool => ! RoleGate::isSuperAdmin())
                        ->dehydrated(fn (): bool => RoleGate::isSuperAdmin()),
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
                    ->wrap()
                    // Строка библиотеки нужна, чтобы по ней перейти: один клик
                    // из списка, без промежуточной формы просмотра.
                    ->url(fn (AdminDocument $record): string => $record->source_url)
                    ->openUrlInNewTab()
                    ->description(fn (AdminDocument $record): ?string => $record->description),

                Tables\Columns\TextColumn::make('category')
                    ->label('Раздел')
                    ->badge()
                    ->formatStateUsing(fn (AdminDocument $record): string => $record->categoryLabel()),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Тип')
                    ->formatStateUsing(fn (AdminDocument $record): string => $record->kindLabel()),

                Tables\Columns\TextColumn::make('host')
                    ->label('Где лежит')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('super_admin_only')
                    ->label('Только СА')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Актуален')
                    ->boolean(),

                Tables\Columns\TextColumn::make('addedBy.name')
                    ->label('Добавил')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->defaultGroup('category')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Раздел')
                    ->options(AdminDocument::CATEGORIES),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Актуальность')
                    ->placeholder('Все')
                    ->trueLabel('Только актуальные')
                    ->falseLabel('Только устаревшие')
                    ->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Открыть')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (AdminDocument $record): string => $record->source_url)
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Библиотека пуста')
            ->emptyStateDescription('Добавьте таблицу, регламент или реестр, '
                .'к которым возвращаетесь чаще всего.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminDocuments::route('/'),
            'create' => Pages\CreateAdminDocument::route('/create'),
            'edit' => Pages\EditAdminDocument::route('/{record}/edit'),
        ];
    }
}
