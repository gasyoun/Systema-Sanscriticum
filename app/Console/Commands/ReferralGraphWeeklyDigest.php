<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * H5024 (MG Q12, 16-09-2026): еженедельный снимок графа рефералов в понедельничный
 * дайджест (чат онбординга, TELEGRAM_ONBOARDING_CHAT_ID — тот же, что у
 * onboarding:weekly-digest). Реферал — канал №1 (325 из 736 известных источников),
 * но users.referred_by давал 0 рёбер с 24-08-2026; ссылка теперь видна на /verify
 * и в письме об оплате, и сводка отвечает на вопрос «появилось ли первое ребро».
 *
 * Датированный ноль — тоже результат: сообщение уходит всегда, даже при нулях.
 * Без настроенного чата — тихий no-op (как OnboardingNotifier). Ничего не пишет.
 */
final class ReferralGraphWeeklyDigest extends Command
{
    protected $signature = 'referral:weekly-graph
        {--dry : Только показать сводку, без отправки в чат}';

    protected $description = 'Еженедельная сводка графа рефералов (users.referred_by) в чат онбординга: рёбра всего / за 7 дней, рефереры, первые оплаты приглашённых';

    public function handle(): int
    {
        $asOf = now();
        $since = $asOf->copy()->subDays(7);

        $edges = User::query()->whereNotNull('referred_by');
        $edgesTotal = (clone $edges)->count();
        $edgesWeek = (clone $edges)->where('created_at', '>=', $since)->count();
        $referrers = (clone $edges)->distinct()->count('referred_by');

        $rewardsTotal = ReferralReward::query()->count();
        $rewardsWeek = ReferralReward::query()->where('created_at', '>=', $since)->count();

        $surfacesOn = (bool) config('partner.enabled', false);

        $lines = [
            '📊 <b>Граф рефералов</b> — на '.$asOf->format('d.m.Y'),
            '',
            'Рёбер users.referred_by всего: <b>'.$edgesTotal.'</b>, за 7 дней: <b>'.$edgesWeek.'</b>',
            'Рефереров (кто привёл хотя бы одного): <b>'.$referrers.'</b>',
            'Первых оплат приглашённых (реф-кредит начислен): всего <b>'.$rewardsTotal.'</b>, за 7 дней: <b>'.$rewardsWeek.'</b>',
            '',
            'Ссылка-приглашение на /verify и в письме об оплате: '.($surfacesOn ? 'включена' : 'выключена (partner.enabled=false)'),
        ];

        if ($edgesTotal === 0) {
            $lines[] = 'Ноль на '.$asOf->format('d.m.Y').' — зафиксирован.';
        }

        $text = implode("\n", $lines);

        foreach ($lines as $line) {
            $this->line(strip_tags($line));
        }

        if ($this->option('dry')) {
            $this->comment('--dry: в чат не отправлено.');

            return self::SUCCESS;
        }

        $chatId = (string) (config('services.telegram.onboarding_chat_id') ?? '');
        if ($chatId === '') {
            $this->comment('TELEGRAM_ONBOARDING_CHAT_ID не задан — сводка не отправлена.');

            return self::SUCCESS;
        }

        SendTelegramChatMessageJob::dispatch($chatId, $text);
        $this->info('Сводка графа рефералов отправлена в чат онбординга.');

        return self::SUCCESS;
    }
}
