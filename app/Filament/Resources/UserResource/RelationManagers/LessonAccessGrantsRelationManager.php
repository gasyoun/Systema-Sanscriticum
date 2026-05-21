<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Services\ConditionalAccessGranter;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Разовые доступы к отдельным урокам — общий механизм, не привязанный к долгу или
 * promise'у. Например, студент пропустил первое бесплатное занятие и хочет вместо
 * него попасть на 2-е занятие 3-го блока. Менеджер выдаёт ему доступ только к
 * этому одному уроку, не открывая весь блок.
 */
class LessonAccessGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessonAccessGrants';

    protected static ?string $title = 'Разовые доступы к урокам';

    protected static ?string $icon = 'heroicon-o-key';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('granted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->wrap(),
                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->description(fn (LessonAccessGrant $r): ?string => $r->lesson?->block_number !== null
                        ? 'Блок №'.((int) $r->lesson->block_number)
                        : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (LessonAccessGrant $r): string => match (true) {
                        $r->revoked_at !== null => 'revoked',
                        $r->expires_at !== null && $r->expires_at->isPast() => 'expired',
                        default => 'active',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'активен',
                        'revoked' => 'отозван',
                        'expired' => 'истёк',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'revoked' => 'gray',
                        'expired' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('granted_at')
                    ->label('Открыт')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Действует до')
                    ->date('d.m.Y')
                    ->placeholder('бессрочно'),
                Tables\Columns\TextColumn::make('grantedBy.name')
                    ->label('Кто открыл')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('revoked_at')
                    ->label('Отозван')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('grant_lesson')
                    ->label('Выдать доступ к уроку')
                    ->icon('heroicon-o-key')
                    ->color('info')
                    ->visible(fn (): bool => ! ($this->getOwnerRecord()?->isUnreliable() ?? false))
                    ->modalHeading('Разовый доступ к уроку')
                    ->modalDescription('Откроет студенту один конкретный урок без покупки целого блока. Сначала выберите курс — затем урок из него.')
                    ->form([
                        Forms\Components\Select::make('course_id')
                            ->label('Курс')
                            ->options(fn () => Course::query()
                                ->where('is_active', true)
                                ->orderBy('title')
                                ->pluck('title', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('lesson_id', null)),
                        Forms\Components\Select::make('lesson_id')
                            ->label('Урок')
                            ->options(fn (Forms\Get $get) => $get('course_id')
                                ? Lesson::query()
                                    ->where('course_id', $get('course_id'))
                                    ->orderBy('block_number')
                                    ->orderBy('created_at')
                                    ->get()
                                    ->mapWithKeys(fn (Lesson $l) => [
                                        $l->id => 'Блок №'.((int) $l->block_number).' · '.$l->title,
                                    ])
                                    ->all()
                                : [])
                            ->searchable()
                            ->required()
                            ->disabled(fn (Forms\Get $get) => empty($get('course_id'))),
                        Forms\Components\DatePicker::make('expires_at')
                            ->label('Действует до (необязательно)')
                            ->native(false)
                            ->minDate(now())
                            ->helperText('Пусто = бессрочно. Удобно для «открываем только до конца недели».'),
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина (для аудита)')
                            ->rows(2)
                            ->placeholder('Например: «пропустил первое бесплатное занятие, открываем 2-е».'),
                    ])
                    ->action(function (array $data): void {
                        $student = $this->getOwnerRecord();
                        $lesson = Lesson::find($data['lesson_id'] ?? null);
                        if (! $student || ! $lesson || (int) $lesson->course_id !== (int) ($data['course_id'] ?? 0)) {
                            Notification::make()->title('Урок не найден или не принадлежит курсу')->danger()->send();

                            return;
                        }

                        $grant = app(ConditionalAccessGranter::class)->grantLesson(
                            $student,
                            $lesson,
                            auth()->user(),
                            ! empty($data['reason']) ? (string) $data['reason'] : null,
                        );

                        if (! empty($data['expires_at'])) {
                            $grant->forceFill(['expires_at' => $data['expires_at']])->save();
                        }

                        Notification::make()
                            ->title('Доступ к уроку открыт')
                            ->body('Студент получит уведомление в Telegram.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke_lesson_grant')
                    ->label('Отозвать')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (LessonAccessGrant $r): bool => $r->isActive())
                    ->requiresConfirmation()
                    ->modalDescription('Доступ к уроку будет закрыт. Студенту уйдёт TG-уведомление.')
                    ->action(function (LessonAccessGrant $r): void {
                        app(ConditionalAccessGranter::class)->revokeLesson($r, auth()->user());
                        Notification::make()->title('Доступ отозван')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Разовых доступов не выдавалось')
            ->emptyStateDescription('Нажмите «Выдать доступ к уроку», чтобы открыть студенту один урок без покупки целого блока.');
    }
}
