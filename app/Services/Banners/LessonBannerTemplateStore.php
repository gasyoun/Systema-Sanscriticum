<?php

declare(strict_types=1);

namespace App\Services\Banners;

use App\Models\LessonBannerTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Единственная точка заведения шаблона плашки: фон + spec (+ PSD для учёта).
 *
 * Новый шаблон для той же пары (курс, группа) не правит старый, а встаёт
 * рядом с версией max+1; прежние гасятся (is_active=false). Так смена
 * шаблона сама перерисовывает все будущие плашки (версия входит в
 * render_hash), а история остаётся для разбора.
 */
final class LessonBannerTemplateStore
{
    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];

    public function store(
        int $courseId,
        ?int $groupId,
        UploadedFile $background,
        string $specJson,
        ?UploadedFile $psd = null,
        ?UploadedFile $overlay = null,
    ): LessonBannerTemplate {
        $info = @getimagesize($background->getRealPath());
        if ($info === false || ! isset(self::IMAGE_TYPES[$info[2]])) {
            throw new InvalidArgumentException('Фон должен быть PNG или JPEG.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];

        $spec = self::validateSpec($specJson, $width, $height);

        $bgDisk = (string) config('lesson_banners.template_disk', 'public');
        $bgPath = trim((string) config('lesson_banners.template_dir', 'lesson-banner-templates'), '/')
            .'/course-'.$courseId.($groupId ? '-group-'.$groupId : '')
            .'-'.Str::lower(Str::random(8)).'.'.self::IMAGE_TYPES[$info[2]];
        Storage::disk($bgDisk)->putFileAs(dirname($bgPath), $background, basename($bgPath));

        // Верхний слой — только PNG (нужна прозрачность) и ровно того же размера,
        // что фон: иначе фото преподавателя съедет относительно подложки.
        $overlayPath = null;
        if ($overlay !== null) {
            $oInfo = @getimagesize($overlay->getRealPath());
            if ($oInfo === false || $oInfo[2] !== IMAGETYPE_PNG) {
                throw new InvalidArgumentException('Верхний слой должен быть PNG с прозрачностью.');
            }
            if ((int) $oInfo[0] !== $width || (int) $oInfo[1] !== $height) {
                throw new InvalidArgumentException("Верхний слой {$oInfo[0]}×{$oInfo[1]} не совпадает с фоном {$width}×{$height}.");
            }
            $overlayPath = preg_replace('/\.(png|jpg)$/', '-overlay.png', $bgPath);
            Storage::disk($bgDisk)->putFileAs(dirname((string) $overlayPath), $overlay, basename((string) $overlayPath));
        }

        if ($this->hasUnderFields($spec) && $overlayPath === null) {
            throw new InvalidArgumentException('spec: есть поля "layer":"under", но не загружен верхний слой (overlay.png).');
        }

        $psdDisk = null;
        $psdPath = null;
        if ($psd !== null) {
            $psdDisk = (string) config('lesson_banners.psd_disk', 'local');
            $psdPath = trim((string) config('lesson_banners.psd_dir', 'lesson-banner-psd'), '/')
                .'/course-'.$courseId.($groupId ? '-group-'.$groupId : '').'-'.Str::lower(Str::random(8)).'.psd';
            Storage::disk($psdDisk)->putFileAs(dirname($psdPath), $psd, basename($psdPath));
        }

        return DB::transaction(function () use ($courseId, $groupId, $bgDisk, $bgPath, $overlayPath, $width, $height, $spec, $psdDisk, $psdPath, $psd) {
            $siblings = LessonBannerTemplate::query()
                ->where('course_id', $courseId)
                ->when($groupId === null, fn ($q) => $q->whereNull('group_id'), fn ($q) => $q->where('group_id', $groupId));

            $version = ((int) (clone $siblings)->max('version')) + 1;
            (clone $siblings)->update(['is_active' => false]);

            return LessonBannerTemplate::create([
                'course_id' => $courseId,
                'group_id' => $groupId,
                'background_disk' => $bgDisk,
                'background_path' => $bgPath,
                'overlay_disk' => $overlayPath !== null ? $bgDisk : null,
                'overlay_path' => $overlayPath,
                'width' => $width,
                'height' => $height,
                'spec' => $spec,
                'psd_disk' => $psdDisk,
                'psd_path' => $psdPath,
                'psd_original_name' => $psd?->getClientOriginalName(),
                'version' => $version,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Положить файлы шрифтов в lesson_banners.fonts_dir (вне git — шрифты
     * коммерческие, репозиторий публичный). Имя файла сохраняется: на него
     * ссылается spec. Принимаются только TTF/OTF по сигнатуре, не по
     * расширению: FreeType на проде откроет что угодно с именем .ttf.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string> сохранённые имена
     */
    public function storeFonts(array $files): array
    {
        $dir = rtrim((string) config('lesson_banners.fonts_dir'), '/\\');
        File::ensureDirectoryExists($dir);

        $stored = [];
        foreach ($files as $file) {
            $name = basename((string) $file->getClientOriginalName());
            if (! preg_match('/^[A-Za-z0-9._ -]+\.(ttf|otf)$/i', $name)) {
                throw new InvalidArgumentException("Шрифт «{$name}»: нужно латинское имя файла .ttf/.otf — на него ссылается template.json.");
            }

            $magic = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);
            if (! in_array($magic, ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
                throw new InvalidArgumentException("Файл «{$name}» — не TTF/OTF.");
            }

            File::copy($file->getRealPath(), $dir.DIRECTORY_SEPARATOR.$name);
            $stored[] = $name;
        }

        return $stored;
    }

    /**
     * Шрифты spec, которых нет ни по абсолютному пути, ни в fonts_dir —
     * такие поля нарисуются запасным шрифтом.
     *
     * @return list<string>
     */
    public static function missingFonts(LessonBannerTemplate $template): array
    {
        $dir = rtrim((string) config('lesson_banners.fonts_dir'), '/\\');
        $missing = [];
        foreach (LessonBannerTemplate::FIELDS as $name) {
            $font = (string) ($template->field($name)['font'] ?? '');
            if ($font !== '' && ! is_file($font) && ! is_file($dir.DIRECTORY_SEPARATOR.$font)) {
                $missing[] = $font;
            }
        }

        return array_values(array_unique($missing));
    }

    /** @param  array<string, mixed>  $spec */
    private function hasUnderFields(array $spec): bool
    {
        foreach ((array) ($spec['fields'] ?? []) as $field) {
            if (is_array($field) && ($field['layer'] ?? 'over') === 'under') {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить spec (вывод scripts/banner_template_from_psd.py или ручной) и
     * вернуть его массивом. Поле, вылезающее за фон, — ошибка: такой шаблон
     * молча рисовал бы дату за краем картинки.
     *
     * @return array<string, mixed>
     */
    public static function validateSpec(string $json, int $width, int $height): array
    {
        $spec = json_decode($json, true);
        if (! is_array($spec) || ! is_array($spec['fields'] ?? null)) {
            throw new InvalidArgumentException('spec: ожидается JSON с ключом "fields".');
        }

        foreach (LessonBannerTemplate::FIELDS as $name) {
            if (! is_array($spec['fields'][$name] ?? null)) {
                throw new InvalidArgumentException("spec: нет поля «{$name}».");
            }
        }

        foreach ($spec['fields'] as $name => $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException("spec: поле «{$name}» должно быть объектом.");
            }

            $source = (string) ($field['source'] ?? $name);
            if (! in_array($source, LessonBannerTemplate::FIELDS, true)) {
                throw new InvalidArgumentException("spec: у поля «{$name}» source — date|number.");
            }
            if (isset($field['layer']) && ! in_array($field['layer'], ['under', 'over'], true)) {
                throw new InvalidArgumentException("spec: у поля «{$name}» layer — under|over.");
            }
            if (isset($field['blend']) && ! in_array($field['blend'], ['normal', 'soft_light'], true)) {
                throw new InvalidArgumentException("spec: у поля «{$name}» blend — normal|soft_light.");
            }
            if (isset($field['opacity']) && (! is_numeric($field['opacity']) || $field['opacity'] < 0 || $field['opacity'] > 1)) {
                throw new InvalidArgumentException("spec: у поля «{$name}» opacity — число от 0 до 1.");
            }
            if (isset($field['badge']) && (! is_array($field['badge']) || ($field['badge']['shape'] ?? 'circle') !== 'circle')) {
                throw new InvalidArgumentException("spec: у поля «{$name}» badge — {\"shape\":\"circle\",\"diameter\":N}.");
            }

            foreach (['x', 'y', 'w', 'h', 'size_px'] as $key) {
                if (! is_numeric($field[$key] ?? null)) {
                    throw new InvalidArgumentException("spec: у поля «{$name}» нет числа «{$key}».");
                }
            }

            if ((float) $field['w'] <= 0 || (float) $field['h'] <= 0 || (float) $field['size_px'] <= 0) {
                throw new InvalidArgumentException("spec: у поля «{$name}» ширина, высота и кегль должны быть больше нуля.");
            }

            // Водяной знак под верхним слоем по дизайну может уходить за край
            // (у Кочергиной «40» обрезана низом картинки) — ему это можно.
            $mayBleed = ($field['layer'] ?? 'over') === 'under';
            if (! $mayBleed && ((float) $field['x'] < 0 || (float) $field['y'] < 0
                || (float) $field['x'] + (float) $field['w'] > $width
                || (float) $field['y'] + (float) $field['h'] > $height)) {
                throw new InvalidArgumentException("spec: поле «{$name}» выходит за фон {$width}×{$height}.");
            }

            if (isset($field['align']) && ! in_array($field['align'], ['left', 'center', 'right'], true)) {
                throw new InvalidArgumentException("spec: у поля «{$name}» align — left|center|right.");
            }

            if ($source === 'number' && ! str_contains((string) ($field['format'] ?? 'Занятие {N}'), '{N}')) {
                throw new InvalidArgumentException("spec: формат номера в поле «{$name}» должен содержать {N}.");
            }
        }

        return $spec;
    }
}
