<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Мост «состоявшаяся оплата → сделка» (GC-C1 / H1641).
 *
 * ОТДЕЛЬНЫЙ обсервер по прецеденту PaymentAuditObserver / PaymentTelemetryObserver:
 * денежную логику PaymentObserver не трогаем и внутрь него не лезем.
 *
 * РАНГ 4 лестницы полномочий (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2):
 * читаем СОСТОЯВШИЙСЯ денежный переход и пишем ТОЛЬКО в deals/deal_transitions.
 * Ни доступа, ни цены, ни статуса платежа, ни статуса лида этот класс не меняет.
 * Lead::markConverted() отсюда НЕ вызывается — это делает Payment на своих
 * трёх путях (депозит/пробное/марафон), и обычная покупка курса лид не
 * конвертирует (спека §2.4). Мост на это поведение не опирается и его не чинит.
 *
 * Три факта из §2.3, на которых построен класс:
 *  1. доступ выдаётся из Payment::booted() → fireOnPaid(), ДО обсерверов, —
 *     значит к моменту нашего вызова денежный переход уже завершён;
 *  2. created/updated в PaymentObserver срабатывают на ЛЮБУЮ платную строку,
 *     включая бухгалтерские, — набор исключений приходится повторять здесь
 *     самим (qualifiesAsSale ниже);
 *  3. предикат события — wasChanged('status'), как у двух соседних аддитивных
 *     обсерверов (развилка F4 спеки §7, решена в пользу wasChanged).
 *
 * ИЗВЕСТНОЕ ОГРАНИЧЕНИЕ (не дефект, вынесено человеку): погашение «обещания»
 * снимает is_conditional БЕЗ смены статуса, поэтому updated() такой платёж не
 * увидит и сделку по нему не заведёт. Пропущенная продажа в воронке, не порча
 * данных; чинить — только расширением предиката, что трогает денежный путь.
 */
class PaymentDealBridgeObserver
{
    /** Статусы отката платежа — зеркало реверса в PaymentObserver (строки 71–76). */
    private const REVERSAL_STATUSES = ['failed', 'canceled', 'cancelled'];

    /** Платёж может родиться сразу paid (ручное создание, нулевая оплата промокодом). */
    public function created(Payment $payment): void
    {
        $this->sync($payment);
    }

    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        $this->sync($payment);
    }

    /**
     * РАНГ 4 НЕ ИМЕЕТ ПРАВА ВЕТО НАД РАНГОМ 1. Обсервер вызывается внутри
     * транзакции вебхука Точки (WebhookController оборачивает переход статуса
     * в DB::transaction), поэтому НЕОБРАБОТАННОЕ исключение отсюда откатило бы
     * ПОДТВЕРЖДЁННЫЙ БАНКОМ ПЛАТЁЖ. Самый реальный сценарий — флаг включили
     * раньше, чем прогнали миграции: «no such table: deals» отменяет оплату.
     * Ловим всё и логируем: сделка — производная сущность, её потеря никогда
     * не должна стоить денежной строки (найдено adversarial-ревью H1641).
     */
    private function sync(Payment $payment): void
    {
        // Флаг ВЫКЛ по умолчанию → на проде мост инертен.
        if (! config('features.crm_pipeline_board')) {
            return;
        }

        try {
            if (in_array($payment->status, self::REVERSAL_STATUSES, true)) {
                $this->reopenDealClosedBy($payment);

                return;
            }

            if (! $this->qualifiesAsSale($payment)) {
                return;
            }

            $this->closeOrRecordDeal($payment);
        } catch (\Throwable $e) {
            Log::error('GC-C1: мост сделок упал, платёж не тронут', [
                'payment_id' => $payment->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Тот же набор исключений, что применяет Payment::fireOnPaid, плюс
     * is_conditional. Бухгалтерская строка, депозит, пробное занятие и
     * марафон «с проверкой» продажей курса не являются и сделку не закрывают.
     *
     * Нулевые access-only siblings (BlockAccessMaterializer) сюда не доходят:
     * они создаются внутри Payment::withoutEvents(), обсерверы по ним молчат.
     */
    private function qualifiesAsSale(Payment $payment): bool
    {
        return in_array($payment->status, Payment::PAID_STATUSES, true)
            && ! $payment->isExpense()
            && ! $payment->isSalaryPayout()
            && ! $payment->isDeposit()
            && ! $payment->isTrial()
            && ! $payment->isMarathonPaid()
            && ! $payment->is_conditional;
    }

    private function closeOrRecordDeal(Payment $payment): void
    {
        // Идемпотентность: повторная доставка вебхука / повторный save не
        // должны плодить вторую сделку по тому же платежу.
        if (Deal::query()->where('source_payment_id', $payment->id)->exists()) {
            return;
        }

        $won = DealStage::won();
        if ($won === null) {
            return;
        }

        $deal = $this->findOpenDealFor($payment);

        if ($deal !== null) {
            // Обе записи — одним атомарным шагом: иначе падение на moveToStage
            // оставило бы сделку с проставленным ключом идемпотентности, но
            // навсегда незакрытой.
            DB::transaction(function () use ($deal, $payment, $won): void {
                $deal->update(['source_payment_id' => $payment->id]);
                $deal->moveToStage($won, null, Deal::REASON_WON);
            });

            return;
        }

        // Рассрочка/доплата/поблочная покупка — это НЕСКОЛЬКО платежей по ОДНОЙ
        // продаже. Если выигранная сделка по этому же человеку и курсу уже есть,
        // второй платёж не заводит вторую сделку, иначе воронка раздувалась бы
        // на каждом взносе (найдено adversarial-ревью H1641).
        //
        // Цена решения: повторная покупка ТОГО ЖЕ курса спустя время тоже не
        // заведёт вторую сделку. Осознанный размен в пользу неинфляции отчётов;
        // вынесено человеку вместе с развилкой F9.
        if ($this->alreadyRecordedAsSale($payment)) {
            return;
        }

        // Открытой сделки нет (прямая покупка без ведения по воронке) —
        // фиксируем состоявшуюся продажу сразу выигранной сделкой, иначе
        // отчётность по воронке слепа к прямым продажам.
        DB::transaction(function () use ($payment, $won): void {
            $deal = Deal::create([
                'lead_id' => $payment->lead_id,
                'user_id' => $payment->user_id,
                'course_id' => $payment->course_id,
                'amount' => $payment->amount ?? 0,
                'currency' => 'RUB',
                'stage_id' => $won->id,
                'closed_at' => now(),
                'closed_reason' => Deal::REASON_WON,
                'source_payment_id' => $payment->id,
            ]);

            DealTransition::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $won->id,
                'user_id' => null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Сопоставление платежа с уже ведущейся сделкой. lead_id — приоритетный
     * ключ (так тикет и сформулирован), но спека сама отмечает в §4.1, что
     * payments.lead_id почти всегда пуст, поэтому есть запасной путь по user_id.
     *
     * КУРС — РАЗЛИЧАЮЩИЙ ПРИЗНАК И ПО ЛИДУ ТОЖЕ. Смысл отдельной сущности Deal
     * ровно в том, что у одного человека может быть НЕСКОЛЬКО сделок (второй
     * курс, апгрейд тарифа); закрывать оплатой курса B сделку по курсу A —
     * запись не в ту строку. Раньше ветка по лиду брала просто самую старую
     * открытую сделку и делала именно это (найдено adversarial-ревью H1641).
     *
     * Ничего не пишем — только ищем.
     */
    private function findOpenDealFor(Payment $payment): ?Deal
    {
        $base = Deal::query()->open();

        if ($payment->lead_id) {
            $base->where('lead_id', $payment->lead_id);
        } elseif ($payment->user_id) {
            $base->where('user_id', $payment->user_id);
        } else {
            return null;
        }

        if (! $payment->course_id) {
            return $base->oldest()->first();
        }

        $exact = (clone $base)->where('course_id', $payment->course_id)->oldest()->first();
        if ($exact !== null) {
            return $exact;
        }

        // Сделка, у которой курс ещё не проставлен, может быть «той самой».
        // Сделку по ДРУГОМУ курсу этим платежом не трогаем никогда.
        return (clone $base)->whereNull('course_id')->oldest()->first();
    }

    /** Уже есть выигранная сделка по этому же человеку и курсу? */
    private function alreadyRecordedAsSale(Payment $payment): bool
    {
        $query = Deal::query()
            ->whereNotNull('closed_at')
            ->where('closed_reason', Deal::REASON_WON);

        if ($payment->lead_id) {
            $query->where('lead_id', $payment->lead_id);
        } elseif ($payment->user_id) {
            $query->where('user_id', $payment->user_id);
        } else {
            return false;
        }

        if ($payment->course_id) {
            $query->where('course_id', $payment->course_id);
        }

        return $query->exists();
    }

    /**
     * Реверс: платёж откатили из paid. Продажи не было — сделка, закрытая
     * этим платежом, возвращается в первую стадию воронки. Ранг 1 прав,
     * сделка была устаревшей (спека §2.1).
     *
     * НО решение человека важнее автомата: если сделку уже увели с выигрышной
     * стадии руками (например, в «Проиграна»), мы её не воскрешаем — только
     * снимаем привязку к платежу (найдено adversarial-ревью H1641).
     */
    private function reopenDealClosedBy(Payment $payment): void
    {
        $deal = Deal::query()->where('source_payment_id', $payment->id)->first();
        if ($deal === null) {
            return;
        }

        $won = DealStage::won();
        $first = DealStage::firstStage();

        // Снимаем ключ идемпотентности всегда: если платёж позже снова станет
        // paid, сделка должна закрыться заново.
        $deal->update(['source_payment_id' => null]);

        if ($won === null || $first === null || $deal->stage_id !== $won->id) {
            return;
        }

        $deal->moveToStage($first, null);
    }
}
