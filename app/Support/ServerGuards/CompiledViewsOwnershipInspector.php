<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * H4848 — скомпилированные Blade, которые обслуживающий пользователь не сможет
 * перезаписать/touch()-нуть.
 *
 * Класс инцидента (17-08, 20-08, 09-09, 14-09-2026): deploy.sh грел кэши от
 * root, Blade ложился как `root:755`, а php-fpm бежит от www-data и в
 * `BladeCompiler.php:215` делает `touch($compiledPath, $lastModified + 1)`.
 * `touch()`/`utime()` разрешён владельцу ИЛИ тому, у кого есть право записи на
 * файл, — root-овый 755 не даёт ни того, ни другого, поэтому любой запрос,
 * которому нужна перекомпиляция вьюхи, получал `Utime failed: Operation not
 * permitted` и Filament `/admin` отдавал HTTP 500 до ручного chown.
 *
 * Почему отдельный класс, а не метод пробы: инвариант нужно проверять на
 * фикстуре (файл, который точно не writable), а `storage/framework/views`
 * локальной машины всегда writable — тест на реальном каталоге проверял бы
 * только «зелёную» ветку. Каталог приходит в конструктор, поэтому фикстура
 * подставляется в тесте.
 *
 * Почему `is_writable()`, а не `fileowner() === 'root'`: спрашивать нужно ровно
 * то, что спросит BladeCompiler, — «сможет ли ЭТОТ процесс записать файл».
 * Пробу крутит сторож (раз в 15 минут) от www-data, тем же пользователем, что
 * обслуживает запросы, поэтому ответ здесь совпадает с ответом php-fpm. 14-09-2026 это
 * доказано с обратной стороны: проба от root была зелёной, пока /admin отдавал
 * 500, — root-овый `touch()` проба от root структурно не видит.
 */
class CompiledViewsOwnershipInspector
{
    public function __construct(private readonly string $viewsPath) {}

    /**
     * Файлы, которые текущий процесс не сможет перезаписать (и, значит,
     * `touch()`-нуть при перекомпиляции).
     *
     * @return list<string> абсолютные пути, отсортированные для стабильного вывода
     */
    public function unwritableFiles(): array
    {
        if (! is_dir($this->viewsPath)) {
            return [];
        }

        $blocked = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->viewsPath, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = $file->getPathname();
                if (is_writable($path)) {
                    continue;
                }

                $blocked[] = $path;
            }
        } catch (\Throwable) {
            // Каталог может исчезнуть между is_dir() и обходом (optimize:clear
            // в параллельном деплое). Молча возвращаем пусто: отсутствие вьюх —
            // не нарушение владения, а состояние между clear и warm.
            return [];
        }

        sort($blocked);

        return $blocked;
    }

    /**
     * Владелец файла строкой — только для текста алерта (root читается лучше,
     * чем UID 0). На системах без posix-расширения отдаём числовой UID.
     */
    public function ownerOf(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return '?';
        }

        if (\function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (\is_array($pw) && isset($pw['name'])) {
                return (string) $pw['name'];
            }
        }

        return (string) $uid;
    }

    /**
     * Имя текущего пользователя — чтобы алерт говорил «не writable для www-data»,
     * а не абстрактное «не writable».
     */
    public function currentUser(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (\is_array($pw) && isset($pw['name'])) {
                return (string) $pw['name'];
            }
        }

        return \function_exists('get_current_user') ? (string) get_current_user() : '?';
    }
}
