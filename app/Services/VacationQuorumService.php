<?php

namespace App\Services;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\User;
use App\Models\VacationQuorumPoll;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Опрос кворума по каникульным группам (H3790, фаза C).
 *
 * MG-контракт:
 *  - окно вопроса: 25–31 август (время МСК) — группе с занятиями и БЕЗ даты
 *    выхода из каникул бот @zapisi_ORSbot в group.telegram_chat_id задаёт
 *    «Когда возобновляем занятия? Ответьте reply на это сообщение, иначе
 *    через 2 недели группа расформируется»;
 *  - голос = reply на message_id опроса от ПЛАТНОГО участника группы
 *    (льготники-бесплатники не считаются, дефолт кворума min_size ?? 4);
 *  - дедлайн +14 дней: кворум собран → пинг куратору; нет → предложение о
 *    распускании в админ-чат с inline-кнопками (одновременно появляется в
 *    Filament-очереди VacationQuorumApprovals);
 *  - физическое распускание (снятие занятий + archived) — ТОЛЬКО после
 *    одобрения Гасунса любым каналом.
 */
class VacationQuorumService
{
    /** Окно опроса: 25–31 августа включительно (МСК). */
    public const ASK_WINDOW_START_DAY = 25;

    public const ASK_WINDOW_MONTH = 8;

    public const DEADLINE_DAYS = 14;

    /** callback_data префиксы inline-кнопок одобрения (главный бот). */
    public const CALLBACK_APPROVE = 'vq:approve:';

    public const CALLBACK_DECLINE = 'vq:decline:';

    public function __construct(
        private readonly TelegramAdminNotifier $notifier,
    ) {}

    /**
     * Группы, которым нужно задать вопрос сегодня (окно 25–31.08 и ещё не спрашивали).
     *
     * @return list<Group>
     */
    public function groupsToAsk(): array
    {
        $today = today();

        return Group::query()
            ->where('is_on_vacation', true)
            ->whereNull('vacation_resume_date')
            ->whereNotNull('telegram_chat_id')
            ->whereDoesntHave('vacationQuorumPolls')
            ->get()
            ->filter(fn (Group $group): bool => $this->inAskWindow($today))
            ->filter(fn (Group $group): bool => $this->hasUpcomingSchedule($group))
            ->values()
            ->all();
    }

    public function inAskWindow(?object $today = null): bool
    {
        $today ??= today()->toImmutable();
        $today = $today->toImmutable();

        return (int) $today->month === self::ASK_WINDOW_MONTH
            && $today->day >= self::ASK_WINDOW_START_DAY;
    }

    /** Есть ли хоть одно будущее занятие в расписании группы. */
    public function hasUpcomingSchedule(Group $group): bool
    {
        return $group->schedules()
            ->where('start', '>', now())
            ->exists();
    }

    /** Задать вопрос в чат группы; запоминает message_id по отправке. */
    public function ask(Group $group): VacationQuorumPoll
    {
        $poll = VacationQuorumPoll::create([
            'group_id' => $group->id,
            'chat_id' => (string) $group->telegram_chat_id,
            'asked_at' => now(),
            'deadline_at' => now()->addDays(self::DEADLINE_DAYS),
            'outcome' => VacationQuorumPoll::OUTCOME_PENDING,
            'quorum_required' => $this->quorumRequired($group),
            'paid_voters' => [],
        ]);

        $text = sprintf(
            "Коллеги, намасте! 🙏\n".
            "Группа «%s» сейчас на каникулах.\n".
            "<b>Когда возобновляем занятия?</b>\n".
            'Ответьте, пожалуйста, reply на это сообщение — иначе через 2 недели (%s) группа будет распущена.',
            htmlspecialchars($group->name, ENT_QUOTES, 'UTF-8'),
            $poll->deadline_at->timezone('Europe/Moscow')->format('d.m.Y')
        );

        dispatch(new SendZapisiBotMessageJob((string) $group->telegram_chat_id, $text))
            ->delay(now()->addSeconds(5 + random_int(0, 60)));

        return $poll;
    }

    public function quorumRequired(Group $group): int
    {
        return max(1, (int) ($group->min_size ?? 4));
    }

    /**
     * Reply на сообщение опроса от участника. Возвращает true, если голос учтён.
     */
    public function registerReply(string $chatId, int $replyToMessageId, int $telegramUserId): bool
    {
        /** @var VacationQuorumPoll|null $poll */
        $poll = VacationQuorumPoll::query()
            ->where('chat_id', (string) $chatId)
            ->where('message_id', $replyToMessageId)
            ->whereIn('outcome', [VacationQuorumPoll::OUTCOME_PENDING, VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING])
            ->first();

        if (! $poll) {
            return false;
        }

        $voter = $poll->group->activeUsers()
            ->where('users.telegram_id', $telegramUserId)
            ->first();

        if (! $voter || ! $this->isPaidParticipant($poll->group, $voter)) {
            return false;
        }

        $voters = $poll->paid_voters ?? [];
        if (! in_array($telegramUserId, $voters, true)) {
            $voters[] = $telegramUserId;
            $poll->update(['paid_voters' => $voters]);
        }

        // Кворум мог собраться уже до дедлайна — не ждём, фиксируем.
        if ($poll->outcome === VacationQuorumPoll::OUTCOME_PENDING
            && count($voters) >= $this->quorumRequired($poll->group)) {
            $poll->update([
                'outcome' => VacationQuorumPoll::OUTCOME_QUORUM_MET,
                'resolved_at' => now(),
            ]);
            $this->notifyQuorumMet($poll);
        }

        return true;
    }

    /** Платный участник: ненулевая неусловная оплата по курсу группы. Льготники-бесплатники НЕ считаются (MG). */
    private function isPaidParticipant(Group $group, $user): bool
    {
        $courseIds = $group->courses()->pluck('courses.id')->all();
        if ($courseIds === []) {
            return true; // курс не привязан — сверять не с чем, считаем ответ действительным
        }

        // Ненулевая неусловная оплата (льготное место тут не в счёт: 0 ₽).
        // См. Group::membersTowardMinSize() — но ЛЬГОТНИКОВ ИСКЛЮЧАЕМ.
        foreach ($courseIds as $courseId) {
            $paid = $user->payments()
                ->where('payments.course_id', $courseId)
                ->where('payments.status', 'paid')
                ->where('payments.is_conditional', false)
                ->where('payments.amount', '>', 0)
                ->exists();
            if ($paid) {
                return true;
            }
        }

        return false;
    }

    /** Обработка дедлайнов: pending-опросы с прошедшим дедлайном. */
    public function resolveDue(): void
    {
        VacationQuorumPoll::query()
            ->where('outcome', VacationQuorumPoll::OUTCOME_PENDING)
            ->where('deadline_at', '<', now())
            ->chunkById(50, function ($polls): void {
                foreach ($polls as $poll) {
                    $votes = count($poll->paid_voters ?? []);
                    $required = $this->quorumRequired($poll->group);

                    if ($votes >= $required) {
                        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_QUORUM_MET, 'resolved_at' => now()]);
                        $this->notifyQuorumMet($poll);
                    } else {
                        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING]);
                        $this->proposeDissolution($poll, $votes, $required);
                    }
                }
            });
    }

    private function notifyQuorumMet(VacationQuorumPoll $poll): void
    {
        $this->notifier->notifyAdmins(sprintf(
            "✅ Кворум по каникульной группе\n«%s» (%d/%d платных ответили).\n".
            'Куратору: проставьте дату выхода из каникул в карточке группы.',
            $poll->group->name,
            count($poll->paid_voters ?? []),
            $this->quorumRequired($poll->group)
        ));
    }

    /** Предложение о распускании: админ-чат (inline) + видно в Filament-очереди. */
    private function proposeDissolution(VacationQuorumPoll $poll, int $votes, int $required): void
    {
        $keyboard = [[
            ['text' => 'Распустить группу', 'callback_data' => self::CALLBACK_APPROVE.$poll->id],
            ['text' => 'Оставить', 'callback_data' => self::CALLBACK_DECLINE.$poll->id],
        ]];

        $this->notifier->notifyAdmins(sprintf(
            "⚠️ Каникулы без кворума: «%s»\n".
            "Ответили платных: %d из %d. Дедлайн истёк (%s).\n".
            'Одобрите распускание — будущие занятия снимутся с расписания, группа уедет в архив. Отменить после одобрения нельзя без ручного восстановления.',
            $poll->group->name,
            $votes,
            $required,
            $poll->deadline_at->timezone('Europe/Moscow')->format('d.m.Y')
        ), $keyboard);

        Log::info('VacationQuorum: dissolution proposed', [
            'poll_id' => $poll->id,
            'group_id' => $poll->group_id,
            'votes' => $votes,
            'required' => $required,
        ]);
    }

    /** Одобрение распускания (Гасунс через кнопку или Filament). */
    public function approveDissolution(VacationQuorumPoll $poll, ?User $approver = null): void
    {
        if ($poll->outcome !== VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING) {
            return;
        }

        $group = $poll->group;

        // Будущие занятия группы снимаем с расписания (soft delete — обратимо)
        $removed = $group->schedules()
            ->where('start', '>', now())
            ->get()
            ->each(fn ($schedule) => $schedule->delete())
            ->count();

        $group->update(['status' => 'archived']);

        $poll->update([
            'outcome' => VacationQuorumPoll::OUTCOME_DISSOLVED,
            'resolved_at' => now(),
        ]);

        $this->notifier->notifyAdmins(sprintf(
            '🗑 Группа «%s» распущена (%s) —%d занятий снято.',
            $group->name,
            $approver ? ('одобрил '.($approver->name ?? 'админ')) : 'одобрено в Filament',
            $removed
        ));

        Log::info('VacationQuorum: group dissolved', [
            'poll_id' => $poll->id,
            'group_id' => $group->id,
            'schedules_cancelled' => $removed,
        ]);
    }

    /** Отклонение (оставляем группу). */
    public function declineDissolution(VacationQuorumPoll $poll, ?User $approver = null): void
    {
        if ($poll->outcome !== VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING) {
            return;
        }

        $poll->update([
            'outcome' => VacationQuorumPoll::OUTCOME_DECLINED,
            'resolved_at' => now(),
        ]);

        Log::info('VacationQuorum: dissolution declined', [
            'poll_id' => $poll->id,
            'group_id' => $poll->group_id,
        ]);
    }
}
