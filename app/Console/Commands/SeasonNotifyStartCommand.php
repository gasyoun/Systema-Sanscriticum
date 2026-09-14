<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramMessageJob;
use App\Mail\SeasonStartMail;
use App\Models\Season;
use App\Models\SeasonNotification;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * H3297 — рассылка «сезон стартует» студентам (email + Telegram).
 *
 * Безопасность:
 *  - мастер-флаг SEASON1_NOTIFY_ENABLED (config season.notify.enabled), по
 *    умолчанию OFF: в живом режиме без флага команда отказывается слать;
 *  - --dry-run работает всегда, ничего не отправляет и не пишет маркеры —
 *    печатает размер аудитории и по одному отрендеренному образцу на канал;
 *  - идемпотентность: маркер (season_id, user_id, channel) в
 *    season_notifications; повторный прогон cron ничего не задваивает;
 *  - сбои отправки логируются и не роняют прогон (failures logged not thrown).
 *
 * Расписание: T-24h до season:open 1 — cron '0 21 30 8 *' (30-08 21:00 UTC).
 */
class SeasonNotifyStartCommand extends Command
{
    protected $signature = 'season:notify-start {season_id?} {--dry-run : показать аудиторию и образцы, ничего не отправляя}';

    protected $description = 'Уведомить студентов о старте сезона (email + Telegram, за 24ч до открытия)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('season.notify.enabled')) {
            $this->error('SEASON1_NOTIFY_ENABLED=false — живая отправка запрещена. Запустите с --dry-run или включите флаг.');

            return 1;
        }

        $season = $this->resolveSeason();

        $audience = self::audienceQuery()->get(['id', 'name', 'email', 'telegram_id']);
        $emailTargets = $audience->filter(fn (User $u) => (bool) $u->email);
        $telegramTargets = $audience->filter(fn (User $u) => (bool) $u->telegram_id);

        $label = $season ? "«{$season->title}» (#{$season->id})" : (string) config('season.defaults.title');
        $mode = $dryRun ? 'DRY-RUN' : 'LIVE';

        $this->info("[{$mode}] Сезон {$label}");
        $this->info("Аудитория (актив за 90 дней ИЛИ любое /lila-событие): {$audience->count()}");
        $this->info('Канал email: '.$emailTargets->count());
        $this->info('Канал Telegram: '.$telegramTargets->count());

        if ($dryRun) {
            $this->printSamples($season);

            return 0;
        }

        $sentEmail = 0;
        $sentTelegram = 0;
        $failed = 0;

        foreach ($emailTargets as $user) {
            if ($this->alreadySent($season?->id ?? (int) config('season.defaults.season_id'), $user->id, SeasonNotification::CHANNEL_EMAIL)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new SeasonStartMail($season));
                $this->markSent($season?->id ?? (int) config('season.defaults.season_id'), $user->id, SeasonNotification::CHANNEL_EMAIL);
                $sentEmail++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('season:notify-start email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($telegramTargets as $user) {
            if ($this->alreadySent($season?->id ?? (int) config('season.defaults.season_id'), $user->id, SeasonNotification::CHANNEL_TELEGRAM)) {
                continue;
            }

            try {
                SendTelegramMessageJob::dispatch($user->id, $this->telegramText($season));
                $this->markSent($season?->id ?? (int) config('season.defaults.season_id'), $user->id, SeasonNotification::CHANNEL_TELEGRAM);
                $sentTelegram++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('season:notify-start telegram dispatch failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Уже получившие (повторный прогон cron после падения середины батча).
        $skippedEmail = $emailTargets->count() - $sentEmail;
        $skippedTelegram = $telegramTargets->count() - $sentTelegram;

        $this->info("Отправлено email: {$sentEmail} (пропущено уже отправленных: {$skippedEmail}).");
        $this->info("Поставлено в очередь Telegram: {$sentTelegram} (пропущено уже отправленных: {$skippedTelegram}).");

        if ($failed > 0) {
            $this->warn("Сбоев: {$failed} (подробности в log). Прогон продолжен.");
        }

        return 0;
    }

    /**
     * Аудитория: студенты (не admin-like персонал), активные за последние 90
     * дней, ЛИБО имеющие хоть одно /lila-событие в game_events.
     */
    public static function audienceQuery(): Builder
    {
        $cutoff = now()->subDays(90);

        return User::query()
            // role IS NULL = обычный студент; исключаем только admin-like персонал.
            // NB: голый whereNotIn отсеял бы и NULL (NULL NOT IN (...) = NULL).
            ->where(function (Builder $q) {
                $q->whereNull('role')->orWhereNotIn('role', Roles::adminLike());
            })
            ->where(function (Builder $q) use ($cutoff) {
                $q->where('last_activity_at', '>=', $cutoff)
                    ->orWhere('last_login_at', '>=', $cutoff)
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw(1)
                            ->from('game_events')
                            ->whereColumn('game_events.user_id', 'users.id');
                    });
            });
    }

    private function resolveSeason(): ?Season
    {
        $seasonId = $this->argument('season_id') ?? config('season.defaults.season_id');

        /** @var Season|null */
        return Season::find($seasonId);
    }

    private function alreadySent(int $seasonId, int $userId, string $channel): bool
    {
        return SeasonNotification::query()
            ->where('season_id', $seasonId)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->exists();
    }

    private function markSent(int $seasonId, int $userId, string $channel): void
    {
        SeasonNotification::create([
            'season_id' => $seasonId,
            'user_id' => $userId,
            'channel' => $channel,
            'sent_at' => now(),
        ]);
    }

    private function printSamples(?Season $season): void
    {
        $mail = new SeasonStartMail($season);

        $this->line('');
        $this->line('=== Образец письма (subject: '.$mail->envelope()->subject.') ===');
        $this->line(strip_tags((string) $mail->render()));
        $this->line('');
        $this->line('=== Образец сообщения Telegram ===');
        $this->line(strip_tags($this->telegramText($season)));
    }

    /**
     * Текст для Telegram (parse_mode HTML у User::sendTelegramMessage).
     */
    private function telegramText(?Season $season): string
    {
        $mail = new SeasonStartMail($season);

        $rewards = collect($mail->rewards())
            ->sortBy('position')
            ->map(fn (array $r) => $r['position'].' место — '.number_format($r['amount'], 0, ',', ' ').' праны')
            ->implode(', ');

        $title = e($mail->seasonTitle());
        $start = $mail->startDate()->locale('ru')->translatedFormat('j F Y');
        $end = $mail->endDate()->locale('ru')->translatedFormat('j F Y');

        return implode("\n", [
            "🙏 <b>{$title}</b>",
            '',
            "Стартует {$start}, продлится до {$end}.",
            '',
            'За решённые раунды начисляется прана и растёт ранг:',
            'Śiṣya → Adhyāyin → Snātaka → Ācārya → Paṇḍita.',
            '',
            'Лидерборд сезона — от базового снапшота: у всех равный отсчёт, а подарки праны между студентами позицию не меняют.',
            '',
            "Призовые места: {$rewards}.",
            '',
            'Играть: '.url('/lila/'),
        ]);
    }
}
