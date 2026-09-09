<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\DstShiftAlertMail;
use App\Models\Schedule;
use App\Models\TzAlertSent;
use App\Models\User;
use App\Services\DstShiftAdvisor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H4434 — DST-будильник (MG 09-09-2026).
 *
 * Ежедневный скан юзеров с эффективной зоной ≠ Europe/Moscow. Для каждого
 * находим следующий перевод часов (правила IANA его зоны), первое занятие
 * после перехода и рассылаем 3 напоминания:
 *   - за 7 дней (в 10:00 МСК);
 *   - за день вечером (20:00 ЛОКАЛЬНОГО времени юзера);
 *   - за час до занятия — как допстрока в существующем classes:remind-upcoming.
 *
 * Дедуп: tz_alerts_sent (user, transition_date, stage) — уникальный индекс.
 * VPN-фактор (MG): зона только manual/device/admin источники — IP-гео не участвует.
 */
class RemindDstShifts extends Command
{
    protected $signature = 'tz:remind-dst-shifts';

    protected $description = 'Предупреждает нон-МСК учеников о переводе часов, задевающем их занятия (T−7д, T−1д вечером, T−1ч).';

    public function handle(): int
    {
        // Юзеры с явной нон-МСК зоной. Ни timezone, ни оверрайда — значит МСК
        // или неизвестно; таких не тревожим (никаких догадок по IP).
        $users = User::query()
            ->where(function ($q) {
                $q->whereNotNull('timezone')->orWhereNotNull('tz_override');
            })
            ->with('groups')
            ->chunkById(200, function ($chunk, int $total): void {
                foreach ($chunk as $user) {
                    $this->processUser($user);
                }
            });

        $this->info('DST-скан завершён.');

        return self::SUCCESS;
    }

    private function processUser(User $user): void
    {
        $tz = $user->effectiveTimezone();

        if ($tz === null || $tz === 'Europe/Moscow') {
            return;
        }

        $now = Carbon::now('UTC');
        $transition = DstShiftAdvisor::nextTransition($tz, $now);

        if ($transition === null) {
            return;
        }

        $session = DstShiftAdvisor::firstSessionAfter($user, $transition['date']->copy()->timezone('UTC')->startOfDay());

        if (! $session instanceof Schedule) {
            return; // Переход не задевает занятий — молчим.
        }

        $shift = DstShiftAdvisor::localShiftFor($user, $session);

        if ($shift === null) {
            return; // Локальное время занятия не изменилось (переход в стороне).
        }

        $transitionDate = $transition['date']->toDateString();
        $startLocal = $session->start->copy()->timezone($tz);

        // --- Stage 1: T−7 дней ---
        if ($now->gte($transition['date']->copy()->subDays(7)->timezone($tz)->startOfDay())
            && $now->lt($transition['date']->copy()->subDay())) {
            $this->sendOnce($user, $transitionDate, 'd7', function () use ($shift, $transition, $tz, $session): string {
                return sprintf(
                    "⏰ <b>Перевод часов в вашей стране</b>\n\n".
                    "Намасте! %s в вашей зоне (%s) переводят часы.\n".
                    "Занятие «%s» остаётся в то же московское время (%s МСК), но в вашем времени теперь будет <b>%s</b> вместо %s.\n\n".
                    'Проверьте будильник, чтобы не опоздать.',
                    $transition['date']->timezone($tz)->translatedFormat('d F'),
                    $tz,
                    $session->title ?: 'занятие',
                    $shift['mskBefore'],
                    $shift['after'],
                    $shift['before'],
                );
            });
        }

        // --- Stage 2: T−1 день, вечером по ЛОКАЛЬНОМУ времени юзера (20:00 его часов) ---
        $eveningLocal = $transition['date']->copy()->subDay()->setTime(20, 0, $tz);
        if ($now->gte($eveningLocal->timezone('UTC')) && $now->lt($transition['date']->copy()->timezone('UTC')->startOfDay())) {
            $this->sendOnce($user, $transitionDate, 'd1_evening', function () use ($shift, $tz, $session): string {
                return sprintf(
                    "⏰ <b>Завтра перевод часов</b>\n\n".
                    "Намасте! С завтрашнего дня занятие «%s» в вашем времени (%s) начнётся в <b>%s</b> вместо %s. Московское время занятия не меняется.\n\n".
                    'Не забудьте про сдвиг — иначе можно не попасть на занятие (группа собирается, кворум важен).',
                    $session->title ?: 'занятие',
                    $tz,
                    $shift['after'],
                    $shift['before'],
                );
            });
        }

        // --- Stage 3: T−1 час — вшивается в 60-минутное напоминание (RemindUpcomingClasses).
        // Тут только маркер в БД для дедупа; текст допстроки шлёт classes:remind-upcoming
        // через User::isNonMskTimezone() (см. buildText там же). Отдельного пинга нет.
        if ($now->gte($session->start->copy()->subHour()) && $now->lt($session->start)) {
            TzAlertSent::firstOrCreate([
                'user_id' => $user->id,
                'transition_date' => $transitionDate,
                'stage' => 'd1_hour',
            ]);
        }
    }

    private function sendOnce(User $user, string $transitionDate, string $stage, \Closure $text): void
    {
        $exists = TzAlertSent::query()
            ->where('user_id', $user->id)
            ->where('transition_date', $transitionDate)
            ->where('stage', $stage)
            ->exists();

        if ($exists) {
            return;
        }

        $message = $text();

        // TG — главный канал (есть у ~32 активных); email — резерв (реальный у 99%).
        if ($user->telegram_id) {
            $user->sendTelegramMessage($message);
        } elseif ($user->email) {
            \Mail::to($user->email)->send(new DstShiftAlertMail($user, $message));
        }

        TzAlertSent::create([
            'user_id' => $user->id,
            'transition_date' => $transitionDate,
            'stage' => $stage,
        ]);
    }
}
