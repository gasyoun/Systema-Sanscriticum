<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messaging\TelegramBotWebhook;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Messaging\TelegramWebhookRegistry;
use App\Support\TelegramWebhooks;
use Illuminate\Console\Command;
use Throwable;

/**
 * Состояние вебхуков ВСЕХ Telegram-ботов на одном экране и перерегистрация их
 * одной командой.
 *
 * Ради чего. 22.07.2026 переехал входной узел (Telegram → зарубежный nginx →
 * обратный SSH-туннель → прод). Кабинетного бота перерегистрировали, а бот
 * записей остался смотреть на умерший адрес — и пять дней не получал сообщений.
 * Заметить это было нечем: URL вебхука не хранился нигде, каждая команда строила
 * его из app.url, а кабинетный бот регистрировался вручную через curl. Колонка
 * «совпадает» ниже отвечает на вопрос «куда вообще смотрит бот» за секунды.
 */
class SyncTelegramWebhooks extends Command
{
    protected $signature = 'telegram:webhooks
        {--set : Перерегистрировать вебхуки на текущий входной узел (по умолчанию — только показать)}
        {--only= : Ограничить одним ботом или семейством — cabinet, magnet, zapisi, landing}
        {--strict : Ненулевой код выхода при расхождении или ошибке (для cron/CI)}';

    protected $description = 'Показать (или перерегистрировать) вебхуки всех Telegram-ботов: кабинет, лид-магнит, лендинг-боты, записи.';

    public function handle(TelegramWebhookRegistry $registry, TelegramDeliveryChannel $telegram): int
    {
        $bots = $this->filter($registry->all());

        if ($bots === []) {
            $this->warn('Ботов с вебхуками не найдено (проверьте --only и настройки токенов).');

            return self::SUCCESS;
        }

        $this->line('Входной узел: <info>'.TelegramWebhooks::baseUrl().'</info>'
            .(TelegramWebhooks::usesEntryNode() ? ' (TELEGRAM_WEBHOOK_BASE_URL)' : ' (APP_URL)'));

        try {
            $certificate = TelegramWebhooks::certificateContents();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($certificate !== null) {
            $this->line('Сертификат узла: <info>будет загружен в Telegram</info> (самоподписанный вход).');
        }

        $rows = [];
        $problems = [];

        foreach ($bots as $bot) {
            // Без токена спрашивать Telegram нечем; без секрета — регистрировать
            // нельзя (получился бы живой вебхук, который fail-closed middleware
            // отбивает 403, снаружи неотличимо от тишины). В обоих случаях
            // показываем проблему, а не прячем её.
            if (! $bot->hasToken() || ($this->option('set') && ! $bot->isRegisterable())) {
                $problems[] = "{$bot->label}: {$bot->problem}";
                $rows[] = [$bot->label, '—', '—', '—', $bot->problem];

                continue;
            }

            try {
                $client = $telegram->usingCredentials($bot->token, $bot->username ?? '');

                if ($this->option('set') && $bot->isRegisterable()) {
                    $client->setWebhook($bot->url, $bot->secret, $bot->allowedUpdates, $certificate);
                }

                $info = $client->getWebhookInfo();
                $current = (string) ($info['url'] ?? '');
                $matches = $current === $bot->url;

                if (! $matches) {
                    $problems[] = "{$bot->label}: зарегистрирован на «{$current}», ожидается «{$bot->url}»";
                }
                if (! empty($info['last_error_message'])) {
                    $problems[] = "{$bot->label}: последняя ошибка доставки — {$info['last_error_message']}";
                }

                $rows[] = [
                    $bot->label,
                    $current !== '' ? $current : '— не зарегистрирован —',
                    $matches ? 'да' : 'НЕТ',
                    (string) ($info['pending_update_count'] ?? 0),
                    (string) ($info['last_error_message'] ?? ''),
                ];
            } catch (Throwable $e) {
                // Один упавший бот не должен прятать состояние остальных.
                $problems[] = "{$bot->label}: {$e->getMessage()}";
                $rows[] = [$bot->label, 'ошибка запроса', '?', '?', $e->getMessage()];
            }
        }

        $this->table(['Бот', 'Зарегистрирован', 'Совпадает', 'Ждут', 'Последняя ошибка'], $rows);

        if ($problems === []) {
            $this->info('Все вебхуки на месте.');

            return self::SUCCESS;
        }

        $this->warn('Расхождения:');
        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }

        if (! $this->option('set')) {
            $this->line('Починить: <info>php artisan telegram:webhooks --set</info>');
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<TelegramBotWebhook>  $bots
     * @return list<TelegramBotWebhook>
     */
    private function filter(array $bots): array
    {
        $only = (string) ($this->option('only') ?? '');
        if ($only === '') {
            return $bots;
        }

        return array_values(array_filter(
            $bots,
            fn (TelegramBotWebhook $bot): bool => $bot->key === $only || str_starts_with($bot->key, $only.':'),
        ));
    }
}
