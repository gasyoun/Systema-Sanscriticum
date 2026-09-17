<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Reinvite48hMail;
use App\Models\ActivityEvent;
use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\User;
use App\Services\Access\LoginLinkNotifier;
use App\Services\Access\StudentUnblockService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * H5022 (MG ruling Q14, digital-marketing grill 16-09-2026) — автоматическое
 * повторное приглашение в кабинет через 48 ч после успешной оплаты, если студент
 * ни разу не входил. Process mining (Uprava reports/PROCESS_MINING_STUDENT_FUNNELS_08-09-2026.md):
 * 707 из 940 оплативших никогда не входили (75,2 %); повторное приглашение
 * поднимает вход с 1,8 % до 10,4 %.
 *
 * Когорта: не админ · login_count = 0 и last_login_at пуст · есть оплаченный
 * (status paid/success, не conditional) платёж с first_paid_at в окне
 * [now − lookback-days; now − 48 ч] · нет события reinvite_48h_sent (идемпотентность
 * — ровно ОДНО сообщение на пользователя) · не приглашался вручную/еженедельной
 * каплей за последние 14 дней (cabinet_invite_sent_at).
 *
 * Канал: Telegram, если привязан chat id (тот же бот кабинета, что и остальные
 * личные уведомления); иначе email (транзакционное письмо, очередь mailing).
 * Ни того ни другого — пропуск без штампа (попадает в счётчик «нет канала»).
 *
 * Вход — по одноразовой magic-ссылке /login-link/{token} (назначение admin_unblock,
 * тот же маршрут, что у кнопки «Разблокировать»; TTL здесь 72 ч, а не 24 —
 * письмо читают не в день отправки). Текст ведёт с записей и своего темпа
 * (objection-playbook: «время × запись/свой темп» — верхний лифт), без срочности.
 *
 * После отправки: ActivityEvent reinvite_48h_sent (канал, payment_id) + штамп
 * cabinet_invite_sent_at — чтобы еженедельная students:send-login-invites не
 * продублировала того же студента на следующей неделе.
 *
 * REPORT-ONLY по умолчанию. Рассылка — только с --send. Kill switch —
 * features.reinvite_48h (REINVITE_48H=false + config:cache), по умолчанию ON.
 *
 *   php artisan students:reinvite-48h                   # сухой прогон: кого затронет
 *   php artisan students:reinvite-48h --send --limit=50
 *   php artisan students:reinvite-48h --report          # вход в 7 дней после приглашения vs baseline
 */
class SendPaidNeverLoginReinvite extends Command
{
    /** Минимальный возраст оплаты до повторного приглашения. */
    public const DELAY_HOURS = 48;

    /** Окно измерения для отчёта: вошёл ли в течение N дней после приглашения. */
    public const LOGIN_WINDOW_DAYS = 7;

    /** Baseline из process mining 08-09-2026 (Uprava): вход после повторного приглашения. */
    public const BASELINE_LOGIN_RATE = 10.4;

    /** Свежее ручное/еженедельное приглашение — не дублируем раньше, чем через столько дней. */
    public const RECENT_INVITE_DAYS = 14;

    /** TTL magic-ссылки, часов. */
    public const LINK_TTL_HOURS = 72;

    protected $signature = 'students:reinvite-48h
        {--send : Реально отправить (без флага — сухой прогон)}
        {--limit=50 : Максимум приглашений за один прогон (батч)}
        {--lookback-days=30 : Учитывать оплаты не старше N дней (старых never-login добирает students:send-login-invites)}
        {--report : Отчёт: доля вошедших в течение 7 дней после приглашения против baseline}
        {--report-days=30 : Для --report: приглашения за последние N дней}';

    protected $description = 'H5022: повторное приглашение в кабинет через 48 ч после оплаты без входа (Telegram → email, magic-ссылка, один раз)';

    public function handle(): int
    {
        if ($this->option('report')) {
            return $this->report(max(1, (int) $this->option('report-days')));
        }

        if (! config('features.reinvite_48h', false)) {
            $this->warn('features.reinvite_48h выключен (REINVITE_48H=false) — ничего не делаем.');

            return self::SUCCESS;
        }

        $send = (bool) $this->option('send');
        $limit = max(1, (int) $this->option('limit'));
        $lookbackDays = max(3, (int) $this->option('lookback-days'));

        $query = $this->eligibleQuery($lookbackDays);

        $total = (clone $query)->count();
        $batch = $query->orderBy('id')->limit($limit)->get();

        $counts = ['telegram' => 0, 'email' => 0, 'none' => 0];
        foreach ($batch as $user) {
            $counts[$this->pickChannel($user)]++;
        }

        $this->info(sprintf(
            'Оплатили ≥%d ч назад (окно %d дн.), ни разу не входили, ещё не приглашались: %d. В этом батче: %d (TG: %d, email: %d, без канала: %d).',
            self::DELAY_HOURS, $lookbackDays, $total, $batch->count(), $counts['telegram'], $counts['email'], $counts['none'],
        ));

        if (! $send) {
            $this->comment('Сухой прогон. Запустите с --send, чтобы разослать (батч ограничен --limit).');
            foreach ($batch->take(10) as $u) {
                $this->line("  #{$u->id} → {$this->pickChannel($u)}");
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($batch as $user) {
            $channel = $this->pickChannel($user);
            if ($channel === 'none') {
                $skipped++;

                continue;
            }

            try {
                $link = $this->issueLoginLink($user);
                $ok = $channel === 'telegram'
                    ? $user->sendTelegramMessage($this->telegramText($user, $link))
                    : $this->sendEmail($user, $link);

                if (! $ok) {
                    throw new \RuntimeException('канал отказал (см. лог)');
                }

                $this->markSent($user, $channel);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('reinvite_48h_failed', ['user_id' => $user->id, 'channel' => $channel, 'error' => $e->getMessage()]);
                $this->warn("  #{$user->id} ({$channel}): {$e->getMessage()}");
            }
        }

        $this->info("Отправлено: {$sent}. Ошибок: {$failed}. Без канала: {$skipped}. Осталось: ".max(0, $total - $sent - $skipped).'.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<User>
     */
    public function eligibleQuery(int $lookbackDays): Builder
    {
        $from = now()->subDays($lookbackDays);
        $to = now()->subHours(self::DELAY_HOURS);

        return User::query()
            ->where('is_admin', false)
            ->where('login_count', 0)
            ->whereNull('last_login_at')
            ->whereHas('payments', function (Builder $q) use ($from, $to) {
                $q->whereIn('status', Payment::PAID_STATUSES)
                    ->where('is_conditional', false)
                    ->whereBetween('first_paid_at', [$from, $to]);
            })
            ->whereDoesntHave('activityEvents', fn (Builder $q) => $q->where('event_type', ActivityEvent::REINVITE_48H_SENT))
            ->where(function (Builder $q) {
                $q->whereNull('cabinet_invite_sent_at')
                    ->orWhere('cabinet_invite_sent_at', '<', now()->subDays(self::RECENT_INVITE_DAYS));
            });
    }

    /** Telegram при привязанном chat id, иначе email — если адрес доставляемый. */
    public function pickChannel(User $user): string
    {
        if (! empty($user->telegram_id)) {
            return 'telegram';
        }

        if (LoginLinkNotifier::hasDeliverableEmail($user)) {
            return 'email';
        }

        return 'none';
    }

    private function issueLoginLink(User $user): string
    {
        $token = MagicLinkToken::issueFor($user, StudentUnblockService::MAGIC_PURPOSE, self::LINK_TTL_HOURS * 60);

        return url('/login-link/'.$token);
    }

    private function sendEmail(User $user, string $link): bool
    {
        Mail::to($user->email)->queue(new Reinvite48hMail($user, $link));

        return true;
    }

    private function markSent(User $user, string $channel): void
    {
        $paymentId = $user->payments()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where('is_conditional', false)
            ->whereNotNull('first_paid_at')
            ->orderByDesc('first_paid_at')
            ->value('id');

        DB::table('activity_events')->insert([
            'user_id' => $user->id,
            'session_id' => null,
            'event_type' => ActivityEvent::REINVITE_48H_SENT,
            'event_data' => json_encode([
                'channel' => $channel,
                'payment_id' => $paymentId,
                'link_ttl_hours' => self::LINK_TTL_HOURS,
            ], JSON_UNESCAPED_UNICODE),
            'url' => null,
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $user->forceFill(['cabinet_invite_sent_at' => now()])->save();
    }

    /** Ведём с записей и своего темпа, без срочности (objection playbook, верхний лифт). */
    public function telegramText(User $user, string $link): string
    {
        $help = rtrim((string) config('app.url'), '/').'/help/kabinet';

        return "🙏 <b>Намасте! Ваш кабинет уже открыт</b>\n\n"
            .'Недавно вы оплатили курс — спасибо, что вы с нами. В личном кабинете вас ждут записи занятий и материалы: '
            ."смотреть можно в любое время и в своём темпе, ничего не пропадает, догнать группу можно с любого места.\n\n"
            ."<a href='{$link}'>Войти в кабинет без пароля</a>\n\n"
            .'<i>Ссылка одноразовая и действует '.self::LINK_TTL_HOURS.' часа. Если она перестанет работать — просто ответьте на это сообщение, вышлем новую.</i>'."\n\n"
            .'Как пользоваться кабинетом: '.$help;
    }

    /**
     * Отчёт: среди приглашённых за N дней, у кого приглашение «созрело» (≥7 дней
     * назад), сколько вошли в течение 7 дней — против baseline 10,4 %.
     * Вход = событие login в окне ИЛИ last_login_at в окне (на случай, если
     * трекер события не записал).
     */
    private function report(int $reportDays): int
    {
        $events = DB::table('activity_events')
            ->where('event_type', ActivityEvent::REINVITE_48H_SENT)
            ->where('created_at', '>=', now()->subDays($reportDays))
            ->orderBy('created_at')
            ->get(['user_id', 'created_at', 'event_data']);

        $matured = 0;
        $loggedIn = 0;
        $immature = 0;
        $byChannel = ['telegram' => [0, 0], 'email' => [0, 0]];

        foreach ($events as $event) {
            $sentAt = Carbon::parse($event->created_at);
            $windowEnd = $sentAt->copy()->addDays(self::LOGIN_WINDOW_DAYS);
            $data = json_decode((string) $event->event_data, true) ?: [];
            $channel = $data['channel'] ?? 'email';

            if ($windowEnd->isFuture()) {
                $immature++;

                continue;
            }

            $matured++;
            $byChannel[$channel][0] = ($byChannel[$channel][0] ?? 0) + 1;

            $loginEvent = DB::table('activity_events')
                ->where('user_id', $event->user_id)
                ->where('event_type', ActivityEvent::TYPE_LOGIN)
                ->whereBetween('created_at', [$sentAt, $windowEnd])
                ->exists();

            $lastLogin = DB::table('users')
                ->where('id', $event->user_id)
                ->whereBetween('last_login_at', [$sentAt, $windowEnd])
                ->exists();

            if ($loginEvent || $lastLogin) {
                $loggedIn++;
                $byChannel[$channel][1] = ($byChannel[$channel][1] ?? 0) + 1;
            }
        }

        $rate = $matured > 0 ? round($loggedIn / $matured * 100, 1) : 0.0;

        $this->info(sprintf('Приглашений за %d дн.: %d. Созревших (≥%d дн.): %d, вошли в течение %d дн.: %d (%s%%). Ещё не созрели: %d.',
            $reportDays, $events->count(), self::LOGIN_WINDOW_DAYS, $matured, self::LOGIN_WINDOW_DAYS, $loggedIn, number_format($rate, 1), $immature));
        foreach ($byChannel as $ch => [$n, $k]) {
            if ($n > 0) {
                $this->line(sprintf('  %s: %d/%d (%s%%)', $ch, $k, $n, number_format($k / $n * 100, 1)));
            }
        }
        $this->line(sprintf('Baseline (process mining 08-09-2026, повторное приглашение): %s%%. Вердикт: %s',
            number_format(self::BASELINE_LOGIN_RATE, 1),
            $matured === 0 ? 'INCONCLUSIVE (нет созревших приглашений)' : ($rate >= self::BASELINE_LOGIN_RATE ? 'на уровне baseline или выше' : 'ниже baseline'),
        ));

        return self::SUCCESS;
    }
}
