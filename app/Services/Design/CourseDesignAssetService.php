<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Единственная точка записи баннеров курса и их PSD-исходников.
 *
 * Страница и архиватор только читают. Причина, по которой запись собрана здесь:
 * замещение слота обязано ещё и УДАЛИТЬ прежний файл со Storage — размазав это
 * по вызывающим, мы бы за год накопили мусор, который никто не свяжет с курсом.
 */
class CourseDesignAssetService
{
    /**
     * Создать или заместить слот (курс × формат).
     *
     * Контракт аргументов сознательно асимметричен, и вот почему:
     *  • $psdUrl — ПОЛНОЕ новое значение ссылки, null очищает её. Форма всегда
     *    предзаполнена текущими значениями, поэтому присланное = желаемое.
     *  • $psd — только НОВЫЙ файл; null означает «оставить прежний». Предзаполнить
     *    FileUpload файлом с приватного диска нельзя без костылей, поэтому снятие
     *    залитого исходника сделано отдельным действием (clearPsdFile).
     *
     * @throws InvalidArgumentException при неизвестном формате или при попытке
     *                                  создать слот без картинки
     */
    public function store(
        Course $course,
        string $format,
        ?UploadedFile $image,
        ?string $psdUrl,
        ?UploadedFile $psd,
        User $actor,
    ): CourseDesignAsset {
        $this->assertKnownFormat($format);

        /** @var CourseDesignAsset|null $existing */
        $existing = CourseDesignAsset::query()
            ->where('course_id', $course->id)
            ->where('format', $format)
            ->first();

        if (! $existing && ! $image) {
            throw new InvalidArgumentException('Для нового слота нужна картинка.');
        }

        $imageDisk = (string) config('design_assets.image_disk', 'public');
        $psdDisk = (string) config('design_assets.psd_disk', 'local');

        // Файлы пишем ДО строки, но старые удаляем только после успешной записи
        // в БД — иначе упавшая транзакция оставила бы слот без картинки вообще.
        $written = [];
        $attributes = [
            'psd_url' => $psdUrl,
            'uploaded_by_user_id' => $actor->id,
        ];

        try {
            if ($image) {
                $path = $this->put($image, $imageDisk, (string) config('design_assets.image_dir', 'course-design'), $course, $format);
                $written[] = [$imageDisk, $path];

                [$width, $height] = $this->dimensions($image);

                $attributes += [
                    'disk' => $imageDisk,
                    'path' => $path,
                    'original_name' => $image->getClientOriginalName(),
                    'size' => $image->getSize(),
                    'mime' => $image->getMimeType(),
                    'width' => $width,
                    'height' => $height,
                ];
            }

            if ($psd) {
                $psdPath = $this->put($psd, $psdDisk, (string) config('design_assets.psd_dir', 'course-design-psd'), $course, $format);
                $written[] = [$psdDisk, $psdPath];

                $attributes += [
                    'psd_disk' => $psdDisk,
                    'psd_path' => $psdPath,
                    'psd_original_name' => $psd->getClientOriginalName(),
                    'psd_size' => $psd->getSize(),
                ];
            }

            $asset = DB::transaction(fn (): CourseDesignAsset => CourseDesignAsset::updateOrCreate(
                ['course_id' => $course->id, 'format' => $format],
                $attributes,
            ));
        } catch (\Throwable $e) {
            // Строка не появилась — только что записанные файлы осиротели.
            foreach ($written as [$disk, $path]) {
                $this->forget($disk, $path);
            }

            throw $e;
        }

        // Замещённые файлы чистим последними: до этого момента они были рабочими.
        if ($image && $existing && filled($existing->path) && $existing->path !== $asset->path) {
            $this->forget((string) $existing->disk, (string) $existing->path);
        }
        if ($psd && $existing && filled($existing->psd_path) && $existing->psd_path !== $asset->psd_path) {
            $this->forget((string) $existing->psd_disk, (string) $existing->psd_path);
        }

        return $asset;
    }

    /** Снять слот целиком: строка и оба файла. */
    public function delete(CourseDesignAsset $asset): void
    {
        $image = [(string) $asset->disk, (string) $asset->path];
        $psd = [(string) $asset->psd_disk, (string) $asset->psd_path];

        $asset->delete();

        $this->forget($image[0], $image[1]);
        $this->forget($psd[0], $psd[1]);
    }

    /** Убрать залитый исходник, оставив картинку и ссылку на облако. */
    public function clearPsdFile(CourseDesignAsset $asset): void
    {
        $disk = (string) $asset->psd_disk;
        $path = (string) $asset->psd_path;

        $asset->update([
            'psd_disk' => null,
            'psd_path' => null,
            'psd_original_name' => null,
            'psd_size' => null,
        ]);

        $this->forget($disk, $path);
    }

    /**
     * Сводка для виджета и бейджа навигации.
     *
     * Считаем только по АКТИВНЫМ курсам: бейдж, который вечно горит красным
     * из-за архивных курсов, приучает не смотреть на бейдж.
     *
     * @return array{courses: int, complete: int, missingSlots: int, withoutPsd: int, ratioMismatch: int}
     */
    public function snapshot(): array
    {
        /** @var list<string> $formats */
        $formats = (array) config('design_assets.formats', []);

        $courses = Course::query()->where('is_active', true)->with('designAssets')->get();

        $complete = 0;
        $missingSlots = 0;
        $withoutPsd = 0;
        $ratioMismatch = 0;

        foreach ($courses as $course) {
            $readiness = $course->designReadiness();
            $missingSlots += count($readiness['missing']);
            if ($readiness['missing'] === []) {
                $complete++;
            }

            foreach ($course->designAssets as $asset) {
                if (! in_array($asset->format, $formats, true)) {
                    continue;
                }
                if (! $asset->hasPsd()) {
                    $withoutPsd++;
                }
                if (! $asset->ratioMatches()) {
                    $ratioMismatch++;
                }
            }
        }

        return [
            'courses' => $courses->count(),
            'complete' => $complete,
            'missingSlots' => $missingSlots,
            'withoutPsd' => $withoutPsd,
            'ratioMismatch' => $ratioMismatch,
        ];
    }

    private function assertKnownFormat(string $format): void
    {
        /** @var list<string> $formats */
        $formats = (array) config('design_assets.formats', []);

        if (! in_array($format, $formats, true)) {
            throw new InvalidArgumentException("Неизвестный формат баннера: {$format}");
        }
    }

    /**
     * Имя файла содержит курс, формат и случайный суффикс. Случайность нужна не
     * ради секретности (картинки публичны), а чтобы замещение слота меняло URL —
     * иначе браузер и CDN отдавали бы старый баннер по прежнему адресу.
     */
    private function put(UploadedFile $file, string $disk, string $dir, Course $course, string $format): string
    {
        $name = sprintf(
            '%s-%s.%s',
            CourseDesignAsset::slugForFormat($format),
            Str::random(8),
            $file->getClientOriginalExtension() ?: 'bin',
        );

        return $file->storeAs($dir.'/'.$course->id, $name, ['disk' => $disk]);
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return [null, null];
        }

        $size = @getimagesize($path);
        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function forget(string $disk, string $path): void
    {
        if ($disk === '' || $path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }
}
