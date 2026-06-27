<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Разовый бэкфилл @username Telegram для уже привязанных студентов.
 *
 * Username мы начали ловить при привязке/сообщении (TelegramWebhookController),
 * но «молчуны», привязавшиеся раньше, останутся без него, пока не напишут боту.
 * Команда добирает их через Telegram Bot API getChat по сохранённому telegram_id.
 *
 * Ограничения Telegram (честно):
 *  - getChat по приватному пользователю работает, только если он запускал бота
 *    (а привязанные — запускали); иначе 400/403 — считаем «недоступен».
 *  - username необязателен — у части людей его просто нет (тогда «без username»).
 *
 * REPORT-ONLY по умолчанию. Запись — только с --apply, батчами (--limit),
 * с троттлингом (--sleep, мс), чтобы не упереться в rate-limit API.
 *
 *   php artisan telegram:backfill-usernames                  # сухой прогон
 *   php artisan telegram:backfill-usernames --apply --limit=200
 *   php artisan telegram:backfill-usernames --apply --all    # обновить и тех, у кого username уже есть
 */
class BackfillTelegramUsernames extends Command
{
    protected $signature = 'telegram:backfill-usernames
        {--apply : Реально записать @username (без флага — сухой прогон)}
        {--all : Опрашивать и тех, у кого username уже сохранён (обновление)}
        {--limit=200 : Максимум пользователей за прогон (батч)}
        {--sleep=60 : Пауза между запросами к API, мс (троттлинг)}';

    protected $description = 'Добрать @username Telegram уже привязанным студентам через getChat';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $all = (bool) $this->option('all');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));

        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        if (! $token) {
            $this->error('Не задан токен бота (STUDENT_TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_TOKEN).');

            return self::FAILURE;
        }

        $query = User::query()->whereNotNull('telegram_id');
        if (! $all) {
            $query->whereNull('telegram_username'); // только те, у кого ещё нет
        }

        $total = (clone $query)->count();
        $batch = $query->orderBy('id')->limit($limit)->get();

        $this->info('Кандидатов (привязан TG'.($all ? '' : ', без username')."): {$total}. В этом батче: {$batch->count()}.");

        if (! $apply) {
            $this->comment('Сухой прогон. Запустите с --apply, чтобы опросить getChat и записать.');
            foreach ($batch->take(10) as $u) {
                $this->line("  #{$u->id} {$u->email} tg_id={$u->telegram_id}");
            }

            return self::SUCCESS;
        }

        $updated = 0;
        $noUsername = 0;
        $unreachable = 0;

        foreach ($batch as $user) {
            try {
                $response = Http::timeout(10)->get(
                    "https://api.telegram.org/bot{$token}/getChat",
                    ['chat_id' => $user->telegram_id],
                );
            } catch (\Throwable $e) {
                $unreachable++;
                $this->warn("  #{$user->id} tg_id={$user->telegram_id}: {$e->getMessage()}");
                $this->throttle($sleepMs);

                continue;
            }

            if (! $response->successful() || ! $response->json('ok')) {
                $unreachable++;
                $desc = $response->json('description') ?? ('HTTP '.$response->status());
                $this->warn("  #{$user->id} tg_id={$user->telegram_id}: {$desc}");
                $this->throttle($sleepMs);

                continue;
            }

            $username = $response->json('result.username');
            if ($user->rememberTelegramUsername($username)) {
                $updated++;
                $this->line("  #{$user->id} → @".$user->telegram_username);
            } else {
                $noUsername++;
            }

            $this->throttle($sleepMs);
        }

        $this->info("Обновлено: {$updated}. Без username/без изменений: {$noUsername}. Недоступны: {$unreachable}.");

        return self::SUCCESS;
    }

    private function throttle(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
