<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Models\ChatMessage;
use App\Models\DebtReminder;
use App\Models\DebtWinBackAttempt;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Собирает по паре (студент, курс) две ленты — «мы писали» и «он отвечал» — и
 * сводит их в ContactEvidence (H2746).
 *
 * Что считается КОНТАКТОМ. Только то, что действительно ушло студенту и
 * оставило след: строка `debt_reminders` (авто-лестница `debts:remind`) и
 * строка `debt_win_back_attempts` (шаблон реактивации, отправленный куратором).
 * Ручное нажатие «Напомнить в TG» на странице «Должники» следа НЕ оставляет —
 * это известный пробел контура (см. docs/ARCHITECTURE_SYSTEMA_COURSE_DEBT_CHAT_
 * REINSTATEMENT_LEDGER_2026.md, «Пробел: ручные напоминания»). Мы намеренно НЕ
 * додумываем такие контакты: правило исключает человека из чата, и опираться
 * оно должно на запись, а не на память куратора.
 *
 * Что считается ОТВЕТОМ. Любой признак, что студент жив и на связи по этому
 * долгу: входящее сообщение в helpdesk (web-чат или TG-userbot), созданное
 * обещание оплаты, реальный платёж по курсу. Клубные (членские) платежи в
 * ответы НЕ идут — членство к курсовому долгу отношения не имеет.
 */
class DebtContactEvidenceCollector
{
    public const SOURCE_DEBT_REMINDER = 'debt_reminder';

    public const SOURCE_WIN_BACK = 'win_back';

    public const SOURCE_CHAT_MESSAGE = 'chat_message';

    public const SOURCE_TELEGRAM_SUPPORT = 'telegram_support';

    public const SOURCE_PROMISE = 'payment_promise';

    public const SOURCE_PAYMENT = 'payment';

    /**
     * @param  Carbon|null  $since  начало эпизода долга (обычно старт reference-блока);
     *                              контакты и ответы раньше него к эпизоду не относятся
     */
    public function collect(User $user, int $courseId, ?Carbon $since = null, ?Carbon $now = null): ContactEvidence
    {
        $now ??= Carbon::now();

        $attempts = $this->attempts($user, $courseId, $since, $now);
        if ($attempts->isEmpty()) {
            return ContactEvidence::empty();
        }

        $responses = $this->responses($user, $courseId, $since, $now);

        return $this->fold($attempts, $responses, $now);
    }

    /**
     * @return Collection<int, array{source: string, at: Carbon, channel: ?string, ref_id: ?int}>
     */
    private function attempts(User $user, int $courseId, ?Carbon $since, Carbon $now): Collection
    {
        $rows = collect();

        DebtReminder::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
            ->where('sent_at', '<=', $now)
            ->orderBy('sent_at')
            ->get(['id', 'sent_at', 'block_number'])
            ->each(function ($r) use ($rows): void {
                $rows->push([
                    'source' => self::SOURCE_DEBT_REMINDER,
                    'at' => Carbon::parse($r->sent_at),
                    'channel' => 'auto',
                    'ref_id' => (int) $r->id,
                ]);
            });

        // Реактивация не привязана к курсу (журнал H221 хранит только студента).
        // Считаем её контактом по студенту: письмо «вы давно не появлялись»
        // должник получает ровно про эту ситуацию.
        DebtWinBackAttempt::query()
            ->where('user_id', $user->id)
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
            ->where('sent_at', '<=', $now)
            ->orderBy('sent_at')
            ->get(['id', 'sent_at', 'channel'])
            ->each(function ($r) use ($rows): void {
                $rows->push([
                    'source' => self::SOURCE_WIN_BACK,
                    'at' => Carbon::parse($r->sent_at),
                    'channel' => $r->channel !== null ? (string) $r->channel : null,
                    'ref_id' => (int) $r->id,
                ]);
            });

        return $rows->sortBy(fn (array $a): int => $a['at']->getTimestamp())->values();
    }

    /**
     * @return Collection<int, array{source: string, at: Carbon, ref_id: ?int}>
     */
    private function responses(User $user, int $courseId, ?Carbon $since, Carbon $now): Collection
    {
        $rows = collect();
        $userId = (int) $user->id;

        ChatMessage::query()
            ->where('user_id', $userId)
            ->where('role', 'user')
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get(['id', 'created_at'])
            ->each(fn ($r) => $rows->push([
                'source' => self::SOURCE_CHAT_MESSAGE,
                'at' => Carbon::parse($r->created_at),
                'ref_id' => (int) $r->id,
            ]));

        TelegramSupportMessage::query()
            ->where('direction', 'incoming')
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
            ->where('sent_at', '<=', $now)
            ->where(function ($q) use ($userId): void {
                $q->whereHas('chat', fn ($c) => $c->where('linked_user_id', $userId))
                    ->orWhereHas('contact', fn ($c) => $c->where('linked_user_id', $userId));
            })
            ->orderBy('sent_at')
            ->get(['id', 'sent_at'])
            ->each(fn ($r) => $rows->push([
                'source' => self::SOURCE_TELEGRAM_SUPPORT,
                'at' => Carbon::parse($r->sent_at),
                'ref_id' => (int) $r->id,
            ]));

        PaymentPromise::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get(['id', 'created_at'])
            ->each(fn ($r) => $rows->push([
                'source' => self::SOURCE_PROMISE,
                'at' => Carbon::parse($r->created_at),
                'ref_id' => (int) $r->id,
            ]));

        Payment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->paid()
            ->real()
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'first_paid_at', 'tariff'])
            ->reject(fn ($r) => MembershipDebtGuard::isMembershipTariff($r->tariff))
            ->each(fn ($r) => $rows->push([
                'source' => self::SOURCE_PAYMENT,
                'at' => Carbon::parse($r->first_paid_at ?? $r->created_at),
                'ref_id' => (int) $r->id,
            ]));

        return $rows->sortBy(fn (array $a): int => $a['at']->getTimestamp())->values();
    }

    /**
     * Разметить каждую попытку «отвечена / нет» по окну до следующей попытки и
     * посчитать хвост подряд неотвеченных.
     *
     * @param  Collection<int, array{source: string, at: Carbon, channel: ?string, ref_id: ?int}>  $attempts
     * @param  Collection<int, array{source: string, at: Carbon, ref_id: ?int}>  $responses
     */
    private function fold(Collection $attempts, Collection $responses, Carbon $now): ContactEvidence
    {
        $list = $attempts->all();
        $count = count($list);
        $marked = [];

        for ($i = 0; $i < $count; $i++) {
            $from = $list[$i]['at'];
            $until = $i + 1 < $count ? $list[$i + 1]['at'] : $now;

            $answered = $responses->contains(
                fn (array $r): bool => $r['at']->gt($from) && $r['at']->lte($until)
            );

            $marked[] = [
                'source' => $list[$i]['source'],
                'at' => $from->toIso8601String(),
                'channel' => $list[$i]['channel'],
                'ref_id' => $list[$i]['ref_id'],
                'answered' => $answered,
            ];
        }

        $trailing = 0;
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($marked[$i]['answered']) {
                break;
            }
            $trailing++;
        }

        // Молчание началось с первой попытки хвоста: с неё и до сих пор от
        // студента не было ни одного признака жизни по этому долгу.
        $silentSince = $trailing > 0 ? $list[$count - $trailing]['at'] : null;

        return new ContactEvidence(
            attempts: $marked,
            responses: $responses->map(fn (array $r): array => [
                'source' => $r['source'],
                'at' => $r['at']->toIso8601String(),
                'ref_id' => $r['ref_id'],
            ])->all(),
            trailingUnanswered: $trailing,
            lastContactAt: $count > 0 ? $list[$count - 1]['at'] : null,
            silentSince: $silentSince,
        );
    }
}
