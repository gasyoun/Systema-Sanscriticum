<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Баннер курса в одном из форматов (16:9 / 9:16 / 4:3) + исходник PSD к нему.
 *
 * Ровно одна строка на пару (курс, формат) — держится unique-индексом в БД,
 * см. докблок миграции. Писать сюда напрямую не нужно: единственная точка
 * записи — App\Services\Design\CourseDesignAssetService, она же чистит
 * замещённые файлы со Storage.
 */
class CourseDesignAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'format',
        'disk',
        'path',
        'original_name',
        'size',
        'mime',
        'width',
        'height',
        'psd_url',
        'psd_disk',
        'psd_path',
        'psd_original_name',
        'psd_size',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'psd_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Кто последним сдал этот слот. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Формат в виде, пригодном для имени файла: «16:9» → «16x9».
     * Двоеточие недопустимо в именах файлов на Windows и ломает ZIP-записи.
     */
    public function formatSlug(): string
    {
        return self::slugForFormat($this->format);
    }

    public static function slugForFormat(string $format): string
    {
        return str_replace(':', 'x', $format);
    }

    /**
     * Ожидаемое соотношение сторон как число: «16:9» → 1.777…
     * Возвращает null для нераспознанной строки — вызывающий тогда просто не
     * сверяет пропорцию (учёт не должен падать из-за экзотического формата).
     */
    public static function expectedRatio(string $format): ?float
    {
        if (! preg_match('/^(\d+):(\d+)$/', $format, $m)) {
            return null;
        }

        $h = (float) $m[2];

        return $h > 0.0 ? ((float) $m[1]) / $h : null;
    }

    /** Учтён ли исходник: ссылка на облако ИЛИ залитый файл. */
    public function hasPsd(): bool
    {
        return filled($this->psd_url) || filled($this->psd_path);
    }

    /**
     * Совпадает ли реальная пропорция картинки с заявленным форматом.
     * true — совпадает или сверить нечем (нет размеров/неизвестный формат):
     * бейдж расхождения показываем только когда расхождение доказано.
     */
    public function ratioMatches(): bool
    {
        $expected = self::expectedRatio($this->format);
        if ($expected === null || ! $this->width || ! $this->height) {
            return true;
        }

        $actual = $this->width / $this->height;
        $tolerance = (float) config('design_assets.ratio_tolerance', 0.02);

        return abs($actual - $expected) <= $expected * $tolerance;
    }

    /** Публичная ссылка на картинку (диск публичный — отдаём напрямую). */
    public function imageUrl(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }
}
