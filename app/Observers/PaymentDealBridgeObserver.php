<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Мост «состоявшаяся оплата → сделка» (GC-C1 / H1641, группировка взносов H1659).
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
 *
 * ЧТО СЧИТАЕТСЯ ОДНОЙ ПРОДАЖЕЙ (H1659, ruling 26-07-2026 по развилке F9).
 * Прежняя эвристика «выигранная сделка по этому человеку и этому курсу уже
 * есть → второй платёж молчит» гасила инфляцию воронки на взносах, но заодно
 * прятала НАСТОЯЩУЮ повторную покупку того же курса. Заменена явным маркером:
 * два платежа — одна продажа тогда и только тогда, когда их обещания оплаты
 * делят непустой installment_group_id (его ставит InstallmentPlanCreator).
 * Платёж без группы — сам себе продажа, повторная покупка снова заводит сделку.
 *
 * Цепочка до группы у платежа читается тремя переходами, в этом порядке:
 *   1. payments.linked_promise_id → payment_promises.installment_group_id —
 *      прямая, ставится ещё на создании платежа (DebtPaymentController);
 *   2. payment_promises.fulfilled_payment_id = payments.id — обратная, её
 *      проставляет PromiseAutoFulfiller внутри fireOnPaid, то есть ДО
 *      обсерверов (факт 1 выше), поэтому к нашему вызову она уже видна;
 *   3. план с НЕПОГАШЕННЫМИ обещаниями по этому человеку и курсу — для
 *      кураторского «Подтвердить оплату», которое связывает обещание уже
 *      ПОСЛЕ создания платежа, так что к обсерверу связи ещё нет (см.
 *      unmetPlanFor: почему это не возврат эвристики «человек + курс»).
 * Ничего из этого мы не пишем — обе таблицы только читаем.
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

    /**
     * ПОРЯДОК ШАГОВ ЗДЕСЬ — ЧАСТЬ КОНТРАКТА, а не стиль.
     *
     * Группа рассрочки проверяется РАНЬШЕ поиска открытой сделки (в H1641
     * подавление стояло последним шагом, после закрытия открытой). Причина:
     * второй взнос обязан быть немым целиком — не только «не создавать вторую
     * сделку», но и НЕ ЗАКРЫВАТЬ собой чужую открытую. Стой проверка после
     * findOpenDealFor(), взнос по плану на курс X закрыл бы менеджерскую
     * открытую сделку по тому же курсу X (например, заведённую под будущий
     * апгрейд) — это ровно тот класс «записи не в ту строку», который
     * adversarial-ревью H1641 уже ловило один раз в findOpenDealFor().
     *
     * Свою же сделку группа при этом находит НАПРЯМУЮ, по deals.
     * installment_group_id, а не по человеку и курсу. Поэтому взнос, пришедший
     * после реверса первого (сделка снова открыта), закрывает именно ту сделку,
     * которая уже принадлежит этому плану.
     */
    private function closeOrRecordDeal(Payment $payment): void
    {
        // Идемпотентность: повторная доставка вебхука / повторный save не
        // должны плодить вторую сделку по тому же платежу. Гарантия про ОДИН
        // платёж; к группировке взносов отношения не имеет и ею не ослаблена.
        if (Deal::query()->where('source_payment_id', $payment->id)->exists()) {
            return;
        }

        $won = DealStage::won();
        if ($won === null) {
            return;
        }

        $group = $this->installmentGroupFor($payment);

        if ($group !== null) {
            $grouped = Deal::query()->where('installment_group_id', $group)->oldest()->first();

            if ($grouped !== null) {
                // Продажа по этому плану уже зафиксирована — взнос молчит.
                // Закрытую «в проигрыш» руками тоже не воскрешаем: решение
                // человека важнее автомата (зеркало reopenDealClosedBy).
                if ($grouped->closed_at !== null) {
                    return;
                }

                $this->closeDealWith($grouped, $payment, $won, $group);

                return;
            }
        }

        $deal = $this->findOpenDealFor($payment, $group);

        if ($deal !== null) {
            $this->closeDealWith($deal, $payment, $won, $group);

            return;
        }

        // Открытой сделки нет (прямая покупка без ведения по воронке) —
        // фиксируем состоявшуюся продажу сразу выигранной сделкой, иначе
        // отчётность по воронке слепа к прямым продажам.
        DB::transaction(function () use ($payment, $won, $group): void {
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
                'installment_group_id' => $group,
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
     * Закрытие уже существующей сделки состоявшимся платежом. Обе записи —
     * одним атомарным шагом: иначе падение на moveToStage оставило бы сделку с
     * проставленным ключом идемпотентности, но навсегда незакрытой.
     *
     * Группу проставляем здесь же и только если её ещё нет: со следующего
     * взноса эта сделка находится по группе напрямую, без догадок по человеку
     * и курсу. Уже проставленную группу не перетираем — план у сделки один.
     */
    private function closeDealWith(Deal $deal, Payment $payment, DealStage $won, ?string $group): void
    {
        DB::transaction(function () use ($deal, $payment, $won, $group): void {
            $attrs = ['source_payment_id' => $payment->id];
            if ($group !== null && $deal->installment_group_id === null) {
                $attrs['installment_group_id'] = $group;
            }

            $deal->update($attrs);
            $deal->moveToStage($won, null, Deal::REASON_WON);
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
     * ГРУППА РАССРОЧКИ — ТАКОЙ ЖЕ РАЗЛИЧАЮЩИЙ ПРИЗНАК (найдено adversarial-
     * ревью H1659). Сделка, помеченная ЧУЖИМ планом, этому платежу не своя:
     * переиспользовать её значило бы закрыть чужой план чужими деньгами, а
     * заодно навсегда оставить на ней штамп плана, который её не оплачивал —
     * следующий взнос настоящего владельца упёрся бы в «уже закрыта» и был бы
     * молча съеден. Свою сделку группа находит РАНЬШЕ, отдельной веткой
     * closeOrRecordDeal; сюда доходят только сделки без плана (и, оборонительно,
     * со своим). Платёж БЕЗ группы не трогает сделки планов вовсе.
     *
     * Ничего не пишем — только ищем.
     */
    private function findOpenDealFor(Payment $payment, ?string $group): ?Deal
    {
        $base = Deal::query()->open();

        $base->where(function (Builder $q) use ($group): void {
            $q->whereNull('installment_group_id');
            if ($group !== null) {
                $q->orWhere('installment_group_id', $group);
            }
        });

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

    /**
     * Группа рассрочки, к которой принадлежит платёж, или null, если платёж
     * сам себе продажа (обычная разовая покупка — таких большинство, и
     * linked_promise_id у них попросту нет).
     *
     * КАЖДЫЙ ПЕРЕХОД ЗАЩИЩЁН ОТ NULL и от битой ссылки: удалённое обещание,
     * обещание без группы, платёж без обещания — всё это законные состояния и
     * все дают null, а не исключение. Ранг 4 не имеет права уронить вебхук
     * Точки (см. докблок sync); дополнительно весь метод вызывается изнутри
     * его try/catch.
     *
     * Обе таблицы — ТОЛЬКО ЧТЕНИЕ (SELECT), правило денежной границы §2.2
     * не нарушено; это же проверяет в рантайме тест границы.
     */
    private function installmentGroupFor(Payment $payment): ?string
    {
        // Прямая связь: платёж создан под конкретное обещание (self-service
        // погашение долга/взноса). Есть уже на создании строки платежа.
        if ($payment->linked_promise_id) {
            $group = PaymentPromise::query()
                ->whereKey($payment->linked_promise_id)
                ->value('installment_group_id');

            if ($group !== null && $group !== '') {
                return (string) $group;
            }
        }

        // Обратная связь: обещание уже помечено закрытым этим платежом.
        // Один платёж может закрывать несколько взносов подряд (greedy в
        // PromiseAutoFulfiller::coveredPromises) — группа у них одна и та же,
        // поэтому берём любую непустую.
        $group = PaymentPromise::query()
            ->where('fulfilled_payment_id', $payment->id)
            ->whereNotNull('installment_group_id')
            ->value('installment_group_id');

        if ($group !== null && $group !== '') {
            return (string) $group;
        }

        return $this->unmetPlanFor($payment);
    }

    /**
     * ТРЕТИЙ ПЕРЕХОД — план с НЕПОГАШЕННЫМИ взносами по этому же человеку и
     * курсу. Нужен потому, что кураторское «Подтвердить оплату»
     * (PromiseFulfillment::fulfil) создаёт платёж и лишь ПОТОМ связывает
     * обещание: на момент обсервера ни прямой, ни обратной связи ещё нет.
     * Без этого перехода основной ручной сценарий закрытия рассрочки заводил
     * бы по выигранной сделке НА КАЖДЫЙ взнос — измерено adversarial-ревью
     * H1659 как 3 сделки против 1 до H1659.
     *
     * ЭТО НЕ ВОЗВРАТ ЭВРИСТИКИ «человек + курс». Разница ровно в том, чего
     * требует рулинг: сначала должен СУЩЕСТВОВАТЬ план рассрочки, и он должен
     * быть ещё не закрыт. Полностью оплаченный план прошлого года повторную
     * покупку того же курса больше не глушит — обещаний в статусе
     * active/expired у него нет, переход возвращает null, платёж заводит свою
     * сделку. Старая эвристика глушила такую покупку всегда.
     *
     * Остаточная цена, принятая осознанно: покупка курса, по которому у
     * человека ПРЯМО СЕЙЧАС висит неоплаченный план, будет отнесена к этому
     * плану. Это и есть более вероятное прочтение (человек гасит долг), а
     * альтернатива — молча удвоить воронку на каждом кураторском взносе.
     *
     * Только чтение. Порядок по promised_at: если планов почему-то два, берём
     * самый ранний — тот же порядок, что у PromiseAutoFulfiller::coveredPromises.
     */
    private function unmetPlanFor(Payment $payment): ?string
    {
        if (! $payment->user_id || ! $payment->course_id) {
            return null;
        }

        $group = PaymentPromise::query()
            ->forPair((int) $payment->user_id, (int) $payment->course_id)
            ->whereNotNull('installment_group_id')
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->orderBy('promised_at')
            ->value('installment_group_id');

        return ($group !== null && $group !== '') ? (string) $group : null;
    }

    /**
     * Реверс: платёж откатили из paid. Продажи не было — сделка, закрытая
     * этим платежом, возвращается в первую стадию воронки. Ранг 1 прав,
     * сделка была устаревшей (спека §2.1).
     *
     * НО решение человека важнее автомата: если сделку уже увели с выигрышной
     * стадии руками (например, в «Проиграна»), мы её не воскрешаем — только
     * снимаем привязку к платежу (найдено adversarial-ревью H1641).
     *
     * installment_group_id при этом НЕ снимаем: группа — это тождество продажи,
     * а не след конкретного платежа. Благодаря ей следующий взнос того же плана
     * закроет именно эту, снова открытую сделку, а не заведёт вторую (H1659).
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
