<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramSupport\SupportContactNameLinker;
use Illuminate\Console\Command;

/**
 * Ручная сшивка контактов поддержки с пользователями по ФИО.
 *
 * Это НЕ та автоматическая сшивка, что работает при синхронизации (там только
 * telegram_id и username — политика, закреплённая тестом
 * `test_auto_linker_does_not_match_by_full_name_without_telegram_identity`).
 * Здесь ФИО разбирает человек: команда по умолчанию сухая, показывает пары и
 * пишет только с `--apply`.
 *
 * Зачем: студент писал в аккаунт школы, диалог есть, но в кабинете он не
 * привязан — канал «от имени аккаунта» в напоминаниях об оплате его не видит.
 */
class LinkSupportContacts extends Command
{
    protected $signature = 'telegram-support:link-contacts
        {--apply : Записать сшивки (без флага — сухой прогон)}
        {--limit=25 : Сколько пар показать в таблице}';

    protected $description = 'Сшивает контакты поддержки с пользователями по однозначному ФИО (по умолчанию — сухой прогон)';

    public function handle(SupportContactNameLinker $linker): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(5, (int) $this->option('limit'));

        $result = $linker->linkByName($apply);
        $samples = array_slice($result['samples'], 0, $limit);

        $this->info(sprintf(
            '%s: однозначных пар по ФИО %d',
            $apply ? 'Записано' : 'Сухой прогон — найдено',
            $result['linked'],
        ));

        if ($samples === []) {
            $this->line('Пар не найдено.');

            return self::SUCCESS;
        }

        $this->table(
            ['contact_id', 'контакт в Telegram', 'chat_id', 'user_id', 'пользователь кабинета'],
            array_map(static fn (array $s): array => [
                $s['contact_id'],
                $s['contact_name'],
                $s['chat_id'] ?? '—',
                $s['user_id'],
                $s['user_name'],
            ], $samples),
        );

        if (! $apply) {
            $this->warn('Это сухой прогон, ничего не записано. Повторите с --apply.');
        }

        return self::SUCCESS;
    }
}
