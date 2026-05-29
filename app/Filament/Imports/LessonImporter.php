<?php

namespace App\Filament\Imports;

use App\Models\Lesson;
use Carbon\Carbon;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
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
                ->rules(['required', 'integer', 'min:1', 'max:200'])
                ->castStateUsing(fn ($state): ?int => ($state === null || trim((string) $state) === '') ? null : (int) $state
                )
                ->label('Блок (просто цифра: 1, 2, 3 или 4)'),

            ImportColumn::make('title')
                ->requiredMapping()
                ->label('Название урока'),

            ImportColumn::make('lesson_date')
                ->requiredMapping()
                ->label('Дата урока')
                // Простой и надежный парсер, который не крашит систему
                ->castStateUsing(function ($state): ?string {
                    if (empty($state)) {
                        return now()->format('Y-m-d');
                    }
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
        $youtube = $this->data['youtube_url'] ?? null;
        $rutube = $this->data['rutube_url'] ?? null;

        // ВАЖНО: дедуплицировать по title нельзя — в одном блоке легко встречаются
        // несколько уроков с одинаковым названием (напр. два «Кочергина 10 (проверка)»).
        // Поиск по title тихо перезаписывал бы соседний урок, и часть занятий «пропадала».
        // Естественный уникальный ключ урока — ссылка на видео.
        if (! empty($youtube)) {
            $lesson = Lesson::firstOrNew(['youtube_url' => $youtube]);
        } elseif (! empty($rutube)) {
            $lesson = Lesson::firstOrNew(['rutube_url' => $rutube]);
        } else {
            // Видео нет — собираем составной ключ, чтобы одинаковые заголовки
            // в разных блоках/датах оставались разными уроками.
            $lesson = Lesson::firstOrNew([
                'title' => $title,
                'block_number' => $this->data['block_number'] ?? null,
                'lesson_date' => $this->data['lesson_date'] ?? null,
            ]);
        }

        if (! $lesson->exists) {
            $lesson->slug = Str::slug($title).'-'.rand(10000, 99999);
        }

        return $lesson;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Импорт уроков завершен. Успешно загружено: '.number_format($import->successful_rows).'.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' Строк с ошибками: '.number_format($failedRowsCount).'. (Скачайте файл ошибок в уведомлениях!)';
        }

        return $body;
    }
}
