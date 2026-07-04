<?php

namespace App\Filament\Resources\CourseResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TestimonialsRelationManager extends RelationManager
{
    protected static string $relationship = 'testimonials';

    protected static ?string $title = 'Отзывы на курсе';

    protected static ?string $recordTitleAttribute = 'author_name';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('course_testimonial.sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('author_name')
                    ->label('Автор')
                    ->searchable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Текст')
                    ->formatStateUsing(fn ($state) => Str::limit(strip_tags((string) $state), 60))
                    ->wrap(),

                Tables\Columns\TextColumn::make('pivot.sort_order')
                    ->label('Порядок')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_visible')
                    ->label('Виден')
                    ->boolean(),
            ])
            ->headerActions([
                // Прикрепляем отзыв из общей библиотеки + задаём порядок показа.
                Tables\Actions\AttachAction::make()
                    ->label('Прикрепить отзыв')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['author_name', 'body'])
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Отзыв из библиотеки'),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Порядок показа')
                            ->numeric()
                            ->default(0),
                    ]),
            ])
            ->actions([
                // Правка порядка показа в пивоте.
                Tables\Actions\EditAction::make()
                    ->label('Порядок')
                    ->form([
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Порядок показа')
                            ->numeric()
                            ->default(0),
                    ]),
                Tables\Actions\DetachAction::make()->label('Открепить'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
