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

    /** Сколько присланных студентами отзывов ждут модерации. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Testimonial::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Отзывы студентов на модерации';
    }

    public const STATUS_LABELS = [
        Testimonial::STATUS_PENDING => 'На модерации',
        Testimonial::STATUS_APPROVED => 'Одобрен',
        Testimonial::STATUS_REJECTED => 'Отклонён',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Прислан студентом')
                ->visible(fn (?Testimonial $record) => $record?->user_id !== null)
                ->schema([
                    Forms\Components\Placeholder::make('submitted_by')
                        ->label('Студент')
                        ->content(fn (Testimonial $record) => $record->user?->email ?? '— аккаунт удалён —'),
                    Forms\Components\Placeholder::make('moderation')
                        ->label('Статус')
                        ->content(fn (Testimonial $record) => self::STATUS_LABELS[$record->moderation_status] ?? $record->moderation_status),
                    Forms\Components\Placeholder::make('consent')
                        ->label('Согласие на публикацию')
                        ->content(fn (Testimonial $record) => $record->publish_consent_at?->format('d.m.Y H:i') ?? 'нет'),
                ])
                ->columns(3),

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

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\DatePicker::make('reviewed_at')
                        ->label('Дата отзыва')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->maxDate(now())
                        ->helperText('Печатается на карточке. Пусто — без даты.'),

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

                Forms\Components\Toggle::make('is_featured')
                    ->label('Избранный (витрина каталога)')
                    ->helperText('Показывать в общесайтовом блоке отзывов на странице каталога — помимо привязки к курсам. На странице входа избранные идут первыми.')
                    ->default(false),

                Forms\Components\Toggle::make('show_on_login')
                    ->label('На странице входа')
                    ->helperText('Бегущие колонки отзывов вокруг формы входа. Нужно и «Показывать».')
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

                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('courses_count')
                    ->label('На курсах')
                    ->counts('courses')
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('is_visible')
                    ->label('Виден')
                    ->boolean(),

                Tables\Columns\ToggleColumn::make('show_on_login')
                    ->label('На входе'),

                Tables\Columns\TextColumn::make('moderation_status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Testimonial::STATUS_PENDING => 'warning',
                        Testimonial::STATUS_REJECTED => 'danger',
                        default => 'success',
                    })
                    ->description(fn (Testimonial $r) => $r->user_id ? 'от студента' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('moderation_status')
                    ->label('Модерация')
                    ->options(self::STATUS_LABELS),
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Видимость'),
                Tables\Filters\TernaryFilter::make('show_on_login')
                    ->label('На странице входа'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Testimonial $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalDescription('Отзыв появится на странице входа и на /otzyvy.')
                    ->action(fn (Testimonial $record) => $record->approve()),
                Tables\Actions\Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (Testimonial $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalDescription('Отзыв останется скрытым. Студент сможет прислать новый.')
                    ->action(fn (Testimonial $record) => $record->reject()),
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
