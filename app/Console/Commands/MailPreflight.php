<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\SenderNameSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Deploy-gating guard for #504: prod once inherited `.env.example`'s dev
 * mailpit host — a mail-catcher with no outbound relay, so every mail was
 * silently swallowed. Deterministic and network-free by default; `--send`
 * is the only check that touches the network. See docs/mail-esp.md.
 *
 * 24-11-2026: добавлен отчёт об отправителе и предупреждение о дрейфе кеша
 * конфига. Не FATAL намеренно: на проде дрейф лечится следующим deploy
 * (`optimize` пересобирает кеш), а падающий гейт останавливал бы выкатку из-за
 * состояния, которое сама выкатка и исправляет. Тот же уровень, что и
 * предупреждение о `QUEUE_CONNECTION`.
 */
class MailPreflight extends Command
{
    protected $signature = 'mail:preflight {--send= : Send one real test email to the given address (opt-in, touches the network)}';

    protected $description = 'Guard against a dev mail config (mailpit host / placeholder sender) reaching production.';

    private const DEV_HOSTS = ['mailpit', 'localhost', '127.0.0.1'];

    public function handle(SenderNameSetting $setting): int
    {
        $failures = [];

        if (! app()->environment('local')) {
            $host = Str::lower((string) config('mail.mailers.smtp.host'));
            if ($host !== '' && Str::contains($host, self::DEV_HOSTS)) {
                $failures[] = "MAIL_HOST is '{$host}', a dev mail-catcher with no outbound relay — point it at your ESP (see docs/mail-esp.md).";
            }

            $from = Str::lower((string) config('mail.from.address'));
            if (Str::endsWith($from, 'example.com')) {
                $failures[] = "MAIL_FROM_ADDRESS is '{$from}', a placeholder sender — use a verified address on an SPF+DKIM+DMARC domain (see docs/mail-esp.md).";
            }
        }

        $this->reportSender($setting);

        $queueConnection = (string) config('queue.default');
        if ($queueConnection !== 'sync') {
            $this->warn("QUEUE_CONNECTION is '{$queueConnection}' — a worker must consume the 'mailing' queue or no mail will send (php artisan queue:work --queue=mailing).");
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('mail:preflight FAILED — production would inherit a dev mail config. See docs/mail-esp.md.');

            return self::FAILURE;
        }

        $this->info('mail:preflight OK.');

        if ($address = $this->option('send')) {
            Mail::raw('This is a test email from `php artisan mail:preflight --send`.', function ($message) use ($address): void {
                $message->to($address)->subject('Systema mail:preflight test');
            });
            $this->info("Test email dispatched to {$address}.");
        }

        return self::SUCCESS;
    }

    /**
     * Кто стоит в поле «от кого» и не разошёлся ли .env с собранным конфигом.
     *
     * Имя отправителя видно студенту в списке писем, и оно единственное во всей
     * рассылке: ни один Mailable в app/Mail не задаёт `from:` в envelope, все
     * берут `config('mail.from.name')`. Поэтому печатаем его в каждой проверке —
     * «mail:preflight OK» без имени отправителя уже один раз скрыл расхождение.
     */
    private function reportSender(SenderNameSetting $setting): void
    {
        $this->line('from: '.(string) config('mail.from.name').' <'.(string) config('mail.from.address').'>');

        if (! $setting->isStale()) {
            return;
        }

        $this->warn(sprintf(
            'MAIL_FROM_NAME в .env = «%s», а собранный конфиг использует «%s» — правка .env не действует, пока не пересобран кеш конфига.',
            (string) $setting->raw(),
            (string) $setting->effective(),
        ));
        $this->line(sprintf('  Исправить: php artisan config:cache — или одной командой: php artisan mail:from-name "%s".', (string) $setting->raw()));
    }
}
