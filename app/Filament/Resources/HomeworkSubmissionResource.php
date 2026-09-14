<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomeworkSubmissionResource\Pages;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Services\HomeworkService;
use App\Services\Stories\StoryPromotionService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Js;
use Throwable;

class HomeworkSubmissionResource extends Resource
{
    protected static ?string $model = HomeworkSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Домашние работы';

    protected static ?string $modelLabel = 'домашняя работа';

    protected static ?string $pluralModelLabel = 'Домашние работы';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canEdit($record): bool
    {
        return static::canReview($record);
    }

    public static function canCreate(): bool
    {
        // Работы создаются студентами из кабинета, а не в админке.
        return false;
    }

    /**
     * Проверять может админ, преподаватель курса этой работы или проверяющий
     * по гранту на её группу (H1729).
     */
    public static function canReview($record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isAdminLike()) {
            return true;
        }
        if (! $user->isTeacher()) {
            return false;
        }

        if ($user->teacher_id && optional($record->course)->teacher_id === $user->teacher_id) {
            return true;
        }

        // Свою собственную работу проверяющий не выносит на вердикт никогда.
        if ((int) $record->user_id === (int) $user->id) {
            return false;
        }

        foreach ($record->reviewGroupIds() as $groupId) {
            if ($user->canReviewGroup($groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Шаблоны частых комментариев куратора — разные для «принять» и «вернуть».
     *
     * @return array<string, string>
     */
    public static function commentTemplates(string $status): array
    {
        $shared = [
            'Смотрите комментарии в приложенном файле.' => 'Смотрите комментарии в приложенном файле.',
            'Удачи!' => 'Удачи!',
        ];

        return $status === HomeworkSubmission::STATUS_ACCEPTED
            ? [
                'Отлично, работа принята! 🙏' => 'Отлично, работа принята! 🙏',
                'Хорошо, зачтено — двигаемся дальше.' => 'Хорошо, зачтено — двигаемся дальше.',
                'Принято. Обратите внимание на мелкие неточности в транслитерации.' => 'Принято. Обратите внимание на мелкие неточности в транслитерации.',
                ...$shared,
            ]
            : [
                'Проверьте, пожалуйста, транслитерацию (IAST) — есть ошибки.' => 'Проверьте, пожалуйста, транслитерацию (IAST) — есть ошибки.',
                'Не хватает части задания — дополните и пришлите снова.' => 'Не хватает части задания — дополните и пришлите снова.',
                'Посмотрите ещё раз склонение/спряжение в выделенных местах.' => 'Посмотрите ещё раз склонение/спряжение в выделенных местах.',
                'Деванагари местами неверно — сверьтесь с уроком.' => 'Деванагари местами неверно — сверьтесь с уроком.',
                ...$shared,
            ];
    }

    /**
     * Собрать текст комментария из выбранных шаблонов (multi-select).
     * Пустой выбор — null (не затирать ручной текст / черновик).
     *
     * @param  array<int|string, mixed>|string|null  $selected
     */
    public static function joinCommentTemplates(array|string|null $selected): ?string
    {
        $parts = array_values(array_filter(
            array_map(static fn ($s): string => (string) $s, (array) $selected),
            static fn (string $s): bool => $s !== '',
        ));

        if ($parts === []) {
            return null;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Поля «шаблоны + комментарий» для форм проверки (массовой и одиночной).
     *
     * $draftKey задан → отзыв переживает закрытие модалки (черновик в localStorage
     * браузера, см. reviewDraftKey()). Для массовой проверки ключа нет: он должен
     * быть привязан к конкретной работе, а там произвольный набор выделенных строк.
     *
     * @return array<int, Component>
     */
    public static function reviewCommentFields(string $status, bool $bodyRequired, ?string $draftKey = null): array
    {
        return [
            Forms\Components\Select::make('templates')
                ->label('Шаблоны ответа')
                ->options(static::commentTemplates($status))
                ->multiple()
                ->placeholder('— выбрать готовые фразы —')
                ->helperText('Можно несколько: подставятся в комментарий через пустую строку.')
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                    $joined = static::joinCommentTemplates($state);
                    if ($joined !== null) {
                        $set('body', $joined);
                    }
                })
                ->dehydrated(false),
            Forms\Components\Textarea::make('body')
                ->label($status === HomeworkSubmission::STATUS_ACCEPTED
                    ? 'Комментарий студенту (необязательно)'
                    : 'Что нужно исправить')
                ->rows(4)
                ->required($bodyRequired)
                ->when(
                    $draftKey !== null,
                    fn (Forms\Components\Textarea $field): Forms\Components\Textarea => $field
                        ->extraAlpineAttributes(static::reviewDraftAttributes($draftKey)),
                ),
        ];
    }

    /**
     * Alpine-обвязка черновика поверх штатного скоупа Filament-Textarea: у него уже
     * есть переменная `state`, связанная с Livewire через $wire.$entangle, поэтому
     * восстановление — обычное присваивание, без записи в DOM и без dispatch('input').
     *
     * Восстанавливаем ТОЛЬКО в пустое поле: выбор «Шаблона ответа» вызывает
     * $set('body', ...) и перерисовку, и черновик не должен затирать то, что
     * человек уже написал руками.
     *
     * @return array<string, string>
     */
    private static function reviewDraftAttributes(string $draftKey): array
    {
        $key = Js::from($draftKey);
        $ttl = 24 * 60 * 60 * 1000; // сутки: дольше черновик отзыва не живёт

        return [
            // Пустое поле = черновика нет, иначе «стёр текст» осталось бы навсегда.
            'x-on:input.debounce.500ms' => <<<JS
                state
                    ? localStorage.setItem({$key}, JSON.stringify({ v: state, t: Date.now() }))
                    : localStorage.removeItem({$key})
            JS,
            'x-init' => <<<JS
                \$nextTick(() => {
                    try {
                        const saved = JSON.parse(localStorage.getItem({$key}) ?? 'null');
                        if (saved?.v && ! state && (Date.now() - saved.t) < {$ttl}) {
                            state = saved.v;
                        }
                    } catch (e) {
                        localStorage.removeItem({$key});
                    }
                })
            JS,
        ];
    }

    /**
     * Ключ черновика отзыва. Версионируется числом уже сделанных проверок этой
     * работы, и это же решает вопрос «когда чистить»: успешная проверка создаёт
     * HomeworkComment(type=review), счётчик растёт, следующая модалка получает
     * ДРУГОЙ ключ — старый черновик просто перестаёт быть виден. Никакого JS-плумбинга
     * и никакой гонки с редиректом после сохранения.
     *
     * Считаем именно review-комментарии: иначе новое сообщение студента в треде
     * обнулило бы валидный черновик преподавателя.
     */
    public static function reviewDraftKey(HomeworkSubmission $record, string $status): string
    {
        $reviewCount = $record->comments()
            ->where('type', HomeworkComment::TYPE_REVIEW)
            ->count();

        return "hw-review-draft:{$record->id}:{$status}:{$reviewCount}";
    }

    /**
     * Применить вердикт к набору выбранных работ. Пропускает то, что нельзя
     * проверять или что уже в целевом статусе; возвращает уведомление с итогом.
     */
    public static function runBulkReview(Collection $records, string $status, array $data): void
    {
        $reviewer = auth()->user();
        $service = app(HomeworkService::class);
        $done = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! static::canReview($record) || $record->status === $status) {
                $skipped++;

                continue;
            }

            $service->recordReview($record, $reviewer, $status, $data['body'] ?? null);
            $done++;
        }

        $title = $status === HomeworkSubmission::STATUS_ACCEPTED
            ? "Принято работ: {$done}"
            : "Возвращено на доработку: {$done}";

        Notification::make()
            ->success()
            ->title($skipped > 0 ? "{$title} (пропущено: {$skipped})" : $title)
            ->send();
    }

    /**
     * Медиа-файлы работы, пригодные для сториз: фото/видео из комментариев
     * к сабмишну (H3964, юнит 5).
     */
    public static function storyPromotableFiles(HomeworkSubmission $record): Collection
    {
        return HomeworkFile::query()
            ->whereHas('comment', fn (Builder $q) => $q->where('submission_id', $record->id))
            ->get()
            ->filter(fn (HomeworkFile $file): bool => $file->isImage() || str_starts_with((string) $file->mime, 'video/'))
            ->values();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            // Черновики студентов в админку не показываем — только реально сданное.
            ->where('status', '!=', HomeworkSubmission::STATUS_DRAFT);

        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            $reviewableGroupIds = $user->reviewableGroupIds();

            $query->where(function (Builder $q) use ($user, $reviewableGroupIds) {
                // Ветка «свои курсы» — как было: основной преподаватель курса.
                if ($user->teacher_id) {
                    $q->whereHas('course', fn (Builder $c) => $c->where('teacher_id', $user->teacher_id));
                } else {
                    $q->whereRaw('1 = 0');
                }

                // Ветка «подшефные группы» (H1729). Именно ИЛИ, а не вместо:
                // преподаватель может вести свои курсы и параллельно проверять
                // чужие группы по гранту.
                if ($reviewableGroupIds !== []) {
                    $q->orWhere(function (Builder $r) use ($user, $reviewableGroupIds) {
                        $r->inReviewableGroups($reviewableGroupIds)
                            ->where('homework_submissions.user_id', '!=', $user->id);
                    });
                }
            });
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', HomeworkSubmission::STATUS_SUBMITTED)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            // Очередь проверки: дольше всех ждущие работы — сверху.
            ->defaultSort('last_activity_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->limit(30)
                    ->sortable(),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->limit(30),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        HomeworkSubmission::STATUS_SUBMITTED => 'На проверке',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'На доработку',
                        HomeworkSubmission::STATUS_ACCEPTED => 'Принято',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        HomeworkSubmission::STATUS_SUBMITTED => 'info',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'danger',
                        HomeworkSubmission::STATUS_ACCEPTED => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Ждёт')
                    ->since()
                    ->description(fn ($record): ?string => $record->last_activity_at?->format('d.m.Y H:i'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Проверено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        HomeworkSubmission::STATUS_SUBMITTED => 'На проверке',
                        HomeworkSubmission::STATUS_NEEDS_REVISION => 'На доработку',
                        HomeworkSubmission::STATUS_ACCEPTED => 'Принято',
                    ])
                    ->default(HomeworkSubmission::STATUS_SUBMITTED),

                Tables\Filters\SelectFilter::make('course')
                    ->label('Курс')
                    // Выборка фильтра обязана совпадать с выборкой таблицы: иначе
                    // в фильтре не окажется курсов, работы по которым видны.
                    ->relationship('course', 'title', function (Builder $query) {
                        $user = auth()->user();
                        if (! $user || ! $user->isTeacher() || $user->isAdminLike()) {
                            return;
                        }
                        $groupIds = $user->reviewableGroupIds();
                        $query->where(function (Builder $q) use ($user, $groupIds) {
                            $user->teacher_id
                                ? $q->where('teacher_id', $user->teacher_id)
                                : $q->whereRaw('1 = 0');
                            if ($groupIds !== []) {
                                $q->orWhereHas('groups', fn (Builder $g) => $g->whereIn('groups.id', $groupIds));
                            }
                        });
                    })
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('lesson')
                    ->label('Урок')
                    ->relationship('lesson', 'title', function (Builder $query) {
                        $user = auth()->user();
                        if (! $user || ! $user->isTeacher() || $user->isAdminLike()) {
                            return;
                        }
                        $groupIds = $user->reviewableGroupIds();
                        $query->where(function (Builder $q) use ($user, $groupIds) {
                            $user->teacher_id
                                ? $q->whereHas('course', fn (Builder $c) => $c->where('teacher_id', $user->teacher_id))
                                : $q->whereRaw('1 = 0');
                            if ($groupIds !== []) {
                                // Урок подшефной группы — напрямую; общий урок курса
                                // подшефной группы — через привязку курса к группе.
                                $q->orWhereIn('group_id', $groupIds)
                                    ->orWhere(function (Builder $l) use ($groupIds) {
                                        $l->whereNull('group_id')
                                            ->whereHas('course.groups', fn (Builder $g) => $g->whereIn('groups.id', $groupIds));
                                    });
                            }
                        });
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Проверить'),

                // «В сториз» (H3964, юнит 5): кураторский минимум — заводит
                // ЧЕРНОВИК story_posts из медиа принятой работы. Публикация
                // студенческих медиа отдельно заперта визой MG на правило
                // анонимизации (features.telegram_story_student_media_visa,
                // default OFF): издатель скипает source=homework до визы.
                Tables\Actions\Action::make('promoteStory')
                    ->label('В сториз')
                    ->icon('heroicon-o-camera')
                    ->color('gray')
                    ->visible(fn (HomeworkSubmission $record): bool => auth()->user()?->isAdminLike() === true
                        && $record->status === HomeworkSubmission::STATUS_ACCEPTED
                        && static::storyPromotableFiles($record)->isNotEmpty())
                    ->requiresConfirmation()
                    ->modalHeading('Вынести медиа работы в очередь сториз')
                    ->modalDescription('Создаётся черновик в «Очереди сториз» (полоса персоны @rusamskrtam). '
                        .'Публикация студенческих медиа запрещена до визы MG на правило анонимизации '
                        .'(blur/crop лиц и имён, подписи без имён и CRM-данных).')
                    ->form([
                        Forms\Components\Select::make('file_id')
                            ->label('Медиа работы')
                            ->options(fn (HomeworkSubmission $record): array => static::storyPromotableFiles($record)
                                ->mapWithKeys(fn (HomeworkFile $file): array => [$file->id => "{$file->original_name} ({$file->humanSize()})"])
                                ->all())
                            ->required(),
                        Forms\Components\Textarea::make('caption')
                            ->label('Подпись (без имён и персональных данных)')
                            ->rows(3)
                            ->maxLength(900),
                        Forms\Components\DateTimePicker::make('publish_at')
                            ->label('Когда публиковать')
                            ->seconds(false),
                    ])
                    ->action(function (HomeworkSubmission $record, array $data): void {
                        $file = static::storyPromotableFiles($record)->firstWhere('id', (int) $data['file_id']);
                        if (! $file) {
                            Notification::make()->danger()->title('Файл не найден')->send();

                            return;
                        }

                        try {
                            $post = app(StoryPromotionService::class)->fromHomeworkFile(
                                $file,
                                (string) ($data['caption'] ?? ''),
                                $data['publish_at'] ?? null,
                            );
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Не заведено')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Черновик сториз #{$post->id} создан")
                            ->body('Далее: approve + публикация издателем (студенческое медиа — только после визы).')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('accept')
                    ->label('Принять')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Принять выбранные работы')
                    ->form(static::reviewCommentFields(HomeworkSubmission::STATUS_ACCEPTED, bodyRequired: false))
                    ->action(fn (Collection $records, array $data) => static::runBulkReview(
                        $records, HomeworkSubmission::STATUS_ACCEPTED, $data
                    ))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('return')
                    ->label('Вернуть на доработку')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Вернуть выбранные работы на доработку')
                    ->form(static::reviewCommentFields(HomeworkSubmission::STATUS_NEEDS_REVISION, bodyRequired: true))
                    ->action(fn (Collection $records, array $data) => static::runBulkReview(
                        $records, HomeworkSubmission::STATUS_NEEDS_REVISION, $data
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomeworkSubmissions::route('/'),
            'view' => Pages\ViewHomeworkSubmission::route('/{record}'),
        ];
    }
}
