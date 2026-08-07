<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Приводит существующие email к нормализованному виду (lowercase+trim), чтобы
 * совпадали логин / сброс пароля / поиск на чекауте (см. User::normalizeEmail).
 *
 * REPORT-ONLY по умолчанию: ничего не меняет, показывает, что будет сделано, и
 * ОТДЕЛЬНО — коллизии (когда два аккаунта схлопываются в один email, напр.
 * `Anna@x` и `anna@x`). Коллизии НИКОГДА не сливаются автоматически — это
 * деньги-/доступ-критичные данные, мерж делается вручную куратором.
 *
 *   php artisan users:normalize-emails           # сухой прогон (отчёт)
 *   php artisan users:normalize-emails --apply    # применить безопасные, коллизии — только отчёт
 */
class NormalizeUserEmails extends Command
{
    protected $signature = 'users:normalize-emails {--apply : Применить нормализацию к безопасным (без коллизий) записям}';

    protected $description = 'Нормализовать email пользователей (lowercase+trim); коллизии — только отчёт, без авто-мержа';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Группируем по нормализованному email.
        // Prefix keys: PHP coerces numeric-looking string keys to int
        // (e.g. phone-as-email "89384362599"), which then fails strict
        // comparison against the original string and false-flags no-op rows.
        $byNormalized = [];
        User::query()->select('id', 'email')->orderBy('id')->get()
            ->each(function (User $u) use (&$byNormalized) {
                $norm = User::normalizeEmail($u->email);
                if ($norm === null || $norm === '') {
                    return; // пустые/плейсхолдеры пропускаем
                }
                $byNormalized['e:'.$norm][] = $u;
            });

        $toUpdate = [];   // безопасные: один аккаунт, email отличается от нормализованного
        $collisions = []; // несколько аккаунтов схлопываются в один email

        foreach ($byNormalized as $key => $users) {
            $norm = substr($key, 2); // strip "e:" prefix
            if (count($users) > 1) {
                $collisions[$norm] = $users;

                continue;
            }
            $user = $users[0];
            if ((string) $user->getRawOriginal('email') !== $norm) {
                $toUpdate[] = [$user, $norm];
            }
        }

        $this->info(sprintf('Безопасных к нормализации: %d. Коллизий: %d.', count($toUpdate), count($collisions)));

        // Отчёт по коллизиям — всегда, и в dry-run, и в apply.
        if ($collisions) {
            $this->warn('--- КОЛЛИЗИИ (НЕ тронуты, требуют ручного мержа) ---');
            foreach ($collisions as $norm => $users) {
                $ids = implode(', ', array_map(fn ($u) => $u->id.' ['.$u->getRawOriginal('email').']', $users));
                $this->line("  {$norm} ← {$ids}");
            }
        }

        if (! $apply) {
            $this->comment('Сухой прогон. Запустите с --apply, чтобы применить безопасные изменения (коллизии всё равно не трогаются).');
            if ($toUpdate) {
                $this->line('Примеры к изменению:');
                foreach (array_slice($toUpdate, 0, 10) as [$user, $norm]) {
                    $this->line("  #{$user->id}: {$user->getRawOriginal('email')} → {$norm}");
                }
            }

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($toUpdate as [$user, $norm]) {
            $user->email = $norm; // мутатор нормализует повторно — идемпотентно
            $user->save();
            $updated++;
        }

        $this->info("Применено: {$updated} записей нормализовано.");
        if ($collisions) {
            $this->warn(count($collisions).' коллизий оставлены без изменений — разберите вручную.');
        }

        return self::SUCCESS;
    }
}
