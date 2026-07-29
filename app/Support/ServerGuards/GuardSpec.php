<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use RuntimeException;

/**
 * Значения предохранителей, прочитанные из scripts/server_guards.conf.
 *
 * Тот же файл читает scripts/server_guards_apply.sh — поэтому формат нарочно
 * беден (KEY=value, без подстановок), а парсер строгий: расхождение в трактовке
 * одного файла двумя потребителями и есть та самая «вторая правда», от которой
 * этот класс существует.
 */
final class GuardSpec
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("не читается файл значений: {$path}");
        }

        return self::fromString($raw, $path);
    }

    public static function fromString(string $raw, string $origin = '<string>'): self
    {
        $values = [];
        // Именно \r\n|\n|\r, а НЕ \R: без модификатора /u класс \R матчит сырой
        // байт 0x85, который в UTF-8 стоит внутри кириллицы (х = D1 85) — и
        // русские комментарии этого же файла резались бы посреди буквы.
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $i => $line) {
            $lineNo = $i + 1;
            if ($line === '' || str_starts_with($line, '#') || preg_match('/^\s/', $line) === 1) {
                continue;
            }
            if (! str_contains($line, '=')) {
                throw new RuntimeException("{$origin}:{$lineNo} — не пара KEY=value: {$line}");
            }
            [$key, $value] = explode('=', $line, 2);
            if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                throw new RuntimeException("{$origin}:{$lineNo} — недопустимое имя ключа: {$key}");
            }
            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            // Одиночный «$» разрешён: он стоит в регулярках earlyoom
            // (^php[0-9.]*$). Запрещены формы, которые ВЫГЛЯДЯТ подстановкой,
            // — на случай, если кто-нибудь однажды сделает `source` этого файла
            // вместо построчного разбора: тогда значения молча разъехались бы
            // между bash и PHP, а весь смысл «одного места для чисел» в том,
            // чтобы оба читали одинаково.
            if (preg_match('/\$\{|\$\(|`/', $value) === 1) {
                throw new RuntimeException("{$origin}:{$lineNo} — подстановки в значении запрещены: {$key}");
            }
            $values[$key] = $value;
        }

        if ($values === []) {
            throw new RuntimeException("{$origin}: ни одного значения");
        }

        return new self($values);
    }

    public function get(string $key): string
    {
        if (! array_key_exists($key, $this->values)) {
            throw new RuntimeException("не задано значение {$key}");
        }

        return $this->values[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Список через запятую (REQUIRED_ACTIVE_UNITS и подобные).
     *
     * @return list<string>
     */
    public function csv(string $key): array
    {
        $parts = preg_split('/[\s,]+/', trim($this->get($key))) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Подстановка @@KEY@@ — ровно то же, что делает bash-половина.
     */
    public function render(string $template): string
    {
        $map = [];
        foreach ($this->values as $key => $value) {
            $map['@@'.$key.'@@'] = $value;
        }
        $rendered = strtr($template, $map);

        if (preg_match('/@@[A-Z0-9_]+@@/', $rendered, $m) === 1) {
            throw new RuntimeException("неподставленная {$m[0]} — нет значения в server_guards.conf");
        }

        return $rendered;
    }

    /**
     * «2G»/«768M»/«65535» → байты. Нужно для сверки с systemd, который отдаёт
     * MemoryMax уже в байтах.
     */
    public function bytes(string $key): int
    {
        $value = strtoupper(trim($this->get($key)));
        if (preg_match('/^(\d+)([KMGT]?)$/', $value, $m) !== 1) {
            throw new RuntimeException("значение {$key} не размер: {$value}");
        }
        $multiplier = match ($m[2]) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
            default => 1,
        };

        return (int) $m[1] * $multiplier;
    }
}
