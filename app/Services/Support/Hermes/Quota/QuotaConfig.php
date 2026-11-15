<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: чтение конфига квотной машины (config/school_role_quota.php).
 *
 * Пороги и доли живут только в конфиге; отсутствие значения — дефект конфига
 * (исключение), не догадка кода.
 */
final class QuotaConfig
{
    /** Порог в процентах (79/80/99/100) или иное целое значение конфига. */
    public static function threshold(string $key): int
    {
        $raw = config('school_role_quota.'.$key);

        if (! is_int($raw) && ! ctype_digit((string) $raw)) {
            throw new \RuntimeException('school_role_quota.'.$key.' is not configured (config defect)');
        }

        return (int) $raw;
    }

    /** Флаг машины; дефолт OFF — никакого production-включения. */
    public static function enabled(): bool
    {
        return (bool) config('school_role_quota.enabled', false);
    }

    /** Квота роли в токенах на окно; null = неизмерена (TBD) ⇒ log-only. */
    public static function allowance(string $roleId): ?int
    {
        $raw = config('school_role_quota.allowances.'.$roleId);

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_int($raw) && ! ctype_digit((string) $raw)) {
            throw new \RuntimeException('allowance for role '.$roleId.' is not an integer (config defect)');
        }

        $allowance = (int) $raw;

        return $allowance > 0 ? $allowance : null;
    }

    /**
     * Канонические role ID ростера с профилем ИСКЛ (ID 8/39/42) — любая
     * попытка активации блокируется.
     *
     * @return list<string>
     */
    public static function excludedRoleIds(): array
    {
        $ids = config('school_role_quota.excluded_role_ids', []);

        return is_array($ids) ? array_values($ids) : [];
    }
}
