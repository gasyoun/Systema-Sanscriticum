<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Нормализует «сырые» названия курсов из мастер-таблицы (вкладки Курсы/Блоки/Оплаты)
 * к каноническим названиям из админки.
 *
 * Источник правды — database/data/course_aliases.csv с колонками:
 *   source_name,target_name,action
 * где action ∈ {rename, create_hidden}.
 *  - rename:        при импорте source_name заменяется на target_name.
 *  - create_hidden: курс отсутствует в админке, но по нему есть исторические оплаты —
 *                   его нужно завести скрытым (см. SeedHistoricalCourses). Для резолва
 *                   имя не меняется (target_name == source_name).
 *
 * Резолвер ленивый и кеширует справочник на время жизни инстанса.
 */
final class CourseNameResolver
{
    /** @var array<string,string>|null  source_name => target_name (только rename) */
    private ?array $aliases = null;

    /** @var array<int,string>|null  имена курсов с action=create_hidden */
    private ?array $historical = null;

    public function __construct(private readonly ?string $csvPath = null) {}

    /**
     * Возвращает каноническое название курса. Если для имени задан rename-алиас —
     * возвращает целевое; иначе возвращает исходное имя без изменений (trim).
     */
    public function resolve(string $name): string
    {
        $name = trim($name);

        return $this->aliases()[$name] ?? $name;
    }

    /**
     * Список названий курсов, которых нет в админке, но которые нужно завести
     * скрытыми ради импорта исторических оплат.
     *
     * @return array<int,string>
     */
    public function historicalCourses(): array
    {
        $this->load();

        return $this->historical ?? [];
    }

    /**
     * @return array<string,string>
     */
    private function aliases(): array
    {
        $this->load();

        return $this->aliases ?? [];
    }

    private function load(): void
    {
        if ($this->aliases !== null) {
            return;
        }

        $this->aliases = [];
        $this->historical = [];

        $path = $this->csvPath ?? database_path('data/course_aliases.csv');
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, null, ',', '"', ''); // пропускаем заголовок

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $source = isset($row[0]) ? trim((string) $row[0]) : '';
            $target = isset($row[1]) ? trim((string) $row[1]) : '';
            $action = isset($row[2]) ? trim((string) $row[2]) : '';

            if ($source === '') {
                continue;
            }

            if ($action === 'rename' && $target !== '') {
                $this->aliases[$source] = $target;
            } elseif ($action === 'create_hidden') {
                $this->historical[] = $source;
            }
        }

        fclose($handle);
    }
}
