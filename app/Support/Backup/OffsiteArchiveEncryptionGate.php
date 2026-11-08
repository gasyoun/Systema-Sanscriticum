<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Console\Commands\DeployConfigPreflight;
use App\Listeners\Backup\SplitUploadToYandex;

/**
 * H6306 (аудит 09-10-2026, канал 3): spatie-архив с PII уезжал off-site
 * открытым zip'ом — `BACKUP_ARCHIVE_PASSWORD` в прод-.env отсутствовал, и
 * ни конфиг-валидация, ни выгрузка частей об этом не спрашивали.
 *
 * Единая точка правды для двух потребителей:
 *  - {@see SplitUploadToYandex} — отказ ДО начала
 *    разбиения/докатки off-site, когда пароль не задан или пуст;
 *  - {@see DeployConfigPreflight} — hard-fail деплоя
 *    в проде при включённой off-site ноге без пароля.
 *
 * Контракт: значение пароля НИКОГДА не покидает этот класс — наружу
 * отдаются только булевы факты и текст причины, называющий env-ключ,
 * но не значение (см. MATURITY_BACKUPS_DR_2026-10-09: «пароли/токены в
 * отчёт не выносятся; фиксируется только факт наличия/отсутствия»).
 *
 * Гейт НЕ трогает: local-копию spatie (продолжает писаться), restic-ногу
 * (systemd-таймеры на боксе, отдельный контур) и исторические части на
 * Диске (не удаляются и не перезаливаются — ротации отдельная gated-op).
 */
class OffsiteArchiveEncryptionGate
{
    /**
     * Включена ли off-site нога split-upload: диск задан и не local.
     * Когда нога выключена, гейт молчит — ругаться на пароль незачем.
     */
    public static function offsiteEnabled(): bool
    {
        $disk = trim((string) config('backup.backup.split_upload.disk', ''));

        return $disk !== '' && $disk !== 'local';
    }

    /**
     * Задан ли пароль архива: непустая строка после trim. Пустая строка
     * (`.env.example` хранит `BACKUP_ARCHIVE_PASSWORD=` без значения)
     * приравнивается к отсутствию: spatie с пустым паролем «шифрует»
     * пустым паролем (libzip тогда вообще падает на close — FINDINGS
     * §513), а частям это всё равно что открытый zip.
     */
    public static function passwordConfigured(): bool
    {
        return trim((string) config('backup.backup.password', '')) !== '';
    }

    /**
     * true — off-site выгрузка разрешена (нога включена И пароль задан).
     * Нога выключена → тоже true: гейт не должен блокировать выключенный
     * контур, его вообще не касается.
     */
    public static function passes(): bool
    {
        return ! self::offsiteEnabled() || self::passwordConfigured();
    }

    /**
     * Причина отказа для лога/предсброса: называет env-ключ и конфиг-путь,
     * никогда — значение. Непустая только когда passes() === false.
     */
    public static function refusalReason(): string
    {
        if (self::passes()) {
            return '';
        }

        return 'BACKUP_ARCHIVE_PASSWORD пуст/не задан при включённой off-site ноге бэкапа '
            .'(backup.backup.split_upload.disk="'.config('backup.backup.split_upload.disk').'"): '
            .'архив уехал бы off-site открытым zip (H6306, аудит 09-10-2026 канал 3). '
            .'Задайте пароль в .env и выполните `php artisan config:cache`.';
    }
}
