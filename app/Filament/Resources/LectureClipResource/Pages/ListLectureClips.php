<?php

declare(strict_types=1);

namespace App\Filament\Resources\LectureClipResource\Pages;

use App\Filament\Resources\LectureClipResource;
use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Lesson;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLectureClips extends ListRecords
{
    protected static string $resource = LectureClipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('dispatch_extract')
                ->label('Нарезать лекцию')
                ->icon('heroicon-o-scissors')
                ->visible(fn (): bool => config('features.clip_marketing') === true)
                ->form([
                    Forms\Components\Select::make('lesson_id')
                        ->label('Опубликованная лекция')
                        ->options(
                            fn () => Lesson::query()
                                ->where('is_published', true)
                                ->orderByDesc('id')
                                ->limit(200)
                                ->pluck('title', 'id')
                        )
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    DispatchLectureClipExtractionJob::dispatch((int) $data['lesson_id']);
                    Notification::make()
                        ->title('Запрос на нарезку поставлен в очередь')
                        ->body('n8n получит спаны из AI-таймкодов; клипы появятся после callback.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
