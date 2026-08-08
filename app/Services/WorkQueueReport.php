<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\UnifiedInboxReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Агрегатор «Моя работа сегодня» (H221): бакеты операторской работы поверх
 * УЖЕ существующих сигналов — без новой скоринговой модели. Только чтение;
 * действия (написать/напомнить/открыть) живут на самой странице кокпита.
 *
 *   1) лиды на контакт сегодня      — Lead.next_contact_at <= сегодня;
 *   2) обещания просрочены/истекают — PaymentPromise;
 *   3) риск оттока                  — StuckStudentsReport;
 *   4) поддержка без ответа > N ч    — открытый тред, последнее сообщение входящее;
 *   5) задачи по сделкам на сегодня — FollowUpTask (GC-C3, H1836), за флагом
 *      crm_follow_up_tasks: пока он OFF, бакет пуст и счётчик равен нулю;
 *   6) недожатые open Deal старше N ч — dozhim Wave 1b (H2119), флаг dozhim_queue.
 */
class WorkQueueReport
{
    /** Порог «поддержка без ответа», часов. */
    public const SUPPORT_SLA_HOURS = 3;

    /** Сколько дней вперёд считаем обещание «истекающим». */
    public const PROMISE_EXPIRING_DAYS = 2;

    /** Предел строк на бакет (домашний экран, не полноценный отчёт). */
    public const LIMIT = 50;

    public function __construct(private UnifiedInboxReader $inbox) {}

    /**
     * Лиды, которых пора контактировать: дата следующего контакта наступила и
     * лид ещё в работе (не конверсия/отказ).
     *
     * @return Collection<int, Lead>
     */
    public function leadsToContact(): Collection
    {
        return Lead::query()
            ->whereNotNull('next_contact_at')
            ->whereDate('next_contact_at', '<=', now()->toDateString())
            ->whereNotIn('status', ['converted', 'rejected'])
            ->orderBy('next_contact_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Непогашенные обещания оплаты: просроченные (в т.ч. переведённые демоном в
     * expired) и истекающие в ближайшие дни.
     *
     * @return Collection<int, PaymentPromise>
     */
    public function overduePromises(): Collection
    {
        $horizon = now()->addDays(self::PROMISE_EXPIRING_DAYS)->toDateString();

        return PaymentPromise::query()
            ->with(['user', 'course'])
            ->where(function ($q) use ($horizon): void {
                $q->where(function ($a) use ($horizon): void {
                    $a->where('status', PaymentPromise::STATUS_ACTIVE)
                        ->whereDate('promised_at', '<=', $horizon);
                })->orWhere('status', PaymentPromise::STATUS_EXPIRED);
            })
            ->orderBy('promised_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Застрявшие / риск оттока — переиспользуем существующий отчёт.
     *
     * @return Collection<int, User>
     */
    public function stuckStudents(): Collection
    {
        return StuckStudentsReport::query()
            ->orderBy('last_activity_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Открытые треды поддержки, где последнее сообщение — входящее и висит
     * дольше SLA (человек ещё не ответил).
     *
     * @return Collection<int, array{conversation: SupportConversation, waiting_since: Carbon}>
     */
    public function unansweredSupport(): Collection
    {
        $cutoff = now()->subHours(self::SUPPORT_SLA_HOURS);

        $open = SupportConversation::query()
            ->where('status', '!=', SupportConversation::STATUS_CLOSED)
            ->with('user')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return $open
            ->filter(fn (SupportConversation $c) => $c->user !== null)
            ->map(function (SupportConversation $c) use ($cutoff) {
                $last = $this->inbox->forUser($c->user_id)->last();
                if (! $last || ! $last->isIncoming() || $last->sentAt->gt($cutoff)) {
                    return null;
                }

                return ['conversation' => $c, 'waiting_since' => $last->sentAt];
            })
            ->filter()
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Задачи менеджера по сделкам, у которых наступил срок (GC-C3, H1836).
     * Тот же паттерн, что и у бакета лидов: сравнение по ДАТЕ, свой лимит,
     * сортировка по сроку. Закрытость считаем ТОЛЬКО по done_at самой задачи —
     * состояние сделки задачу не закрывает.
     *
     * За флагом crm_follow_up_tasks: пока он OFF — бакет пуст, ни одного
     * запроса к follow_up_tasks (deploy-рубильник, зеркало Segment/GC-A1).
     *
     * @return Collection<int, FollowUpTask>
     */
    public function followUpTasksDue(): Collection
    {
        if (! config('features.crm_follow_up_tasks')) {
            return collect();
        }

        return FollowUpTask::query()
            ->forDeals()
            ->due()
            ->with(['deal.user', 'deal.lead', 'deal.course', 'assignee'])
            ->orderBy('due_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Недожатые open Deals (H2119 / NOBORING Wave 1b): сделка ещё не закрыта и
     * висит дольше N часов (config/dozhim.php unpaid_deal_hours, default 24).
     * Карточки появляются после open-on-pending (H2102) когда crm_pipeline_board ON.
     *
     * За флагом dozhim_queue: пока OFF — пусто, без запроса к deals.
     *
     * @return Collection<int, Deal>
     */
    public function unpaidOpenDeals(): Collection
    {
        if (! config('features.dozhim_queue')) {
            return collect();
        }

        return $this->agedOpenDeals();
    }

    /**
     * Тот же запрос, что {@see self::unpaidOpenDeals()}, но БЕЗ гейта
     * dozhim_queue (H2059): тот флаг — только видимость бакета оператору,
     * а `dozhim:create-followups`/`dozhim:drip` гейтятся своими флагами
     * (dozhim_queue / dozhim_drip) в самих командах, не здесь.
     *
     * @return Collection<int, Deal>
     */
    public function agedOpenDeals(): Collection
    {
        $hours = max(1, (int) config('dozhim.unpaid_deal_hours', 24));
        $cutoff = now()->subHours($hours);

        return Deal::query()
            ->open()
            ->where('created_at', '<=', $cutoff)
            ->with(['user', 'lead', 'course', 'assignee'])
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Счётчики бакетов для заголовка страницы.
     *
     * @return array{leads:int, promises:int, stuck:int, support:int, follow_ups:int, unpaid_deals:int}
     */
    public function counts(): array
    {
        return [
            'leads' => $this->leadsToContact()->count(),
            'promises' => $this->overduePromises()->count(),
            'stuck' => $this->stuckStudents()->count(),
            'support' => $this->unansweredSupport()->count(),
            'follow_ups' => $this->followUpTasksDue()->count(),
            'unpaid_deals' => $this->unpaidOpenDeals()->count(),
        ];
    }
}
