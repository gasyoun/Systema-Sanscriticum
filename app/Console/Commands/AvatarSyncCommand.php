<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Avatars\AvatarSyncService;
use Illuminate\Console\Command;

/**
 * Добор/обновление аватарок студентов из Telegram и VK.
 *
 * Источник фото — getChat (TG) и users.get (VK); см. AvatarSyncService.
 * Картинки скачиваются в public-диск (storage/app/public/avatars).
 *
 * REPORT-ONLY по умолчанию. Запись — только с --apply, батчами (--limit),
 * с троттлингом (--sleep, мс), чтобы не упереться в rate-limit API.
 *
 * Бэкфилл существующих:
 *   php artisan avatars:sync                          # сухой прогон
 *   php artisan avatars:sync --apply --limit=200
 *   php artisan avatars:sync --apply --source=vk
 *
 * Периодическое обновление (раз в неделю, см. Kernel): берём тех, кого давно
 * не синхронизировали (--stale-days) или ни разу (avatar_synced_at IS NULL).
 *   php artisan avatars:sync --apply --stale-days=7
 */
class AvatarSyncCommand extends Command
{
    protected $signature = 'avatars:sync
        {--apply : Реально опросить API и скачать аватарки (без флага — сухой прогон)}
        {--source=all : Источник: tg | vk | all}
        {--stale-days= : Брать только тех, кого не синхронизировали N+ дней (или ни разу)}
        {--limit=200 : Максимум пользователей за прогон (батч)}
        {--sleep=60 : Пауза между пользователями, мс (троттлинг)}';

    protected $description = 'Подтянуть аватарки студентов из Telegram/VK (getChat / users.get)';

    public function handle(AvatarSyncService $avatars): int
    {
        $apply = (bool) $this->option('apply');
        $source = (string) $this->option('source');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $staleDays = $this->option('stale-days') !== null ? max(0, (int) $this->option('stale-days')) : null;

        if (! in_array($source, ['all', 'tg', 'vk'], true)) {
            $this->error('--source должен быть tg | vk | all');

            return self::FAILURE;
        }

        $query = User::query()
            ->where(function ($q) use ($source) {
                if ($source === 'tg') {
                    $q->whereNotNull('telegram_id');
                } elseif ($source === 'vk') {
                    $q->whereNotNull('vk_id');
                } else {
                    $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id');
                }
            });

        if ($staleDays !== null) {
            $query->where(function ($q) use ($staleDays) {
                $q->whereNull('avatar_synced_at')
                    ->orWhere('avatar_synced_at', '<', now()->subDays($staleDays));
            });
        }

        $total = (clone $query)->count();
        $batch = $query->orderBy('id')->limit($limit)->get();

        $this->info("Кандидатов (source={$source}".($staleDays !== null ? ", stale>={$staleDays}д" : '')."): {$total}. В этом батче: {$batch->count()}.");

        if (! $apply) {
            $this->comment('Сухой прогон. Запустите с --apply, чтобы опросить API и скачать.');
            foreach ($batch->take(10) as $u) {
                $this->line("  #{$u->id} {$u->email} tg={$u->telegram_id} vk={$u->vk_id}");
            }

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($batch as $user) {
            $ok = match ($source) {
                'tg' => $avatars->syncTelegram($user),
                'vk' => $avatars->syncVk($user),
                default => $avatars->sync($user),
            };

            if ($ok) {
                $updated++;
                $this->line("  #{$user->id} → {$user->avatar_path}");
            } else {
                $skipped++;
            }

            $this->throttle($sleepMs);
        }

        $this->info("Обновлено: {$updated}. Без фото/недоступны: {$skipped}.");

        return self::SUCCESS;
    }

    private function throttle(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
