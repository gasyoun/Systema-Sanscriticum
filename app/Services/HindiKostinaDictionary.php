<?php

declare(strict_types=1);

namespace App\Services;

/**
 * H3206 — Kostina Hindi module dictionaries (M1–M12) from the PDF text layer.
 *
 * Committed JSON only. No OCR at request time. Does not grant access.
 */
final class HindiKostinaDictionary
{
    public const STORE = 'database/data/kostina_hindi_dicts/entries.json';

    /** @var list<string> */
    public const MODULES = ['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11', 'M12'];

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function storePath(): string
    {
        return base_path(self::STORE);
    }

    /**
     * @return list<array{id: string, module: string, label: string, count: int}>
     */
    public function modules(): array
    {
        $counts = $this->payload()['per_module'] ?? [];
        $out = [];
        foreach (self::MODULES as $module) {
            $out[] = [
                'id' => $module,
                'module' => $module,
                'label' => $this->label($module),
                'count' => (int) ($counts[$module] ?? 0),
            ];
        }

        return $out;
    }

    public function label(string $module): string
    {
        $n = (int) substr($module, 1);
        $part = $n <= 6 ? 1 : 2;

        return 'Часть '.$part.' · модуль '.$n;
    }

    public function isModule(string $module): bool
    {
        return in_array($module, self::MODULES, true);
    }

    /**
     * @return list<array{
     *     id: string,
     *     module: string,
     *     page: int,
     *     hindi: string,
     *     ru: string,
     *     gender: string,
     *     examples: list<string>
     * }>
     */
    public function entriesFor(string $module): array
    {
        $out = [];
        foreach ($this->payload()['entries'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['module'] ?? '') !== $module) {
                continue;
            }
            $hindi = trim((string) ($row['hindi'] ?? ''));
            $ru = trim((string) ($row['ru'] ?? ''));
            if ($hindi === '' || $ru === '') {
                continue;
            }
            $examples = $row['examples'] ?? [];
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'module' => $module,
                'page' => (int) ($row['page'] ?? 0),
                'hindi' => $hindi,
                'ru' => $ru,
                'gender' => (string) ($row['gender'] ?? ''),
                'examples' => is_array($examples) ? array_values(array_map('strval', $examples)) : [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }
        $path = $this->storePath();
        if (! is_file($path)) {
            $this->payload = [];

            return $this->payload;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->payload = is_array($decoded) ? $decoded : [];

        return $this->payload;
    }
}
