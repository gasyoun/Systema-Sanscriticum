<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Awcodes\Curator\Models\Media;

class MigrateMediaToCurator extends Command
{
    protected $signature = 'app:migrate-media';
    protected $description = 'Безопасный перенос путей файлов в медиатеку Curator';

    protected array $map = [
        'courses'       => 'image',
        'lessons'       => 'image',
        'landing_pages' => 'image_path',
        'tariffs'       => 'image',
        'announcements' => 'image_path',
    ];

    public function handle()
    {
        $this->info('🚀 Начинаем БЕЗОПАСНУЮ миграцию медиафайлов...');

        foreach ($this->map as $table => $oldColumn) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $oldColumn) || !Schema::hasColumn($table, 'image_id')) {
                $this->warn("⚠️ Пропуск {$table}: нет таблицы, колонки {$oldColumn} или новой колонки image_id.");
                continue;
            }

            $records = DB::table($table)
                ->whereNotNull($oldColumn)
                ->whereNull('image_id')
                ->get();
            
            if ($records->isEmpty()) {
                $this->line("📍 В таблице {$table} нет новых файлов для переноса.");
                continue;
            }

            $this->info("📦 Обработка: {$table} (нашли {$records->count()} файлов)");
            $bar = $this->output->createProgressBar($records->count());
            $bar->start();

            foreach ($records as $record) {
                $path = $record->$oldColumn;
                
                $mediaId = $this->getOrCreateMedia($path);

                if ($mediaId) {
                    DB::table($table)->where('id', $record->id)->update([
                        'image_id' => $mediaId
                    ]);
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
        }

        $this->info('✅ Миграция завершена! Ни один файл не пострадал.');
    }

    private function getOrCreateMedia(?string $path)
    {
        if (!$path) return null;

        $cleanPath = str_replace(['/storage/', 'storage/'], '', $path);

        if (!Storage::disk('public')->exists($cleanPath)) {
            return null; 
        }

        $existing = Media::where('path', $cleanPath)->first();
        if ($existing) {
            return $existing->id;
        }

        try {
            $fullPath = Storage::disk('public')->path($cleanPath);
            $mime = Storage::disk('public')->mimeType($cleanPath);
            $size = Storage::disk('public')->size($cleanPath);
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            $name = pathinfo($fullPath, PATHINFO_FILENAME);

            $width = $height = null;
            if (str_starts_with($mime, 'image/')) {
                $dims = @getimagesize($fullPath);
                $width = $dims[0] ?? null;
                $height = $dims[1] ?? null;
            }

            $media = Media::create([
                'disk' => 'public',
                'directory' => dirname($cleanPath) === '.' ? '' : dirname($cleanPath),
                'name' => $name,
                'original_name' => basename($cleanPath),
                'ext' => $ext,
                'mime_type' => $mime,
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'path' => $cleanPath,
            ]);

            return $media->id;
        } catch (\Exception $e) {
            \Log::error("Ошибка добавления {$cleanPath} в Curator: " . $e->getMessage());
            return null;
        }
    }
}