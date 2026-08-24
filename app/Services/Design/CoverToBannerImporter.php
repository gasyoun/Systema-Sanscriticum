<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Перенос ОБЛОЖКИ ВИТРИНЫ курса (courses.image_path) в пустой слот баннера.
 *
 * Зачем: матрица «Дизайн курсов» стартовала пустой, а у части курсов обложка
 * уже нарисована и живёт на витрине. Кадрируем её по центру до точной
 * пропорции слота и кладём в учёт — слот закрывается без работы дизайнера.
 *
 * Отдельный класс, а не метод CourseDesignAssetService: правило «единственная
 * точка записи» не нарушается (сюда попадает только подготовка файла, пишет
 * по-прежнему сервис), зато учётный сервис не обрастает растровой обработкой.
 * Зависимость односторонняя — так же устроен сосед CourseDesignArchiver.
 *
 * ИНВАРИАНТ: обложка витрины только ЧИТАЕТСЯ. Ни courses.image_path, ни файл в
 * courses/ этот класс не меняет и не удаляет — курс на витрине обязан
 * выглядеть после переноса ровно так же, как до него.
 */
class CoverToBannerImporter
{
    /** Слот, в который переносим по умолчанию. */
    public const FORMAT = '4:3';

    /** Приватная временная папка — рядом с архивами баннеров. */
    private const TMP_DIR = 'app/tmp/course-design-import';

    /**
     * Что вообще берём в слот: IMAGETYPE_* => [декодер, энкодер, расширение].
     *
     * Список общий для ОБЕИХ веток — и для кадрирования, и для копии байт-в-байт,
     * хотя копии кодек не нужен. Иначе поведение зависело бы от пропорции
     * исходника: SVG на 800×600 — это ровно 4:3, и он молча уехал бы в слот
     * баннера (getimagesize его в этой сборке PHP распознаёт), а тот же SVG на
     * 16:9 честно отвалился бы как неподдержанный.
     */
    private const CODECS = [
        IMAGETYPE_JPEG => ['imagecreatefromjpeg', 'imagejpeg', 'jpg'],
        IMAGETYPE_PNG => ['imagecreatefrompng', 'imagepng', 'png'],
        IMAGETYPE_WEBP => ['imagecreatefromwebp', 'imagewebp', 'webp'],
        IMAGETYPE_GIF => ['imagecreatefromgif', 'imagegif', 'gif'],
    ];

    public function __construct(private readonly CourseDesignAssetService $assets) {}

    /**
     * Перенести обложку курса в слот $format, если слот пуст.
     *
     * Исключений не бросает вовсе: массовый прогон обязан дойти до конца по
     * всем выделенным курсам, а не оборваться на первом же курсе без обложки.
     */
    public function import(Course $course, User $actor, string $format = self::FORMAT): CoverImportResult
    {
        if (! preg_match('/^(\d+):(\d+)$/', $format, $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            return CoverImportResult::of(CoverImportOutcome::Failed, 'Формат «'.$format.'» не разобрать.');
        }

        $rw = (int) $m[1];
        $rh = (int) $m[2];

        // Слот читаем запросом, а не из $course->designAssets: в массовом
        // действии связь загружена таблицей страницы и к моменту переноса уже
        // не отражает того, что записали предыдущие курсы этого же прогона.
        /** @var CourseDesignAsset|null $existing */
        $existing = CourseDesignAsset::query()
            ->where('course_id', $course->id)
            ->where('format', $format)
            ->first();

        if ($existing && filled($existing->path)) {
            return CoverImportResult::of(CoverImportOutcome::SlotTaken);
        }

        if (blank($course->image_path)) {
            return CoverImportResult::of(CoverImportOutcome::NoCover);
        }

        $source = null;
        $result = null;

        try {
            $dir = $this->tmpDir();
            $cover = (string) $course->image_path;

            $source = $this->pullCover($cover, $dir);
            if ($source === null) {
                return CoverImportResult::of(CoverImportOutcome::FileMissing, $cover);
            }

            $info = @getimagesize($source);
            if ($info === false) {
                return CoverImportResult::of(CoverImportOutcome::Unreadable);
            }

            [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];

            $codec = self::CODECS[$type] ?? null;
            if ($codec === null) {
                return CoverImportResult::of(
                    CoverImportOutcome::Unsupported,
                    ltrim((string) image_type_to_extension($type, false), '.') ?: null,
                );
            }

            if ($width < $rw || $height < $rh || $height < 1) {
                return CoverImportResult::of(CoverImportOutcome::TooSmall, $width.'×'.$height);
            }

            $extension = $codec[2];
            $result = $dir.DIRECTORY_SEPARATOR.Str::random(24).'.'.$extension;

            if ($this->ratioIsClose($width, $height, $rw, $rh)) {
                // Пропорция уже та — перекодировать нечего и незачем: JPEG
                // потерял бы качество, GIF и WebP — ещё и анимацию.
                if (! @copy($source, $result)) {
                    return CoverImportResult::of(CoverImportOutcome::Failed, 'Не удалось скопировать обложку.');
                }
            } else {
                $failure = $this->crop($source, $result, $width, $height, $type, $rw, $rh);
                if ($failure !== null) {
                    return CoverImportResult::of($failure);
                }
            }

            $maxBytes = ((int) config('design_assets.max_image_kb', 10240)) * 1024;
            $size = (int) @filesize($result);
            if ($size > $maxBytes) {
                return CoverImportResult::of(
                    CoverImportOutcome::TooHeavy,
                    CourseDesignAssetService::formatBytes($size).' против лимита '.CourseDesignAssetService::formatBytes($maxBytes),
                );
            }

            // Имя даём по исходной обложке — в колонке «original_name» видно,
            // откуда взят файл. Расширение соответствует содержимому (его
            // определил кодек); сервис при записи всё равно строит имя слота
            // сам и проверяет содержимое своим джейлом расширений.
            $name = (pathinfo($cover, PATHINFO_FILENAME) ?: 'cover').'.'.$extension;

            $asset = $this->assets->store(
                $course,
                $format,
                new UploadedFile($result, $name, null, null, true),
                // Ссылку на исходник передаём прежнюю, а не голый null: store()
                // трактует присланное как желаемое состояние и стёр бы её.
                // Сегодня сюда всегда приходит null (path в БД NOT NULL, значит
                // любая существующая строка — это занятый слот, и мы вышли выше),
                // но перестань колонка быть обязательной — и молчаливая потеря
                // ссылки на PSD была бы худшим видом регрессии.
                $existing?->psd_url,
                null,
                $actor,
            );

            return CoverImportResult::imported($asset);
        } catch (\Throwable $e) {
            Log::warning('Не удалось перенести обложку курса в слот баннера', [
                'course_id' => $course->id,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return CoverImportResult::of(CoverImportOutcome::Failed, $e->getMessage());
        } finally {
            foreach ([$source, $result] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Копия обложки в локальный временный файл.
     *
     * Через readStream, а не через $disk->path(): диск обложек задан конфигом и
     * завтра может оказаться удалённым (в проекте уже подключён flysystem-webdav),
     * а getimagesize и GD умеют работать только с локальным путём.
     */
    private function pullCover(string $cover, string $dir): ?string
    {
        $disk = Storage::disk((string) config('design_assets.cover_disk', 'public'));

        if (! $disk->exists($cover)) {
            return null;
        }

        $stream = $disk->readStream($cover);
        if (! is_resource($stream)) {
            return null;
        }

        $path = $dir.DIRECTORY_SEPARATOR.Str::random(24).'.src';
        $target = @fopen($path, 'wb');

        if (! is_resource($target)) {
            fclose($stream);

            throw new RuntimeException('Не удалось открыть временный файл для обложки.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $path;
    }

    /** Достаточно ли пропорция близка к целевой, чтобы не трогать файл вовсе. */
    private function ratioIsClose(int $width, int $height, int $rw, int $rh): bool
    {
        $expected = $rw / $rh;
        $tolerance = (float) config('design_assets.import_copy_tolerance', 0.0);

        return abs($width / $height - $expected) <= $expected * $tolerance;
    }

    /**
     * Центральный кроп до точной пропорции. Возвращает null при успехе либо
     * исход, объясняющий отказ.
     */
    private function crop(string $source, string $target, int $width, int $height, int $type, int $rw, int $rh): ?CoverImportOutcome
    {
        if (! extension_loaded('gd')) {
            return CoverImportOutcome::GdUnavailable;
        }

        $codec = self::CODECS[$type] ?? null;
        if ($codec === null) {
            return CoverImportOutcome::Unsupported;
        }

        [$decoder, $encoder] = $codec;
        if (! function_exists($decoder) || ! function_exists($encoder)) {
            return CoverImportOutcome::GdUnavailable;
        }

        // Проверяем ДО декодирования: нехватка памяти внутри GD — это фатальная
        // ошибка, которую catch (\Throwable) не поймает, и одна тяжёлая
        // картинка уронила бы весь массовый прогон.
        if (! $this->fitsInMemory($width, $height)) {
            return CoverImportOutcome::TooHeavy;
        }

        // Снап на целое кратное: 1600×900 → k=300 → 1200×900. Апскейл здесь
        // невозможен по построению ($cw <= $width, $ch <= $height), а не
        // запрещён отдельной проверкой. Теряем максимум пару пикселей, зато
        // пропорция получается точной в целых числах, без всякого допуска.
        $k = min(intdiv($width, $rw), intdiv($height, $rh));
        $cw = $rw * $k;
        $ch = $rh * $k;
        $x = intdiv($width - $cw, 2);
        $y = intdiv($height - $ch, 2);

        $image = @$decoder($source);
        if (! $image instanceof GdImage) {
            return CoverImportOutcome::Unreadable;
        }

        $canvas = null;

        try {
            if (! imageistruecolor($image)) {
                // Палитровый PNG/GIF: индекс прозрачности превращаем в честную
                // альфу, иначе прозрачный угол приедет чёрным квадратом.
                imagepalettetotruecolor($image);
            }

            $canvas = imagecreatetruecolor($cw, $ch);

            if ($type !== IMAGETYPE_JPEG) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $cw - 1, $ch - 1, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            }

            // imagecopy, а не imagecopyresampled: масштаба нет, пиксели
            // переносим один в один. И не imagecrop: на палитровых и альфа-
            // картинках он ведёт себя по-разному в разных сборках GD.
            imagecopy($canvas, $image, 0, 0, $x, $y, $cw, $ch);

            $written = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($canvas, $target, (int) config('design_assets.import_jpeg_quality', 88)),
                IMAGETYPE_PNG => imagepng($canvas, $target, (int) config('design_assets.import_png_compression', 6)),
                IMAGETYPE_WEBP => imagewebp($canvas, $target, (int) config('design_assets.import_webp_quality', 88)),
                IMAGETYPE_GIF => imagegif($canvas, $target),
                default => false,
            };

            return $written ? null : CoverImportOutcome::Failed;
        } finally {
            imagedestroy($image);
            if ($canvas instanceof GdImage) {
                imagedestroy($canvas);
            }
        }
    }

    /** Влезет ли распакованный растр в остаток memory_limit. */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return true;
        }

        // Четыре байта на пиксель у truecolor плюс запас: холст и исходник
        // какое-то время живут в памяти одновременно.
        $needed = (int) ($width * $height * 4 * 2.2);

        return memory_get_usage(true) + $needed < $limit;
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;
        $unit = strtolower(substr($raw, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function tmpDir(): string
    {
        $dir = storage_path(self::TMP_DIR);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Не удалось создать временную директорию для переноса обложки.');
        }

        $this->purgeStale($dir);

        return $dir;
    }

    /**
     * Подчистить временные файлы старше часа.
     *
     * Успешный и провалившийся перенос убирают за собой сами (finally), но
     * убитый на полпути процесс след оставляет — без этой уборки каталог рос бы
     * молча. Тот же приём в CourseDesignArchiver.
     */
    private function purgeStale(string $dir): void
    {
        $deadline = time() - 3600;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $deadline) {
                @unlink($file);
            }
        }
    }
}
