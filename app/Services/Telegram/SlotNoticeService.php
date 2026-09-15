<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\LessonSeriesInfo;
use App\Services\TeacherVacation;
use App\Support\TelegramSendGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * MG 08-09, слотовые уведомления чату группы от @zapisi_ORSbot.
 *
 * A. «Сегодня занятия нет» — настал обычный день/время занятия (выведено по
 *    последнему прошедшему занятию группы), но строки на сегодня нет (перенос
 *    каскадом или датированная отмена) и будущее занятие существует. Уходит за
 *    lead-минут до обычного времени — в то же окно, что и «Скоро занятие».
 * B. Оплата за блок — после каждого 4-го занятия блока ({N} % 4 === 0,
 *    blockSize=4 у TemplateRenderer): до какого числа ждём оплату (дата
 *    ближайшего занятия = начало следующего блока) и что будет с доступом
 *    неоплатившим и не предупредившим.
 *
 * Дедуп — клеймы TelegramSendGuard: A на (чат, дата) на сутки, B на строку
 * расписания на неделю. Отпуск группы/преподавателя (H3790/H4253) гасит A —
 * там «нет занятия» уже объявлено каникулами.
 *
 * Клейм берётся коротким (INFLIGHT_CLAIM_SECONDS) и продлевается до полного
 * окна только после диспатча. 14-09-2026 процесс умер между недельным клеймом
 * и диспатчем (автодеплой в ту же минуту) — напоминание об оплате так и не ушло,
 * а клейм запрещал повтор неделю. Дубль при повторе гасит контентный клейм
 * SendZapisiBotMessageJob (24 ч).
 *
 * C. Ручная кнопка «Напомнить в чат об оплате» (Расписание / Группы):
 *    paymentDraft() даёт черновик, куратор правит, sendManual() ставит в очередь.
 */
final class SlotNoticeService
{
    /** Ширина окна стрельбы A: планировщик зовёт команду каждые 5 минут. */
    private const FIRE_WINDOW_MINUTES = 10;

    private const BLOCK_SIZE = 4;

    /** Клейм «на время полёта» — ~3 такта планировщика, потом повтор. */
    private const INFLIGHT_CLAIM_SECONDS = 900;

    private const NO_LESSON_CLAIM_SECONDS = 86400;

    private const BLOCK_PAY_CLAIM_SECONDS = 604800;

    public const MANUAL_QUEUED = 'queued';

    public const MANUAL_DUPLICATE = 'duplicate';

    public const MANUAL_NO_CHAT = 'no_chat';

    public function noLessonToday(int $lead): int
    {
        $now = now();
        $sent = 0;

        $groups = Group::query()
            ->whereNotNull('telegram_chat_id')
            ->where('is_on_vacation', false)
            ->get();

        foreach ($groups as $group) {
            if (TeacherVacation::covers($group, $now)) {
                continue;
            }

            // Обычный слот = день недели и время последнего прошедшего занятия.
            $usual = Schedule::query()
                ->where('group_id', $group->id)
                ->where('is_overview', 0)
                ->whereNotNull('start')
                ->where('start', '<', $now->copy()->startOfDay())
                ->orderByDesc('start')
                ->first();

            if ($usual === null || $now->dayOfWeek !== $usual->start->dayOfWeek) {
                continue;
            }

            $fireAt = $now->copy()->startOfDay()->setTimeFrom($usual->start)->subMinutes(max(1, $lead));
            if ($now->lt($fireAt) || $now->gte($fireAt->copy()->addMinutes(self::FIRE_WINDOW_MINUTES))) {
                continue;
            }

            // Что-то сегодня всё же есть (в т.ч. обзорное) — уведомление не нужно.
            $todayRows = Schedule::query()
                ->where('group_id', $group->id)
                ->whereBetween('start', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
                ->count();
            if ($todayRows > 0) {
                continue;
            }

            $next = LessonSeriesInfo::nextRowAfter((int) $group->id, $now);
            if ($next === null) {
                continue;
            }

            $claim = 'tg:nolesson:'.$group->telegram_chat_id.':'.$now->toDateString();
            if (! TelegramSendGuard::claimKey($claim, self::INFLIGHT_CLAIM_SECONDS)) {
                continue;
            }

            SendZapisiBotMessageJob::dispatch((string) $group->telegram_chat_id, $this->noLessonText($next));
            TelegramSendGuard::extendKey($claim, self::NO_LESSON_CLAIM_SECONDS);
            $sent++;

            Log::info('SlotNotice: no-lesson-today notice sent', [
                'group_id' => $group->id,
                'chat_id' => $group->telegram_chat_id,
                'usual_start' => $usual->start->toDateTimeString(),
                'next_schedule_id' => $next->id,
            ]);
        }

        return $sent;
    }

    public function blockPaymentReminders(): int
    {
        $now = now();
        $sent = 0;

        $rows = Schedule::query()
            ->with('group')
            ->where('is_overview', 0)
            ->whereNotNull('group_id')
            ->whereNotNull('end')
            ->whereBetween('end', [$now->copy()->subHours(48), $now])
            ->get();

        foreach ($rows as $row) {
            $group = $row->group;
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            $number = LessonSeriesInfo::number($row->title);
            if ($number === null || $number < 1 || $number % self::BLOCK_SIZE !== 0) {
                continue;
            }

            // Поток закончился — напоминать про оплату следующего блока не о чем.
            $next = LessonSeriesInfo::nextRowAfter((int) $group->id, $row->end);
            if ($next === null) {
                continue;
            }

            $claim = 'tg:blockpay:'.$row->id;
            if (! TelegramSendGuard::claimKey($claim, self::INFLIGHT_CLAIM_SECONDS)) {
                continue;
            }

            SendZapisiBotMessageJob::dispatch((string) $group->telegram_chat_id, $this->paymentText($row, $next));
            TelegramSendGuard::extendKey($claim, self::BLOCK_PAY_CLAIM_SECONDS);
            $sent++;

            Log::info('SlotNotice: block payment reminder sent', [
                'group_id' => $group->id,
                'schedule_id' => $row->id,
                'lesson_number' => $number,
                'pay_until' => $next->start->toDateTimeString(),
            ]);
        }

        return $sent;
    }

    /**
     * Черновик для ручной кнопки. $after — точка отсчёта: конец выбранного
     * занятия (Расписание) или «сейчас» (Группы). Опорное занятие — последнее
     * закончившееся к $after; если им закрылся блок — тот же текст, что у
     * автонапоминания, иначе — «идёт N-й блок, кто не оплатил — до …».
     *
     * @return array{text: string, next: Schedule}|null null — будущих занятий нет
     */
    public function paymentDraft(Group $group, CarbonInterface $after): ?array
    {
        $next = LessonSeriesInfo::nextRowAfter((int) $group->id, $after);
        if ($next === null) {
            return null;
        }

        $reference = Schedule::query()
            ->where('group_id', $group->id)
            ->where('is_overview', 0)
            ->whereNotNull('end')
            ->where('end', '<=', $after)
            ->orderByDesc('end')
            ->first();

        $refNumber = $reference ? LessonSeriesInfo::number($reference->title) : null;
        $text = $reference !== null && $refNumber !== null && $refNumber % self::BLOCK_SIZE === 0
            ? $this->paymentText($reference, $next)
            : $this->inProgressText($next, $reference !== null);

        return ['text' => $text, 'next' => $next];
    }

    /** Ручная отправка: MANUAL_QUEUED | MANUAL_DUPLICATE (тот же текст уже уходил за 24 ч) | MANUAL_NO_CHAT. */
    public function sendManual(Group $group, string $text, ?User $by = null): string
    {
        $chatId = (string) ($group->telegram_chat_id ?? '');
        if ($chatId === '') {
            return self::MANUAL_NO_CHAT;
        }

        $text = trim((string) preg_replace('/\r\n|\r/', "\n", $text));

        if (TelegramSendGuard::isClaimed($chatId, $text)) {
            return self::MANUAL_DUPLICATE;
        }

        SendZapisiBotMessageJob::dispatch($chatId, $text);

        Log::info('SlotNotice: manual payment reminder queued', [
            'group_id' => $group->id,
            'chat_id' => $chatId,
            'user_id' => $by?->id,
        ]);

        return self::MANUAL_QUEUED;
    }

    private function noLessonText(Schedule $next): string
    {
        $text = "📌 <b>Сегодня занятия нет</b>\n\n";
        $text .= 'Следующее занятие: <b>'.$next->start->format('d.m.Y').' в '.$next->start->format('H:i').'</b> (МСК)';
        $text .= CancelNoticeRenderer::ordinalSuffix(
            LessonSeriesInfo::number($next->title),
            LessonSeriesInfo::totalForGroup((int) $next->group_id),
        );
        $text .= '.';

        return $text;
    }

    private function paymentText(Schedule $row, Schedule $next): string
    {
        $number = LessonSeriesInfo::number($row->title);
        $total = LessonSeriesInfo::totalForGroup((int) $row->group_id);

        $text = '💳 <b>Блок занятий завершён'.($number !== null && $total !== null ? ' — '.$number.'-е из '.$total : '')."</b>\n\n";
        $text .= 'Оплату за следующий блок ждём до <b>'.$next->start->format('d.m.Y').'</b> (до ближайшего занятия).'."\n";
        $text .= self::accessClosingLine();

        return $text;
    }

    private function inProgressText(Schedule $next, bool $started): string
    {
        $number = LessonSeriesInfo::number($next->title);
        $block = $number !== null ? (int) ceil($number / self::BLOCK_SIZE).'-й блок занятий' : 'очередной блок занятий';

        $text = "💳 <b>Напоминание об оплате</b>\n\n";
        $text .= ($started ? 'Идёт ' : 'Начинается ').$block.'. Кто ещё не оплатил — оплатите, пожалуйста, до <b>'
            .$next->start->format('d.m.Y').'</b> (до ближайшего занятия).'."\n";
        $text .= self::accessClosingLine();

        return $text;
    }

    private static function accessClosingLine(): string
    {
        return 'Тем, кто не оплатит и не предупредит, закрывается доступ в личный кабинет — '
            .'останутся только игры, просмотр бесплатных вебинаров и возможность оплаты.';
    }
}
