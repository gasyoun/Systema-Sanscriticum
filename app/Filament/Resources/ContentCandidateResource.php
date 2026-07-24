<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContentCandidateResource\Pages;
use App\Models\ContentCandidate;
use App\Models\LectureClip;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Thin review surface for ContentCandidate (H1547 Wave 1, H1548 Wave 2).
 * "Mark free" (clip type) also flips the underlying LectureClip so the two
 * stay in sync in either direction, then auto-drafts a social_post via
 * ContentCandidateObserver. "Accept" (non-clip types: social_post/
 * email_blast/…) is the human review gate — accepting a social_post
 * dispatches PublishSocialPostJob, accepting an email_blast dispatches
 * SendContentOneShotMailJob (both self-gated by their own OFF-by-default
 * flag, so accepting here never actually posts/sends until activation).
 */
class ContentCandidateResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ContentCandidate::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 137;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Контент-кандидаты';

    protected static ?string $pluralModelLabel = 'Контент-кандидаты';

    protected static ?string $modelLabel = 'Контент-кандидат';

    public static function canViewAny(): bool
    {
        return config('features.content_from_lectures') === true && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.content_from_lectures') === true && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('type')->label('Тип')->disabled()->dehydrated(false),
            Forms\Components\TextInput::make('status')->label('Статус')->disabled()->dehydrated(false),
            Forms\Components\TextInput::make('title')->label('Заголовок')->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('body')->label('Текст')->rows(4)->columnSpanFull(),
            Forms\Components\Textarea::make('quote')
                ->label('Цитата (не более 2 предложений)')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('Тип')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('lesson.title')->label('Лекция')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('title')->label('Заголовок')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('channel_target')->label('Канал'),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Тип')->options([
                    ContentCandidate::TYPE_CLIP => 'Клип',
                    ContentCandidate::TYPE_SOCIAL_POST => 'Соцсеть',
                    ContentCandidate::TYPE_FAQ_DRAFT => 'FAQ',
                    ContentCandidate::TYPE_ARTICLE => 'Статья',
                    ContentCandidate::TYPE_STUDY_ARTIFACT => 'Учебный материал',
                    ContentCandidate::TYPE_EMAIL_BLAST => 'Рассылка',
                ]),
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    ContentCandidate::STATUS_DRAFT => 'Черновик',
                    ContentCandidate::STATUS_ACCEPTED => 'Принят',
                    ContentCandidate::STATUS_PUBLISHED => 'Опубликован',
                    ContentCandidate::STATUS_DISCARDED => 'Отклонён',
                    ContentCandidate::STATUS_FAILED => 'Ошибка',
                ]),
                Tables\Filters\SelectFilter::make('lesson_id')->label('Лекция')->relationship('lesson', 'title'),
            ])
            ->actions([
                Tables\Actions\Action::make('accept')
                    ->label('Принять')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ContentCandidate $record): bool => $record->type !== ContentCandidate::TYPE_CLIP
                        && $record->status === ContentCandidate::STATUS_DRAFT)
                    ->action(fn (ContentCandidate $record) => $record->update(['status' => ContentCandidate::STATUS_ACCEPTED])),
                Tables\Actions\Action::make('mark_free')
                    ->label('Сделать бесплатным')
                    ->icon('heroicon-o-gift')
                    ->visible(fn (ContentCandidate $record): bool => $record->type === ContentCandidate::TYPE_CLIP
                        && $record->status !== ContentCandidate::STATUS_ACCEPTED)
                    ->action(function (ContentCandidate $record): void {
                        $record->update(['status' => ContentCandidate::STATUS_ACCEPTED, 'channel_target' => 'vk_wall']);
                        if ($record->lecture_clip_id !== null) {
                            LectureClip::whereKey($record->lecture_clip_id)->update(['is_free' => true]);
                        }
                    }),
                Tables\Actions\Action::make('discard')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ContentCandidate $record): bool => $record->status !== ContentCandidate::STATUS_DISCARDED)
                    ->action(fn (ContentCandidate $record) => $record->update(['status' => ContentCandidate::STATUS_DISCARDED])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCandidates::route('/'),
            'edit' => Pages\EditContentCandidate::route('/{record}/edit'),
        ];
    }
}
