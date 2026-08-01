<?php

namespace App\Filament\Resources\HomeworkSubmissionResource\Pages;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Models\HomeworkSubmission;
use App\Services\HomeworkService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewHomeworkSubmission extends ViewRecord
{
    protected static string $resource = HomeworkSubmissionResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Работа')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('Студент'),
                    TextEntry::make('course.title')->label('Курс'),
                    TextEntry::make('lesson.title')->label('Урок'),
                    TextEntry::make('status')
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
                ]),

            Section::make('Переписка и файлы')
                ->schema([
                    ViewEntry::make('thread')
                        ->view('filament.homework.thread'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept')
                ->label('Принять')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord()))
                ->form([
                    ...HomeworkSubmissionResource::reviewCommentFields(
                        HomeworkSubmission::STATUS_ACCEPTED,
                        bodyRequired: false,
                        draftKey: HomeworkSubmissionResource::reviewDraftKey($this->getRecord(), HomeworkSubmission::STATUS_ACCEPTED),
                    ),
                    $this->feedbackFilesField(),
                ])
                ->action(fn (array $data) => $this->review(HomeworkSubmission::STATUS_ACCEPTED, $data)),

            Action::make('return')
                ->label('Вернуть на доработку')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord()))
                ->form([
                    ...HomeworkSubmissionResource::reviewCommentFields(
                        HomeworkSubmission::STATUS_NEEDS_REVISION,
                        bodyRequired: true,
                        draftKey: HomeworkSubmissionResource::reviewDraftKey($this->getRecord(), HomeworkSubmission::STATUS_NEEDS_REVISION),
                    ),
                    $this->feedbackFilesField(),
                ])
                ->action(fn (array $data) => $this->review(HomeworkSubmission::STATUS_NEEDS_REVISION, $data)),

            // H2120: ошибочно залитое ДЗ (не тот урок / не тот файл) —
            // стереть студенческие вложения и открыть приём на правку.
            Action::make('clearStudentFiles')
                ->label('Стереть файлы студента')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord()))
                ->requiresConfirmation()
                ->modalHeading('Стереть залитые файлы студента?')
                ->modalDescription('Удалятся все вложения студента (с диска). Работа перейдёт в «На доработку», чтобы студент мог прикрепить файлы заново. Файлы и комментарии проверяющего сохранятся.')
                ->modalSubmitActionLabel('Стереть')
                ->action(function (): void {
                    $deleted = app(HomeworkService::class)->clearStudentFiles(
                        $this->getRecord(),
                        auth()->user(),
                        reopenForRevision: true,
                    );

                    Notification::make()
                        ->success()
                        ->title($deleted > 0
                            ? "Удалено файлов: {$deleted}. Работа открыта на доработку."
                            : 'Файлов студента не было. Работа открыта на доработку.')
                        ->send();

                    $this->redirect(HomeworkSubmissionResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
        ];
    }

    private function feedbackFilesField(): FileUpload
    {
        return FileUpload::make('feedback_files')
            ->label('Файлы с правками (необязательно)')
            ->multiple()
            ->disk('local')
            ->directory('homework/feedback/'.$this->getRecord()->id)
            ->preserveFilenames()
            ->maxSize(30720)
            ->maxFiles(10);
    }

    private function review(string $status, array $data): void
    {
        $files = [];
        foreach (($data['feedback_files'] ?? []) as $path) {
            if (! Storage::disk('local')->exists($path)) {
                continue;
            }
            $files[] = [
                'disk' => 'local',
                'path' => $path,
                'original_name' => basename($path),
                'size' => Storage::disk('local')->size($path),
                'mime' => Storage::disk('local')->mimeType($path),
            ];
        }

        app(HomeworkService::class)->recordReview(
            $this->getRecord(),
            auth()->user(),
            $status,
            $data['body'] ?? null,
            $files,
        );

        Notification::make()
            ->success()
            ->title($status === HomeworkSubmission::STATUS_ACCEPTED ? 'Работа принята' : 'Возвращено на доработку')
            ->send();

        $this->redirect(HomeworkSubmissionResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
