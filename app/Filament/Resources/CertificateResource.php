<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CertificateResource\Pages;
use App\Filament\Resources\CertificateResource\RelationManagers;
use App\Models\Certificate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // 1. Выбор Студента (обязательно)
                Forms\Components\Select::make('user_id')
                    ->label('Студент')
                    ->relationship('user', 'name') // Ищем по имени в таблице users
                    ->searchable()
                    ->preload()
                    ->required(), // <--- ВАЖНО: Не даст сохранить без выбора

                // 2. Выбор Курса (обязательно)
                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title') // Ищем по названию в таблице courses
                    ->searchable()
                    ->preload()
                    ->required(), // <--- ВАЖНО

                // 3. Путь к файлу (если загружаем вручную, но мы теперь генерируем их)
                // Можно оставить необязательным или вообще скрыть, раз у нас автогенерация
                Forms\Components\TextInput::make('file_path')
                    ->label('Путь к файлу (необязательно при автогенерации)')
                    ->disabled() // Блокируем, чтобы руками не писали ерунду
                    ->placeholder('Генерируется автоматически'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->label('Номер сертификата')
                    ->searchable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Дата выдачи')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(), // Кнопка отзыва сертификата
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificates::route('/'),
            'create' => Pages\CreateCertificate::route('/create'),
            'edit' => Pages\EditCertificate::route('/{record}/edit'),
        ];
    }
}
