<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Материализует три несведённых маппинга внешней идентичности в единый стор
 * `social_accounts` (provider, provider_id, user_id) — см. docs/support-identity.md:
 *   - users.telegram_id / vk_id / max_user_id (денормализованные кэши исходящих);
 *   - TelegramSupportContact.telegram_user_id -> linked_user_id (импорт TG).
 *
 * Идемпотентно: ключ (provider, provider_id) уникален. Существующая строка на ТОГО
 * ЖЕ пользователя не трогается; строка на ДРУГОГО пользователя не переписывается —
 * репортится как конфликт (ручные/OAuth-привязки побеждают). Денормализованные
 * колонки users.* остаются как кэш исходящих отправок — команда их не удаляет.
 */
class ConsolidateSocialIdentities extends Command
{
    protected $signature = 'identity:backfill-social-accounts
        {--apply : Реально записать строки social_accounts (без флага — сухой прогон)}';

    protected $description = 'Свести users.telegram_id/vk_id/max_user_id и TelegramSupportContact в единый стор social_accounts';

    public function handle(): int
    {
        if (! Schema::hasTable('social_accounts')) {
            $this->error('Нет таблицы social_accounts — сначала прогоните миграции.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $created = 0;
        $unchanged = 0;
        $conflicts = [];

        foreach ($this->identityRows() as [$provider, $providerId, $userId, $source]) {
            $existing = SocialAccount::where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if ($existing) {
                if ($existing->user_id === $userId) {
                    $unchanged++;
                } else {
                    $conflicts[] = "  ⚠ {$provider}:{$providerId} уже привязан к user #{$existing->user_id}, "
                        ."а {$source} указывает на #{$userId} — пропущено";
                }

                continue;
            }

            $this->line("  + {$provider}:{$providerId} -> user #{$userId} ({$source})");

            if ($apply) {
                SocialAccount::create([
                    'user_id' => $userId,
                    'provider' => $provider,
                    'provider_id' => $providerId,
                ]);
            }

            $created++;
        }

        $mode = $apply ? 'Создано' : 'Будет создано';
        $this->info("{$mode}: {$created}. Уже сведено: {$unchanged}. Конфликтов: ".count($conflicts).'.');

        foreach ($conflicts as $conflict) {
            $this->warn($conflict);
        }

        if (! $apply) {
            $this->comment('Сухой прогон. Запустите с --apply, чтобы записать строки.');
        }

        return self::SUCCESS;
    }

    /**
     * Плоский поток кандидатов идентичности из всех источников.
     *
     * @return iterable<array{0:string,1:string,2:int,3:string}>
     */
    private function identityRows(): iterable
    {
        $columns = [
            'telegram_id' => SocialAccount::PROVIDER_TELEGRAM,
            'vk_id' => SocialAccount::PROVIDER_VK,
            'max_user_id' => SocialAccount::PROVIDER_MAX,
        ];

        foreach ($columns as $column => $provider) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            foreach (User::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select(['id', $column])
                ->cursor() as $user) {
                yield [$provider, (string) $user->{$column}, (int) $user->id, "users.{$column}"];
            }
        }

        foreach (TelegramSupportContact::query()
            ->whereNotNull('telegram_user_id')
            ->whereNotNull('linked_user_id')
            ->select(['telegram_user_id', 'linked_user_id'])
            ->cursor() as $contact) {
            yield [
                SocialAccount::PROVIDER_TELEGRAM,
                (string) $contact->telegram_user_id,
                (int) $contact->linked_user_id,
                'TelegramSupportContact',
            ];
        }
    }
}
