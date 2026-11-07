<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\OffsiteArchiveEncryptionGate;
use Illuminate\Console\Command;

/**
 * H3311: конфиг-предсброс деплоя — прод не выкатывается с небезопасной
 * сессионной кукой. Сеть не трогает, детерминирован; вызывается из deploy.sh
 * рядом с deploy:webhook-preflight (только в forward-деплое, rollback не
 * блокирует). Дополнительно предупреждает о пустом TRUSTED_PROXIES в проде
 * (не блокирует: без него теряются клиентские IP, но сайт работает).
 *
 * H6306 (аудит 09-10-2026, канал 3): прод также НЕ выкатывается без пароля
 * архива бэкапов при включённой off-site ноге — spatie-архивы с PII иначе
 * продолжали бы уезжать на Яндекс открытым zip'ом. Установка самого секрета
 * в прод-.env — отдельная gated-op (см. ops/backup/ARCHIVE_ENCRYPTION_H6306_RUNBOOK_2026-10.md).
 */
class DeployConfigPreflight extends Command
{
    protected $signature = 'deploy:config-preflight';

    protected $description = 'Fail deployment when production config posture is unsafe (H3311: SESSION_SECURE_COOKIE; H6306: BACKUP_ARCHIVE_PASSWORD with offsite backup enabled).';

    public function handle(): int
    {
        $failures = [];
        $warnings = [];

        if ((string) config('app.env') === 'production') {
            if (! self::isTruthy(config('session.secure'))) {
                $failures[] = sprintf(
                    'SESSION_SECURE_COOKIE must be true in production (got %s): session and remember-me cookies would ship without the Secure flag.',
                    var_export(config('session.secure'), true)
                );
            }

            if (trim((string) config('security.trusted_proxies', '')) === '') {
                $warnings[] = 'TRUSTED_PROXIES is empty in production: X-Forwarded-* are ignored, so request()->ip() reports the local proxy for everyone. Set it to your nginx/LB IP or CIDR list (see DEPLOY_QUEUE.md).';
            }

            if (! OffsiteArchiveEncryptionGate::passes()) {
                $failures[] = OffsiteArchiveEncryptionGate::refusalReason();
            }
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('deploy:config-preflight FAILED.');

            return self::FAILURE;
        }

        $this->info('deploy:config-preflight OK.'.($warnings === [] ? '' : ' (with warnings)'));

        return self::SUCCESS;
    }

    /** true-значения так, как их понимает env()/phpdotenv: true, 1, 'true', '1'. */
    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array($value, ['1', 'true'], true);
    }
}
