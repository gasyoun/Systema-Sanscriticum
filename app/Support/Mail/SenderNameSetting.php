<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Имя отправителя писем (`MAIL_FROM_NAME`) как один объект вместо строки в .env.
 *
 * Зачем класс, а не одна команда: у этой правки два шага, которые легко забыть,
 * и оба живут ЗДЕСЬ, а не в голове агента.
 *
 *  1. Кеш конфига. На проде `php artisan optimize` (deploy.sh) кладёт
 *     `bootstrap/cache/config.php`, и при живом кеше Laravel .env не читает
 *     вообще. Правка файла тогда невидима: письма уходят со старым именем, а
 *     `.env` при этом выглядит правильным — ровно этот класс ошибки стоил
 *     переименования 24-11-2026.
 *  2. Воркеры очереди. Письма рассылки идут через Horizon (`redis-long` →
 *     `mailing`), и живой воркер держит старую конфигурацию в памяти до
 *     перезапуска: `php artisan queue:restart`.
 *
 * Класс читает и переписывает .env, умеет сказать, что реально увидит
 * следующий процесс (`effective()`), и узнать расхождение (`isStale()`).
 * Ничего не делает по своей воле: пересборка кеша и перезапуск очереди —
 * в команде `mail:from-name`, чтобы тесты не трогали общий кеш.
 */
final class SenderNameSetting
{
    public const KEY = 'MAIL_FROM_NAME';

    /**
     * Верхняя граница имени. Не про валидацию адреса, а про отображение:
     * мобильные клиенты режут строку примерно на 30–45 символах, а прежнее
     * «Институт исследования санскрита Общества ревнителей санскрита»
     * (53) влезало только обрезанным.
     */
    public const MAX_LENGTH = 78;

    public function __construct(
        private readonly string $envPath,
        private readonly ?string $cachedConfigPath = null,
    ) {}

    /** Виден ли путь .env — без него объект бесполезен, и это надо сказать вслух. */
    public function hasEnvFile(): bool
    {
        return is_file($this->envPath);
    }

    public function envPath(): string
    {
        return $this->envPath;
    }

    /** Есть ли собранный кеш конфига: пока он есть, .env не читается. */
    public function isConfigCached(): bool
    {
        return $this->cachedConfigPath !== null && is_file($this->cachedConfigPath);
    }

    /** Сырое значение из .env: null — строки в файле нет. */
    public function raw(): ?string
    {
        $contents = $this->readEnv();

        if ($contents === null) {
            return null;
        }

        if (preg_match('/^'.preg_quote(self::KEY, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return self::unquote(trim($matches[1]));
    }

    /**
     * Что получит СЛЕДУЮЩИЙ процесс, а не текущий.
     *
     * При живом кеше источник правды — `bootstrap/cache/config.php` (это и есть
     * суть ловушки: .env может говорить одно, а письма уходить с другим).
     */
    public function effective(): ?string
    {
        if ($this->isConfigCached()) {
            $cached = $this->cachedConfiguration();
            $name = $cached['mail']['from']['name'] ?? null;

            return is_string($name) ? $name : null;
        }

        $name = Config::get('mail.from.name');

        return is_string($name) ? $name : null;
    }

    /** .env правили, но кеш конфига не пересобрали — правка не действует. */
    public function isStale(): bool
    {
        $raw = $this->raw();

        if ($raw === null || $raw === '') {
            return false;
        }

        // `${APP_NAME}` и прочая интерполяция: сравнивать сырую строку не с чем.
        if (str_contains($raw, '${')) {
            return false;
        }

        // Без кеша .env читается напрямую — расхождения не бывает.
        if (! $this->isConfigCached()) {
            return false;
        }

        return $this->effective() !== $raw;
    }

    /**
     * Переписать `MAIL_FROM_NAME`, сохранив остальные строки и вид файла.
     *
     * @param  string|null  $backupDir  куда положить копию .env (null — без копии)
     * @return string|null путь к копии
     *
     * @throws InvalidArgumentException имя не годится для .env
     * @throws RuntimeException .env не читается/не пишется
     */
    public function set(string $value, ?string $backupDir = null): ?string
    {
        $error = self::validate($value);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        $value = trim($value);
        $contents = $this->readEnv();

        if ($contents === null) {
            throw new RuntimeException("Файл .env не найден или не читается: {$this->envPath}");
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $line = self::KEY.'="'.$value.'"';

        $count = 0;
        $updated = preg_replace_callback(
            '/^'.preg_quote(self::KEY, '/').'=[^\r\n]*/m',
            static fn (): string => $line,
            $contents,
            1,
            $count,
        );

        if ($updated === null) {
            throw new RuntimeException('Не удалось переписать .env (preg_replace_callback вернул null).');
        }

        if ($count === 0) {
            if ($updated !== '' && ! str_ends_with($updated, "\n")) {
                $updated .= $eol;
            }
            $updated .= $line.$eol;
        }

        $backup = $backupDir === null ? null : $this->backup($backupDir);

        // Пишем на месте (тот же inode): владелец, группа и права .env не меняются.
        // Ровно поэтому `sed -i` здесь не годится — он подменяет файл и уносит права.
        if (@file_put_contents($this->envPath, $updated) === false) {
            throw new RuntimeException("Не удалось записать {$this->envPath}");
        }

        return $backup;
    }

    /**
     * Почему имя не годится для `.env`; null — годится.
     *
     * Проверки не про эстетику: перевод строки превращает одно значение в две
     * директивы, кавычка рвёт строку .env, `$` включает интерполяцию — каждая из
     * них тихо ломает конфиг, а не падает.
     */
    public static function validate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Имя отправителя не может быть пустым.';
        }

        if (preg_match('/[\r\n]/', $value) === 1) {
            return 'В имени не должно быть перевода строки: в .env он создаёт вторую директиву.';
        }

        if (str_contains($value, '"')) {
            return 'В имени не должно быть двойной кавычки: значение в .env заключено в кавычки.';
        }

        if (str_contains($value, '$')) {
            return 'Символ $ в .env включает интерполяцию — такое имя не сохранится дословно.';
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            return 'Слишком длинное имя: '.mb_strlen($value).' символов, максимум '.self::MAX_LENGTH.'.';
        }

        return null;
    }

    private function readEnv(): ?string
    {
        if (! is_file($this->envPath)) {
            return null;
        }

        $contents = @file_get_contents($this->envPath);

        return $contents === false ? null : $contents;
    }

    /** @return array<string, mixed> */
    private function cachedConfiguration(): array
    {
        /** @var array<string, mixed> $config */
        $config = require $this->cachedConfigPath;

        return is_array($config) ? $config : [];
    }

    private function backup(string $dir): string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог для бэкапа: {$dir}");
        }

        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'.env.'.now()->format('Ymd-His');

        if (@copy($this->envPath, $path) === false) {
            throw new RuntimeException("Не удалось сохранить копию .env в {$path}");
        }

        return $path;
    }

    private static function unquote(string $value): string
    {
        if (mb_strlen($value) >= 2) {
            $first = mb_substr($value, 0, 1);
            $last = mb_substr($value, -1);

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return mb_substr($value, 1, -1);
            }
        }

        return $value;
    }
}
