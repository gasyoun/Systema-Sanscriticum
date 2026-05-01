<?php

declare(strict_types=1);

namespace App\Filament\Editor\Resources\LectureDraftResource\Pages;

use App\Filament\Editor\Resources\LectureDraftResource;
use App\Jobs\BuildLectureHtmlJob;
use App\Jobs\PreprocessLectureDraftJob;
use App\Models\Course;
use App\Models\LectureDraft;
use App\Models\Lesson;
use App\Services\Lecture\LectureAiClient;
use App\Services\Lecture\LecturePublisher;
use App\Services\Lecture\LectureStorage;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLectureDraft extends EditRecord
{
    protected static string $resource = LectureDraftResource::class;

    protected function getHeaderActions(): array
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();

        return [
            Actions\Action::make('preprocess')
                ->label('Препроцесс (PDF + транскрипт)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($draft->status, [
                    LectureDraft::STATUS_DRAFT,
                    LectureDraft::STATUS_EDITING,
                ], true))
                ->form([
                    Forms\Components\FileUpload::make('pdf')
                        ->label('PDF слайдов (опционально)')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(50 * 1024)
                        ->disk('local')
                        ->directory('tmp/lecture-uploads')
                        ->visibility('private'),
                    Forms\Components\FileUpload::make('transcript')
                        ->label('Транскрипт (TXT с разметкой или JSON)')
                        ->required()
                        ->acceptedFileTypes(['text/plain', 'application/json', 'text/x-markdown'])
                        ->maxSize(20 * 1024)
                        ->disk('local')
                        ->directory('tmp/lecture-uploads')
                        ->visibility('private'),
                ])
                ->action(fn (array $data) => $this->runPreprocess($data)),

            Actions\Action::make('build')
                ->label('Собрать HTML')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('info')
                ->visible(fn () => $draft->status === LectureDraft::STATUS_EDITING)
                ->requiresConfirmation()
                ->action(fn () => $this->runBuild()),

            Actions\ActionGroup::make([
                Actions\Action::make('ai_structure')
                    ->label('🤖 Разбить на разделы')
                    ->icon('heroicon-o-list-bullet')
                    ->form([
                        Forms\Components\Textarea::make('hint')
                            ->label('Уточнения для ИИ (опционально)')
                            ->rows(3)
                            ->placeholder('Например: «Делай разделы покрупнее, минимум 8-10 минут на раздел»')
                            ->helperText('Можно оставить пустым — ИИ сам предложит структуру.'),
                    ])
                    ->action(fn (array $data) => $this->runAi('structure', $data['hint'] ?? '')),

                Actions\Action::make('ai_correct')
                    ->label('🤖 Корректура текста')
                    ->icon('heroicon-o-pencil')
                    ->form([
                        Forms\Components\Textarea::make('hint')
                            ->label('Уточнения для ИИ')
                            ->rows(3)
                            ->placeholder('Например: «Особое внимание на санскритские термины, написание имён лекторов»'),
                        Forms\Components\TextInput::make('max_paragraphs')
                            ->label('Лимит абзацев (0 = все)')
                            ->numeric()
                            ->default(0)
                            ->helperText('Полный прогон ~103 абзацев = долго и дорого. Для теста ставьте 5.'),
                    ])
                    ->action(fn (array $data) => $this->runAi(
                        'correct',
                        $data['hint'] ?? '',
                        ['max_paragraphs' => (int) ($data['max_paragraphs'] ?? 0)],
                    )),

                Actions\Action::make('ai_place_slides')
                    ->label('🤖 Расставить слайды')
                    ->icon('heroicon-o-photo')
                    ->form([
                        Forms\Components\Textarea::make('hint')
                            ->label('Уточнения для ИИ')
                            ->rows(3)
                            ->placeholder('Например: «Слайд 1 — это титульный, после первого предложения»'),
                    ])
                    ->action(fn (array $data) => $this->runAi('place_slides', $data['hint'] ?? '')),

                Actions\Action::make('ai_timecodes')
                    ->label('🤖 Сверить таймкоды (YT)')
                    ->icon('heroicon-o-clock')
                    ->form([
                        Forms\Components\Textarea::make('hint')
                            ->label('Уточнения для ИИ')
                            ->rows(3),
                    ])
                    ->action(fn (array $data) => $this->runAi('timecodes', $data['hint'] ?? '')),
            ])
                ->label('🤖 ИИ Claude')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->button()
                ->visible(fn () => in_array($draft->status, [
                    LectureDraft::STATUS_EDITING,
                    LectureDraft::STATUS_BUILT,
                ], true)),

            Actions\Action::make('preview')
                ->label('Открыть редактор')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->url(fn () => route('editor.lecture.preview', $draft), shouldOpenInNewTab: true)
                ->visible(fn () => in_array($draft->status, [
                    LectureDraft::STATUS_EDITING,
                    LectureDraft::STATUS_BUILT,
                ], true) && $draft->output_html_path !== null),

            Actions\Action::make('publish')
                ->label('Опубликовать')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn () => $draft->status === LectureDraft::STATUS_BUILT)
                ->form([
                    Forms\Components\Select::make('lesson_id')
                        ->label('Привязать к уроку')
                        ->required()
                        ->searchable()
                        ->options(function () use ($draft) {
                            $query = Lesson::query()->orderByDesc('id')->limit(200);
                            if ($draft->course_id) {
                                $query->where('course_id', $draft->course_id);
                            }
                            return $query->pluck('title', 'id');
                        })
                        ->helperText('После публикации ссылка попадёт в transcript_file урока'),
                ])
                ->action(fn (array $data) => $this->runPublish((int) $data['lesson_id'])),

            Actions\DeleteAction::make()
                ->after(function (LectureDraft $record) {
                    app(LectureStorage::class)->deleteWorkingDir($record);
                }),
        ];
    }

    public function form(Form $form): Form
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();
        $isPublished = $draft->status === LectureDraft::STATUS_PUBLISHED;

        return $form->schema([
            Forms\Components\Placeholder::make('status_hint')
                ->label('Текущий статус')
                ->content(fn () => $this->statusHint($draft)),

            Forms\Components\Placeholder::make('error_log_show')
                ->label('Последняя ошибка')
                ->visible(fn () => !empty($draft->error_log))
                ->content(fn () => $draft->error_log),

            Forms\Components\Section::make('Метаданные лекции')
                ->disabled($isPublished)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Название')->required()->columnSpanFull(),
                    Forms\Components\Select::make('course_id')
                        ->label('Курс')
                        ->relationship('course', 'title')
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('meta.lesson_number')->label('Номер занятия')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('meta.lecturer')->label('Лектор'),
                    Forms\Components\TextInput::make('meta.host')->label('Ведущий'),
                    Forms\Components\TextInput::make('meta.video.youtube')->label('YouTube URL')->url(),
                    Forms\Components\TextInput::make('meta.video.rutube')->label('Rutube URL')->url(),
                    Forms\Components\TextInput::make('meta.date_display')->label('Дата'),
                    Forms\Components\TextInput::make('meta.period')->label('Период курса'),
                ])->columns(2),
        ]);
    }

    private function statusHint(LectureDraft $draft): string
    {
        return match ($draft->status) {
            LectureDraft::STATUS_DRAFT         => '🟡 Черновик. Нажмите «Препроцесс», чтобы загрузить PDF слайдов и транскрипт.',
            LectureDraft::STATUS_PREPROCESSING => '⏳ Препроцесс выполняется…',
            LectureDraft::STATUS_EDITING       => '✍️ Препроцесс выполнен, но HTML ещё не собран. Нажмите «Собрать HTML», чтобы открыть редактор.',
            LectureDraft::STATUS_BUILT         => '✅ HTML собран. Нажмите «Открыть редактор» — там можно править параграфы прямо в браузере и сохранять.',
            LectureDraft::STATUS_PUBLISHED     => '🚀 Опубликовано. Доступно по адресу /lectures/' . $draft->slug . '/.',
            default                            => $draft->status,
        };
    }

    private function runPreprocess(array $data): void
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();
        $storage = app(LectureStorage::class);

        $rawDir = $storage->ensureRawDir($draft);

        $transcriptName = $this->moveUploadedFile($data['transcript'] ?? null, $rawDir);
        $pdfName        = $this->moveUploadedFile($data['pdf'] ?? null, $rawDir);

        if ($transcriptName === null) {
            Notification::make()->title('Не загружен транскрипт')->danger()->send();
            return;
        }

        $lessonNumber = (int) ($draft->meta['lesson_number'] ?? 1);

        try {
            PreprocessLectureDraftJob::dispatchSync(
                draftId: $draft->id,
                rawTranscriptName: $transcriptName,
                rawPdfName: $pdfName,
                lessonNumber: $lessonNumber,
            );
            // Первая сборка HTML сразу же — чтобы у редактора было что смотреть
            BuildLectureHtmlJob::dispatchSync($draft->id);
            Notification::make()->title('Готово: препроцесс + первая сборка')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Ошибка препроцесса')->body($e->getMessage())->danger()->persistent()->send();
        }

        $this->refreshFormData(['status', 'error_log', 'data_json_path', 'slides_dir', 'output_html_path']);
    }

    /**
     * Запускает одну из 4 AI-задач через lecture-builder.
     *
     * После успеха автоматически ребилдит HTML, чтобы редактор увидел изменения.
     * Все правки бэкапятся на стороне Python-сервиса в backups/{ts}_ai.json.
     */
    private function runAi(string $task, string $hint, array $extra = []): void
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();
        $storage = app(LectureStorage::class);
        $client = app(LectureAiClient::class);

        try {
            $absoluteWorkingDir = $storage->absoluteWorkingDir($draft);

            $result = match ($task) {
                'structure'    => $client->structure($absoluteWorkingDir, $hint, apply: true),
                'correct'      => $client->correct(
                    $absoluteWorkingDir,
                    $hint,
                    apply: true,
                    maxParagraphs: (int) ($extra['max_paragraphs'] ?? 0),
                ),
                'place_slides' => $client->placeSlides($absoluteWorkingDir, $hint, apply: true),
                'timecodes'    => $client->verifyTimecodes($absoluteWorkingDir, $hint, apply: true),
                default        => throw new \InvalidArgumentException("Неизвестная AI-задача: {$task}"),
            };

            // После применения правок к data.json — пересобираем HTML
            BuildLectureHtmlJob::dispatchSync($draft->id);

            $body = $result['summary'] ?? 'Готово';
            if (!empty($result['usage'])) {
                $u = $result['usage'];
                $body .= sprintf("\nТокены: in=%d, out=%d", $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0);
            }
            if (!empty($result['backup'])) {
                $body .= "\nБэкап: " . $result['backup'];
            }

            Notification::make()
                ->title('ИИ применил правки')
                ->body($body)
                ->success()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Ошибка ИИ')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }

        $this->refreshFormData(['status', 'error_log', 'output_html_path']);
    }

    private function runBuild(): void
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();

        try {
            BuildLectureHtmlJob::dispatchSync($draft->id);
            Notification::make()->title('HTML собран')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Ошибка сборки')->body($e->getMessage())->danger()->persistent()->send();
        }

        $this->refreshFormData(['status', 'error_log', 'output_html_path']);
    }

    private function runPublish(int $lessonId): void
    {
        /** @var LectureDraft $draft */
        $draft = $this->getRecord();

        try {
            $result = app(LecturePublisher::class)->publish($draft, $lessonId);
            Notification::make()
                ->title('Лекция опубликована')
                ->body('URL: ' . $result['public_url'])
                ->success()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Ошибка публикации')->body($e->getMessage())->danger()->persistent()->send();
        }

        $this->refreshFormData(['status', 'lesson_id']);
    }

    /**
     * Перемещает загруженный Filament FileUpload в raw/ и возвращает имя файла.
     * Возвращает null, если файл не передан.
     */
    private function moveUploadedFile(mixed $value, string $rawDir): ?string
    {
        if (empty($value)) {
            return null;
        }

        // FileUpload возвращает строку (одиночный файл) или массив
        $relativePath = is_array($value) ? array_values($value)[0] ?? null : $value;
        if (!$relativePath) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (!$disk->exists($relativePath)) {
            throw new \RuntimeException("Загруженный файл не найден: {$relativePath}");
        }

        $basename = basename($relativePath);
        $targetAbs = $rawDir . DIRECTORY_SEPARATOR . $basename;

        // Перенос (атомарный rename внутри одного диска)
        rename($disk->path($relativePath), $targetAbs);

        return $basename;
    }
}
