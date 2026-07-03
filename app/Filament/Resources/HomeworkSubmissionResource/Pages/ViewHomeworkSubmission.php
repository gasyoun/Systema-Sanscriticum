<?php

namespace App\Filament\Resources\HomeworkSubmissionResource\Pages;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Models\HomeworkFile;
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
                            HomeworkSubmission::STATUS_UNDER_REVIEW => 'Проверяется',
                            HomeworkSubmission::STATUS_NEEDS_CHANGES, HomeworkSubmission::LEGACY_STATUS_NEEDS_REVISION => 'На доработку',
                            HomeworkSubmission::STATUS_ACCEPTED => 'Принято',
                            HomeworkSubmission::STATUS_LATE => 'Просрочено',
                            HomeworkSubmission::STATUS_LOCKED => 'Закрыто',
                            default => $state,
                        })
                        ->color(fn (string $state): string => match ($state) {
                            HomeworkSubmission::STATUS_SUBMITTED => 'info',
                            HomeworkSubmission::STATUS_UNDER_REVIEW => 'warning',
                            HomeworkSubmission::STATUS_NEEDS_CHANGES, HomeworkSubmission::LEGACY_STATUS_NEEDS_REVISION => 'danger',
                            HomeworkSubmission::STATUS_ACCEPTED => 'success',
                            HomeworkSubmission::STATUS_LATE => 'danger',
                            HomeworkSubmission::STATUS_LOCKED => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('grade')
                        ->label('Оценка')
                        ->formatStateUsing(fn (?string $state): string => $state ? (HomeworkSubmission::gradeOptions()[$state] ?? $state) : '—')
                        ->placeholder('—'),
                    TextEntry::make('student_files')
                        ->label('Файлы студента')
                        ->state(fn (HomeworkSubmission $record): string => $record->studentFilesCount().' · '.HomeworkFile::humanSizeFor($record->studentFilesBytes())),
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
            Action::make('startReview')
                ->label('Начать проверку')
                ->icon('heroicon-o-eye')
                ->color('warning')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord())
                    && $this->getRecord()->status === HomeworkSubmission::STATUS_SUBMITTED)
                ->action(function (): void {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => HomeworkSubmission::STATUS_UNDER_REVIEW,
                        'review_started_at' => now(),
                        'last_activity_at' => now(),
                    ]);

                    Notification::make()->success()->title('Проверка начата')->send();
                    $this->redirect(HomeworkSubmissionResource::getUrl('view', ['record' => $record]));
                }),

            Action::make('accept')
                ->label('Принять')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord()))
                ->form([
                    ...HomeworkSubmissionResource::reviewCommentFields(HomeworkSubmission::STATUS_ACCEPTED, bodyRequired: false),
                    $this->feedbackFilesField(),
                ])
                ->action(fn (array $data) => $this->review(HomeworkSubmission::STATUS_ACCEPTED, $data)),

            Action::make('return')
                ->label('Вернуть на доработку')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => HomeworkSubmissionResource::canReview($this->getRecord()))
                ->form([
                    ...HomeworkSubmissionResource::reviewCommentFields(HomeworkSubmission::STATUS_NEEDS_CHANGES, bodyRequired: true),
                    $this->feedbackFilesField(),
                ])
                ->action(fn (array $data) => $this->review(HomeworkSubmission::STATUS_NEEDS_CHANGES, $data)),
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
            $data['grade'] ?? null,
        );

        Notification::make()
            ->success()
            ->title($status === HomeworkSubmission::STATUS_ACCEPTED ? 'Работа принята' : 'Возвращено на доработку')
            ->send();

        $this->redirect(HomeworkSubmissionResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
