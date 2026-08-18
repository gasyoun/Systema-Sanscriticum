<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ЕДИНСТВЕННАЯ точка, где путь к картинке в БД меняется на webp-версию.
 *
 * И наблюдатель (сразу после загрузки), и ежедневная уборка ходят сюда, чтобы
 * порядок операций был один на всех: перекодировать -> записать путь в БД ->
 * и только потом удалить оригинал. Обратный порядок оставил бы курс с обложкой,
 * которой уже нет на диске, если запись в БД не доедет.
 */
class ModelImageWebpConverter
{
    public function __construct(private readonly WebpTranscoder $transcoder) {}

    /**
     * Перевести картинку модели в WebP и записать новый путь.
     *
     * Модель сохраняется через saveQuietly(): вызов идёт из наблюдателя на
     * `saved`, и обычный save() устроил бы рекурсию.
     */
    public function convert(Model $model, string $column): WebpTranscodeResult
    {
        $path = (string) ($model->getAttribute($column) ?? '');

        if ($path === '') {
            return WebpTranscodeResult::skipped($path, 'empty-column');
        }

        // Curator хранит в этой же колонке числовой id медиа-записи, а не путь
        // (см. layouts/promo.blade.php). Такие значения не наши.
        if (is_numeric($path)) {
            return WebpTranscodeResult::skipped($path, 'curator-id');
        }

        $result = $this->transcoder->transcode($path);

        if (! $result->converted || $result->target === null) {
            return $result;
        }

        $model->setAttribute($column, $result->target);
        $model->saveQuietly();

        if ((bool) config('media.webp.delete_original', true)) {
            $disk = (string) config('media.webp.disk', 'public');

            try {
                Storage::disk($disk)->delete($result->source);
            } catch (\Throwable $e) {
                // Путь уже переехал и страница работает — осиротевший файл
                // подметёт следующий прогон, ронять запрос из-за него нельзя.
                Log::warning('webp: original not deleted', [
                    'path' => $result->source,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
