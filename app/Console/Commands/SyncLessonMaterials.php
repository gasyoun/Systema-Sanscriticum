<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class SyncLessonMaterials extends Command
{
    protected $signature = 'materials:sync';
    protected $description = 'Массовая синхронизация материалов к урокам из транзитной папки';

    public function handle()
    {
        $this->info('Начинаем синхронизацию материалов...');

        // Папка, куда ты будешь заливать файлы по FTP
        $syncPath = storage_path('app/materials_sync');

        if (!File::exists($syncPath)) {
            File::makeDirectory($syncPath, 0775, true);
            $this->warn("Папка {$syncPath} не найдена. Я ее создал. Положи туда файлы и запусти скрипт снова.");
            return;
        }

        $coursesDirs = File::directories($syncPath);
        $totalFiles = 0;
        $updatedLessons = 0;

        foreach ($coursesDirs as $courseDir) {
            $courseId = basename($courseDir); // ID Курса
            
            if (!is_numeric($courseId)) {
                $this->warn("Пропускаю папку {$courseId} (имя должно быть ID курса)");
                continue;
            }

            $lessonsDirs = File::directories($courseDir);

            foreach ($lessonsDirs as $lessonDir) {
                $lessonId = basename($lessonDir); // ID Урока
                
                if (!is_numeric($lessonId)) continue;

                // Ищем урок в базе с двойной проверкой (урок + курс)
                $lesson = Lesson::where('id', $lessonId)->where('course_id', $courseId)->first();

                if (!$lesson) {
                    $this->error("Урок ID:{$lessonId} в Курсе ID:{$courseId} не найден в базе. Пропускаем.");
                    continue;
                }

                $files = File::files($lessonDir);
                if (empty($files)) continue;

                $materialsPaths = []; // Собираем пути к файлам

                foreach ($files as $file) {
                    $filename = $file->getFilename();
                    
                    // Постоянный путь хранения: storage/app/public/lesson_materials/{lesson_id}/filename.pdf
                    $publicPath = "lesson_materials/{$lessonId}/{$filename}";
                    
                    // Копируем файл в публичное хранилище (disk 'public')
                    Storage::disk('public')->put($publicPath, file_get_contents($file));
                    
                    $materialsPaths[] = $publicPath;
                    $totalFiles++;
                }

                // ВАЖНО: Предполагается, что колонка в БД называется 'materials'. Если иначе - переименуй здесь!
                $lesson->update([
                    'attachments' => $materialsPaths // Записываем массив путей в БД
                ]);
                $updatedLessons++;

                $this->line("✅ Обновлен урок ID: {$lessonId} (Добавлено файлов: " . count($files) . ")");
            }
        }

        $this->info("Синхронизация завершена! Обновлено уроков: {$updatedLessons}, загружено файлов: {$totalFiles}.");
    }
}