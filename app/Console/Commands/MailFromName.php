<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\SenderNameSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;

/**
 * Имя отправителя писем — одной командой, вместе с двумя шагами, которые к нему
 * прилагаются и которые легко забыть (см. SenderNameSetting).
 *
 *     php artisan mail:from-name                              # что сейчас: .env / кеш / дрейф
 *     php artisan mail:from-name "Общество ревнителей санскрита"   # поменять и довести до конца
 *
 * Почему не «поправить .env руками»: на проде конфиг закеширован, и правка .env
 * без `config:cache` невидима, а без `queue:restart` — невидима воркерам,
 * которые как раз и отправляют рассылку. Обе ловушки 24-11-2026 стоили
 * ручной правки на сервере вместо одной команды.
 */
class MailFromName extends Command
{
    protected $signature = 'mail:from-name
        {name? : Новое имя отправителя. Без аргумента — только показать состояние}
        {--dry-run : Показать, что изменится, и ничего не записывать}
        {--no-cache : Не пересобирать кеш конфига (тогда правка .env не подействует)}
        {--no-restart : Не перезапускать воркеры очереди}';

    protected $description = 'Показать или изменить имя отправителя писем (MAIL_FROM_NAME) вместе с кешем конфига и перезапуском воркеров.';

    public function handle(SenderNameSetting $setting): int
    {
        $this->report($setting);

        $name = $this->argument('name');

        if ($name === null) {
            if (! $setting->hasEnvFile()) {
                $this->error("Файл .env не найден: {$setting->envPath()}");

                return self::FAILURE;
            }

            if ($setting->isStale()) {
                $this->error(sprintf(
                    'Кеш конфига расходится с .env: письма уходят с «%s», хотя в .env «%s». Исправить: php artisan mail:from-name "%s"',
                    (string) $setting->effective(),
                    (string) $setting->raw(),
                    (string) $setting->raw(),
                ));

                return self::FAILURE;
            }

            $this->info('Расхождения нет: .env и конфиг согласованы. Чтобы поменять имя — php artisan mail:from-name "Новое имя".');

            return self::SUCCESS;
        }

        $error = SenderNameSetting::validate((string) $name);

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $name = trim((string) $name);

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('dry-run: MAIL_FROM_NAME «%s» → «%s». Файлы не тронуты.', (string) $setting->raw(), $name));

            return self::SUCCESS;
        }

        if ($this->runningAsRoot()) {
            $this->warn('Запуск от root: пересобранный bootstrap/cache/config.php окажется root-овым, и следующий deploy (www-data) не сможет его перезаписать. Правильный вызов: sudo -u www-data php artisan mail:from-name "…"');
        }

        try {
            $backup = $setting->set($name, storage_path('app/mail-from-name'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("MAIL_FROM_NAME записан в .env: «{$name}»".($backup === null ? '' : " (копия: {$backup})"));

        $cacheRebuilt = false;
        $cacheAttempted = false;

        if ((bool) $this->option('no-cache')) {
            $this->warn('Кеш конфига НЕ пересобран (--no-cache): пока он жив, письма уйдут со старым именем. Шаг: php artisan config:cache');
        } elseif ($this->laravel->runningUnitTests()) {
            $this->line('  кеш конфига не пересобираю: тестовый прогон не трогает общий bootstrap/cache.');
        } else {
            $cacheAttempted = true;
            $cacheRebuilt = $this->rebuildConfigCache();
        }

        if ((bool) $this->option('no-restart')) {
            $this->warn('Воркеры очереди НЕ перезапущены (--no-restart): Horizon продолжит слать со старым именем, пока не подхватит конфиг. Шаг: php artisan queue:restart');
        } elseif ($this->laravel->runningUnitTests()) {
            $this->line('  очередь не перезапускаю: тестовый прогон.');
        } else {
            Artisan::call('queue:restart');
            $this->line('  очередь: сигнал queue:restart отправлен — Horizon поднимет воркеров с новым конфигом за несколько секунд.');
        }

        if ($cacheAttempted && ! $cacheRebuilt) {
            $this->newLine();
            $this->report($setting);

            return self::FAILURE;
        }

        if ($cacheRebuilt && $setting->effective() !== $name) {
            $this->error(sprintf(
                'После пересборки кеша имя всё ещё «%s» — правка не доехала. Проверьте bootstrap/cache/config.php и права на него.',
                (string) $setting->effective(),
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->report($setting);
        $this->info('Готово. Проверить на живом письме: php artisan mail:preflight --send=<адрес>');

        return self::SUCCESS;
    }

    private function report(SenderNameSetting $setting): void
    {
        $this->line('  .env:        '.(($raw = $setting->raw()) === null ? '— (строки MAIL_FROM_NAME нет)' : "MAIL_FROM_NAME=\"{$raw}\""));
        $this->line('  письма:      '.(($effective = $setting->effective()) ?? '—').' <'.(string) config('mail.from.address').'>');
        $this->line('  кеш конфига: '.($setting->isConfigCached() ? 'есть — .env читается только после config:cache' : 'нет — .env читается напрямую'));

        if ($setting->isStale()) {
            $this->warn(sprintf('  дрейф:       в .env «%s», а конфиг собран из «%s» — правка не действует.', (string) $raw, (string) $effective));
        }
    }

    private function rebuildConfigCache(): bool
    {
        $exitCode = Artisan::call('config:cache');

        if ($exitCode !== self::SUCCESS) {
            $this->error('config:cache завершился с ошибкой — .env записан, но правка пока не действует.');

            return false;
        }

        $this->line('  кеш конфига пересобран (config:cache) — теперь .env читается заново.');

        return true;
    }

    private function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}
