<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Awcodes\Curator\Models\Media;

class MigrateBuilderMedia extends Command
{
    protected $signature = 'app:migrate-builder';
    protected $description = 'Перенос картинок из JSON-блоков конструктора в Curator';

    public function handle()
    {
        $this->info('🚀 Начинаем сканирование JSON-блоков в landing_pages...');

        $pages = DB::table('landing_pages')->whereNotNull('content')->get();

        if ($pages->isEmpty()) {
            $this->info('Лендинги с контентом не найдены.');
            return;
        }

        $bar = $this->output->createProgressBar($pages->count());
        $bar->start();

        foreach ($pages as $page) {
            $content = json_decode($page->content, true);
            
            if (!is_array($content)) {
                $bar->advance();
                continue;
            }

            $updated = false;

            foreach ($content as &$block) {
                if (!isset($block['type']) || !isset($block['data'])) continue;

                $type = $block['type'];
                $data = &$block['data'];

                // УМНАЯ ФУНКЦИЯ: понимает и одиночные строки, и массивы путей (multiple)
                $replaceImage = function (&$array, $key) use (&$updated) {
                    if (empty($array[$key])) return;

                    // Если это массив путей (например, скриншоты в отзывах)
                    if (is_array($array[$key])) {
                        $newImages = [];
                        foreach ($array[$key] as $path) {
                            if (is_string($path)) {
                                $mediaId = $this->getOrCreateMedia($path);
                                if ($mediaId) {
                                    // Curator принимает ID как строки в JSON, но можно и как числа. Оставим строку для совместимости.
                                    $newImages[] = (string) $mediaId;
                                    $updated = true;
                                    continue;
                                }
                            }
                            $newImages[] = $path; // Если файла нет, оставляем как было
                        }
                        $array[$key] = $newImages;
                    } 
                    // Если это обычная строка (один файл)
                    elseif (is_string($array[$key])) {
                        $mediaId = $this->getOrCreateMedia($array[$key]);
                        if ($mediaId) {
                            $array[$key] = (string) $mediaId;
                            $updated = true;
                        }
                    }
                };

                // 1. Блок Результаты
                if ($type === 'results_block' && isset($data['items']) && is_array($data['items'])) {
                    foreach ($data['items'] as &$item) {
                        $replaceImage($item, 'icon');
                    }
                }
                // 2. Блок Баннер-призыв
                elseif ($type === 'cta_banner_block') {
                    $replaceImage($data, 'bg_image');
                }
                // 3. Блок Команда
                elseif ($type === 'team_block' && isset($data['items']) && is_array($data['items'])) {
                    foreach ($data['items'] as &$item) {
                        $replaceImage($item, 'image');
                    }
                }
                // 4. Блок О преподавателе
                elseif ($type === 'instructor_block') {
                    $replaceImage($data, 'image');
                    if (isset($data['publications']) && is_array($data['publications'])) {
                        foreach ($data['publications'] as &$pub) {
                            $replaceImage($pub, 'image');
                        }
                    }
                }
                // 5. Блок Главный экран (Спец дизайн)
                elseif ($type === 'new_paribok_hero') {
                    $replaceImage($data, 'logo_image');
                    $replaceImage($data, 'bg_image');
                    $replaceImage($data, 'clouds_image');
                    $replaceImage($data, 'speaker_image');
                }
                // 6. ВОТ ОН! Блок Отзывов
                elseif ($type === 'reviews_block' && isset($data['reviews']) && is_array($data['reviews'])) {
                    foreach ($data['reviews'] as &$review) {
                        $replaceImage($review, 'avatar'); // Аватарка (одно фото)
                        $replaceImage($review, 'images'); // Скриншоты (массив фото)
                    }
                }
            }

            if ($updated) {
                DB::table('landing_pages')->where('id', $page->id)->update([
                    'content' => json_encode($content, JSON_UNESCAPED_UNICODE)
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✅ JSON-блоки успешно обновлены!');
    }

    private function getOrCreateMedia(?string $path)
    {
        if (!$path || is_numeric($path)) return null; 

        $cleanPath = str_replace(['/storage/', 'storage/'], '', $path);

        if (!Storage::disk('public')->exists($cleanPath)) return null;

        $existing = Media::where('path', $cleanPath)->first();
        if ($existing) return $existing->id;

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