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
     * Что вообще разрешено класть в слот картинки.
     *
     * Проверка двусторонняя и ОБЯЗАТЕЛЬНА перед любой записью: расширение
     * из имени клиента должно быть в списке, И содержимое должно опознаться
     * как одна из разрешённых MIME. Одного фильтра Filament ->image()
     * недостаточно — он пропускает SVG (а тот исполняем в браузере) и
     * обходится подделкой multipart. Без этого джейла JPEG с именем
     * shell.php уезжал бы на публичный диск как /storage/.../cover-x.php.
     */
    private const IMAGE_EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

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
                $extension = $this->assertAllowedImage($image);
                $path = $this->put($image, $imageDisk, (string) config('design_assets.image_dir', 'course-design'), $course, $format, $extension);
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
     * Человекочитаемый размер. Живёт здесь, а не в странице и виджете по копии:
     * иначе колонка строки и карточка «Занято на диске» начали бы округлять
     * по-разному и выглядеть как расхождение данных.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', ' ').' КБ';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', ' ').' МБ';
        }

        return number_format($bytes / 1024 / 1024 / 1024, 2, ',', ' ').' ГБ';
    }

    /**
     * Сводка для виджета и бейджа навигации.
     *
     * Показатели готовности считаем только по АКТИВНЫМ курсам: бейдж, который
     * вечно горит красным из-за архивных курсов, приучает не смотреть на бейдж.
     *
     * ОБЪЁМ — исключение: он считается по ВСЕМ строкам. Архивный курс место на
     * диске занимает ровно так же, и «занято на диске», посчитанное по части
     * файлов, было бы неправдой в отчёте, который заводят как раз ради контроля
     * роста хранилища.
     *
     * @return array{courses: int, complete: int, missingSlots: int, withoutPsd: int, ratioMismatch: int, bytesImages: int, bytesPsd: int, bytesTotal: int}
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

        // По всем строкам, а не только по активным курсам — см. докблок выше.
        $bytesImages = (int) CourseDesignAsset::query()->sum('size');
        $bytesPsd = (int) CourseDesignAsset::query()->sum('psd_size');

        return [
            'courses' => $courses->count(),
            'complete' => $complete,
            'missingSlots' => $missingSlots,
            'withoutPsd' => $withoutPsd,
            'ratioMismatch' => $ratioMismatch,
            'bytesImages' => $bytesImages,
            'bytesPsd' => $bytesPsd,
            'bytesTotal' => $bytesImages + $bytesPsd,
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
     *
     * Для картинок $forcedExtension — каноничное расширение, определённое ПО
     * СОДЕРЖИМОМУ (assertAllowedImage), никогда не имя клиента. PSD-исходники
     * живут на приватном диске за гейтованным маршрутом, там расширение из
     * имени клиента безопасно и сохранено для совместимости.
     */
    private function put(UploadedFile $file, string $disk, string $dir, Course $course, string $format, ?string $forcedExtension = null): string
    {
        $extension = $forcedExtension
            ?? strtolower($file->getClientOriginalExtension() ?: 'bin');

        $name = sprintf(
            '%s-%s.%s',
            CourseDesignAsset::slugForFormat($format),
            Str::random(8),
            $extension,
        );

        return $file->storeAs($dir.'/'.$course->id, $name, ['disk' => $disk]);
    }

    /**
     * Джейл расширений для картинок слота: расширение из имени клиента должно
     * быть в белом списке, И содержимое должно опознаться как разрешённый
     * растровый MIME. Одного фильтра Filament ->image() недостаточно — он
     * пропускает SVG (исполняем в браузере) и обходится подделкой multipart.
     * Без этого джейла JPEG с именем shell.php уезжал бы на публичный диск
     * как /storage/.../cover-x.php.
     *
     * @return string каноничное расширение по содержимому (для put())
     *
     * @throws InvalidArgumentException с человекочитаемым текстом: страница
     *                                  показывает его как есть в уведомлении
     */
    private function assertAllowedImage(UploadedFile $file): string
    {
        $clientExtension = strtolower($file->getClientOriginalExtension());

        if (! in_array($clientExtension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Файл «%s» отклонён: разрешены только картинки JPG, JPEG, PNG, WebP или GIF.',
                $file->getClientOriginalName() ?: 'без имени',
            ));
        }

        $mime = (string) $file->getMimeType();

        if (! isset(self::IMAGE_EXT_BY_MIME[$mime])) {
            throw new InvalidArgumentException(sprintf(
                'Содержимое файла «%s» не похоже на JPG, PNG, WebP или GIF.',
                $file->getClientOriginalName() ?: 'без имени',
            ));
        }

        return self::IMAGE_EXT_BY_MIME[$mime];
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
