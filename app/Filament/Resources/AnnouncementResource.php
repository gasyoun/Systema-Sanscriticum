<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    // Делаем красивую иконку рупора в левом меню
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationLabel = 'Рассылки';
    protected static ?string $pluralModelLabel = 'Сообщения';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Заголовок сообщения')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('preview')
                            ->label('Краткое превью (до 100 символов)')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Этот текст студент увидит до того, как раскроет сообщение.')
                            ->columnSpanFull(),
                            
                        Forms\Components\Select::make('target_courses')
                            ->label('Кому отправить? (Фильтр по курсам)')
                            ->multiple() // Позволяет выбрать несколько курсов
                            ->options(\App\Models\Course::pluck('title', 'id')) // Берем названия всех курсов из базы
                            ->placeholder('Всем студентам (оставьте пустым)')
                            ->helperText('Если ничего не выбрано, рассылка отобразится у ВСЕХ студентов платформы.')
                            ->columnSpanFull(),    

                        // 👇 Поле для картинки 👇
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Картинка / Обложка (необязательно)')
                            ->image()
                            ->directory('announcements')
                            ->imageEditor() // Включает встроенный редактор для обрезки
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('content')
                            ->label('Полный текст сообщения')
                            ->required()
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'h2', 'h3', 'bulletList', 'orderedList',
                                'link', 'blockquote',
                            ])
                            ->columnSpanFull(),
                    ]),

                // 👇 Блок для создания красивой кнопки 👇
                Forms\Components\Section::make('Кнопка действия (Call to Action)')
                    ->description('Если хотите добавить красивую кнопку под текстом сообщения, заполните эти поля.')
                    ->schema([
                        Forms\Components\TextInput::make('button_text')
                            ->label('Текст на кнопке')
                            ->placeholder('Например: Записаться на курс'),
                            
                        Forms\Components\TextInput::make('button_url')
                            ->label('Ссылка для кнопки')
                            ->url()
                            ->placeholder('https://...'),
                    ])->columns(2),

                Forms\Components\Section::make('Настройки отправки')
                    ->schema([
                        Forms\Components\Toggle::make('is_published')
                            ->label('Опубликовать в кабинетах студентов')
                            ->default(true),
                            
                        Forms\Components\Toggle::make('send_to_email')
                            ->label('Продублировать на Email')
                            ->default(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable(),
                    
                Tables\Columns\IconColumn::make('is_published')
                    ->label('В кабинете')
                    ->boolean(),
                    
                Tables\Columns\IconColumn::make('send_to_email')
                    ->label('Email')
                    ->boolean(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата создания')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}