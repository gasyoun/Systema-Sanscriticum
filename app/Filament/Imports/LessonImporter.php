<?php

namespace App\Filament\Imports;

use App\Models\Lesson;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LessonImporter extends Importer
{
    protected static ?string $model = Lesson::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('course')
                ->relationship(resolveUsing: 'title')
                ->requiredMapping()
                ->label('Название курса'),

            ImportColumn::make('block_number')
                ->requiredMapping()
                ->numeric()
                ->label('Блок (просто цифра: 1, 2, 3 или 4)'),

            ImportColumn::make('title')
                ->requiredMapping()
                ->label('Название урока'),

            ImportColumn::make('lesson_date')
                ->requiredMapping()
                ->label('Дата урока')
                // Простой и надежный парсер, который не крашит систему
                ->castStateUsing(function ($state): ?string {
                    if (empty($state)) return now()->format('Y-m-d');
                    try {
                        return Carbon::parse($state)->format('Y-m-d');
                    } catch (\Exception $e) {
                        return now()->format('Y-m-d');
                    }
                }),

            ImportColumn::make('topic')
                ->label('Описание / Тема'),

            ImportColumn::make('youtube_url')
                ->label('Ссылка YouTube'),

            ImportColumn::make('rutube_url')
                ->label('Ссылка Rutube'),
        ];
    }

    public function resolveRecord(): ?Lesson
    {
        $title = $this->data['title'] ?? 'Без названия';

        $lesson = Lesson::firstOrNew([
            'title' => $title,
        ]);

        if (!$lesson->exists) {
            $lesson->slug = Str::slug($title) . '-' . rand(10000, 99999);
        }

        return $lesson;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Импорт уроков завершен. Успешно загружено: ' . number_format($import->successful_rows) . '.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' Строк с ошибками: ' . number_format($failedRowsCount) . '. (Скачайте файл ошибок в уведомлениях!)';
        }

        return $body;
    }
}