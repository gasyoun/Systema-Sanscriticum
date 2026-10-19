<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Производные WebP-варианты картинки по ширине — сырьё для `srcset`.
 *
 * Зачем отдельно от {@see WebpTranscoder} (H3082): тот меняет КОДЕК у исходника
 * и переписывает путь в БД. Для обложки курса это правильно, для баннера
 * витрины и медиа Куратора — нет: исходник нужен архиву дизайнов и og:image, а
 * 1448×1086 при показе 378×283 — это 3,8× лишних пикселей (замер 19-10-2026:
 * карточка `/online` весила 2,1 МБ и была LCP-элементом страницы).
 *
 * Здесь исходник НЕ трогается: рядом кладутся `stem-w400.webp`, `stem-w800.webp`
 * и т.д. Шаблон отдаёт srcset, браузер берёт нужную ширину. Варианта нет —
 * компонент отдаёт исходный URL, поэтому деплой безопасен до первого прогона
 * `media:responsive-variants`.
 *
 * ИНВАРИАНТ: апскейла нет. Ширина варианта обязана быть меньше исходной, иначе
 * файл не создаётся (иначе карточка «улучшалась» бы до мыла).
 */
final class ResponsiveDerivatives
{
    /** Расширения, которые имеет смысл перекодировать. GIF не трогаем — анимация. */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Кэш на время запроса: у каталога ~90 карточек, и без него каждая делала бы
     * три stat-вызова. Ключ включает корень диска, иначе тесты с Storage::fake
     * видели бы варианты предыдущего теста.
     *
     * @var array<string, array{srcset: string, variants: list<array{path: string, url: string, width: int}>}|null>
     */
    private static array $memo = [];

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('media.responsive.disk', 'public'));
    }

    /** Есть ли чем работать: GD с WebP и включённый флаг. */
    public function enabled(): bool
    {
        return (bool) config('media.responsive.enabled', true)
            && function_exists('imagewebp')
            && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /** @return list<int> */
    public function widths(): array
    {
        $widths = array_map('intval', (array) config('media.responsive.widths', [400, 800, 1200]));
        $widths = array_values(array_unique(array_filter($widths, static fn (int $w): bool => $w > 0)));
        sort($widths);

        return $widths;
    }

    /** `media/a.jpg` + 800 → `media/a-w800.webp`. */
    public function variantPath(string $path, int $width): string
    {
        $dir = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $stem = (string) pathinfo($path, PATHINFO_FILENAME);
        $base = ($dir === '' ? '' : $dir.'/').$stem;

        return $base.'-w'.$width.'.webp';
    }

    /**
     * Найти уже сгенерированные варианты для URL или storage-пути.
     *
     * @param  list<int>|null  $widths
     * @return array{srcset: string, variants: list<array{path: string, url: string, width: int}>}|null
     */
    public function variantsForUrl(string $src, ?array $widths = null): ?array
    {
        if ($src === '' || ! $this->enabled()) {
            return null;
        }

        $path = $this->storagePath($src);
        if ($path === null
            || ! in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }

        $widths ??= $this->widths();
        $fs = $this->disk();
        $cacheKey = $this->diskRoot($fs).'|'.$path.'|'.implode(',', $widths);

        if (array_key_exists($cacheKey, self::$memo)) {
            return self::$memo[$cacheKey];
        }

        $variants = [];

        try {
            foreach ($widths as $width) {
                $variant = $this->variantPath($path, $width);
                if (! $fs->exists($variant)) {
                    continue;
                }

                $variants[] = ['path' => $variant, 'url' => $fs->url($variant), 'width' => $width];
            }
        } catch (\Throwable $e) {
            Log::warning('responsive: variant lookup failed', ['path' => $path, 'error' => $e->getMessage()]);

            return self::$memo[$cacheKey] = null;
        }

        if ($variants === []) {
            return self::$memo[$cacheKey] = null;
        }

        $srcset = implode(', ', array_map(
            static fn (array $v): string => $v['url'].' '.$v['width'].'w',
            $variants,
        ));

        return self::$memo[$cacheKey] = ['srcset' => $srcset, 'variants' => $variants];
    }

    /** Сбросить кэш запроса. Нужен тестам и командам, которые пишут варианты в том же процессе. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /** `/storage/media/a.jpg`, `https://host/storage/media/a.jpg` и `media/a.jpg` → `media/a.jpg`. */
    public function storagePath(string $src): ?string
    {
        $path = (string) (parse_url($src, PHP_URL_PATH) ?: $src);
        $path = ltrim($path, '/');
        $path = (string) preg_replace('#^storage/#', '', $path);

        return $path === '' ? null : $path;
    }

    /**
     * Сгенерировать варианты для файла на диске.
     *
     * @param  list<int>|null  $widths
     * @return array{created: list<array{path: string, width: int, bytes: int}>, skipped: list<string>}
     */
    public function generate(string $path, ?array $widths = null, bool $force = false): array
    {
        if (! $this->enabled()) {
            return ['created' => [], 'skipped' => ['disabled']];
        }

        $fs = $this->disk();

        if (! $fs->exists($path)) {
            return ['created' => [], 'skipped' => ['missing']];
        }

        $bytes = (string) $fs->get($path);
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return ['created' => [], 'skipped' => ['unreadable']];
        }

        [$sourceWidth, , $type] = $info;

        if (! in_array((int) $type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['created' => [], 'skipped' => ['unsupported-type']];
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return ['created' => [], 'skipped' => ['decode-failed']];
        }

        $created = [];
        $skipped = [];
        $widths ??= $this->widths();

        // Нативные ширины: маленький исходник (карточка 532×399 при показе 533)
        // обязан получить вариант в свой размер, иначе браузер возьмёт 400w и
        // карточка на десктопе станет мыльной. Апскейла по-прежнему нет.
        $targets = array_values(array_filter($widths, static fn (int $w): bool => $w < (int) $sourceWidth));
        if ((int) $sourceWidth <= max($widths) && ! in_array((int) $sourceWidth, $targets, true)) {
            $targets[] = (int) $sourceWidth;
        }
        sort($targets);

        try {
            // Без этих строк PNG с прозрачностью (аватары преподавателей) теряет альфу.
            if ((int) $type === IMAGETYPE_PNG || (int) $type === IMAGETYPE_WEBP) {
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            foreach ($targets as $width) {

                $target = $this->variantPath($path, $width);

                if (! $force && $fs->exists($target) && $fs->lastModified($target) >= $fs->lastModified($path)) {
                    $skipped[] = 'fresh';

                    continue;
                }

                $scaled = $this->resize($image, $width, (int) $type);

                if (! $scaled instanceof GdImage) {
                    $skipped[] = 'scale-failed';

                    continue;
                }

                $encoded = $this->encode($scaled, (int) config('media.responsive.quality', 80));
                imagedestroy($scaled);

                if ($encoded === null) {
                    $skipped[] = 'encode-failed';

                    continue;
                }

                $fs->put($target, $encoded);
                $created[] = ['path' => $target, 'width' => $width, 'bytes' => strlen($encoded)];
            }
        } catch (\Throwable $e) {
            Log::warning('responsive: generate failed', ['path' => $path, 'error' => $e->getMessage()]);
            $skipped[] = 'exception';
        } finally {
            imagedestroy($image);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function diskRoot(Filesystem $fs): string
    {
        return method_exists($fs, 'path') ? (string) $fs->path('') : (string) config('media.responsive.disk', 'public');
    }

    /**
     * Уменьшить картинку до ширины, сохранив пропорции и альфу.
     *
     * Почему не imagescale(): на GD сборке PHP 8.5 (проверено 19-10-2026) режимы
     * IMG_BICUBIC/IMG_BICUBIC_FIXED возвращают false, и карточка молча осталась
     * бы без вариантов. imagecopyresampled ведёт себя одинаково везде и даёт
     * качество не хуже бикубика на даунскейле.
     */
    private function resize(GdImage $image, int $width, int $type): ?GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));
        $scaled = imagecreatetruecolor($width, $height);

        if (! $scaled instanceof GdImage) {
            return null;
        }

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefilledrectangle($scaled, 0, 0, $width, $height, $transparent);
        }

        if (! imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
            imagedestroy($scaled);

            return null;
        }

        return $scaled;
    }

    private function encode(GdImage $image, int $quality): ?string
    {
        ob_start();
        $ok = imagewebp($image, null, max(1, min(100, $quality)));
        $out = (string) ob_get_clean();

        return ($ok && $out !== '') ? $out : null;
    }
}
