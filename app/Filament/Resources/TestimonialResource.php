<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\TestimonialResource\Pages;
use App\Models\Testimonial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TestimonialResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Testimonial::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Отзывы';

    protected static ?string $modelLabel = 'Отзыв';

    protected static ?string $pluralModelLabel = 'Отзывы';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Отзыв')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('author_name')
                        ->label('Автор')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('city')
                        ->label('Город')
                        ->maxLength(255),
                ]),

                Forms\Components\Textarea::make('body')
                    ->label('Текст отзыва')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull(),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Select::make('rating')
                        ->label('Оценка')
                        ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                        ->placeholder('— без оценки —'),

                    Forms\Components\TextInput::make('media_url')
                        ->label('Ссылка на аудио/видео-отзыв')
                        ->url()
                        ->maxLength(1024),
                ]),

                Forms\Components\FileUpload::make('avatar_path')
                    ->label('Аватар автора')
                    ->image()
                    ->directory('testimonials')
                    ->imageEditor()
                    ->maxSize(4096),

                Forms\Components\Toggle::make('is_visible')
                    ->label('Показывать')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn () => null),

                Tables\Columns\TextColumn::make('author_name')
                    ->label('Автор')
                    ->description(fn (Testimonial $r) => $r->city)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Текст')
                    ->formatStateUsing(fn ($state) => Str::limit(strip_tags((string) $state), 70))
                    ->wrap(),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Оценка')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('courses_count')
                    ->label('На курсах')
                    ->counts('courses')
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('is_visible')
                    ->label('Виден')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Видимость'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit' => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
