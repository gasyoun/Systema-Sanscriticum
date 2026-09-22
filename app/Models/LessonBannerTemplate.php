<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шаблон плашки занятия: фон + spec полей «дата» и «номер».
 *
 * spec (json):
 *   {
 *     "fields": {
 *       "date":   {"x":…, "y":…, "w":…, "h":…, "align":"left|center|right",
 *                  "font":"Montserrat-Bold.ttf", "size_px":48, "color":"#FFFFFF",
 *                  "tracking":0, "uppercase":false, "fit":true,
 *                  "format":"D MMMM"},                       // isoFormat, ru
 *       "number": {… те же ключи …, "format":"Занятие {N}",
 *                  "overview_text":"Обзорное занятие"}
 *     }
 *   }
 *
 * x/y/w/h — прямоугольник поля в пикселях фона; текст вписывается в него по
 * align по горизонтали и по центру по вертикали; fit=true ужимает кегль, если
 * текст не влезает по ширине (номер «12» шире «1»).
 *
 * Кроме date и number spec может описывать ДОПОЛНИТЕЛЬНЫЕ поля с "source":
 * "number"|"date" — например, водяной номер Кочергиной ("layer":"under",
 * "blend":"soft_light"). Номер в кружке — ключ "badge" (см. LessonBannerRenderer).
 */
class LessonBannerTemplate extends Model
{
    public const FIELDS = ['date', 'number'];

    protected $fillable = [
        'course_id',
        'group_id',
        'background_disk',
        'background_path',
        'overlay_disk',
        'overlay_path',
        'width',
        'height',
        'spec',
        'psd_disk',
        'psd_path',
        'psd_original_name',
        'version',
        'is_active',
    ];

    protected $casts = [
        'spec' => 'array',
        'width' => 'integer',
        'height' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function banners(): HasMany
    {
        return $this->hasMany(LessonBanner::class, 'template_id');
    }

    /**
     * Все поля spec: имя → [источник значения, описание]. Источник — ключ
     * "source" поля, для date/number по умолчанию — само имя.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public function fieldSources(): array
    {
        $out = [];
        foreach ((array) ($this->spec['fields'] ?? []) as $name => $field) {
            if (! is_array($field)) {
                continue;
            }
            $source = (string) ($field['source'] ?? $name);
            if (in_array($source, self::FIELDS, true)) {
                $out[(string) $name] = [$source, $field];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function field(string $name): array
    {
        $field = $this->spec['fields'][$name] ?? null;

        return is_array($field) ? $field : [];
    }

    /**
     * Шаблон для занятия: активный шаблон группы, иначе активный шаблон курса
     * без группы. Самый свежий по id, если заведено несколько.
     */
    public static function resolveFor(?int $courseId, ?int $groupId): ?self
    {
        if ($groupId !== null) {
            $forGroup = self::query()
                ->where('is_active', true)
                ->where('group_id', $groupId)
                ->latest('id')
                ->first();

            if ($forGroup !== null) {
                return $forGroup;
            }
        }

        if ($courseId === null) {
            return null;
        }

        return self::query()
            ->where('is_active', true)
            ->where('course_id', $courseId)
            ->whereNull('group_id')
            ->latest('id')
            ->first();
    }
}
