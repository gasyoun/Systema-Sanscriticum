<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\DealKanbanBoard;
use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Observers\PaymentDealBridgeObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GC-C1 сделки + канбан + мост от оплаты (H1641, группировка взносов H1659).
 * Покрывает:
 *  (a) модель и гард финальных стадий;
 *  (b) мост: создание/закрытие, исключения, идемпотентность, реверс;
 *  (b2) что считается ОДНОЙ продажей — по installment_group_id, не по
 *       «человек + курс» (H1659);
 *  (c) флаг crm_pipeline_board — значение по умолчанию И наблюдаемое поведение;
 *  (d) правило денежной границы — мост не пишет ни в один ранг 1-5.
 */
class DealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deploy-рубильник ВЫКЛ по умолчанию — тесты, которым нужен мост/доска,
        // включают флаг явно (зеркало SegmentTest).
        config(['features.crm_pipeline_board' => true]);
    }

    private function wonStage(): DealStage
    {
        return DealStage::won() ?? $this->fail('deal_stages не содержит выигрышной стадии');
    }

    private function firstStage(): DealStage
    {
        return DealStage::firstStage() ?? $this->fail('deal_stages пуста');
    }

    /** Обычная продажа курса: не расход, не депозит, не пробное, не марафон, не conditional. */
    private function makeSale(array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ], $attrs));
    }

    /**
     * План рассрочки: N обещаний с общим installment_group_id — ровно то, что
     * создаёт InstallmentPlanCreator. Строим строки напрямую, чтобы тест не
     * зависел от уведомлений куратора (для строк с группой они и так молчат).
     *
     * @param  list<float|int>  $amounts
     * @return array{0: string, 1: list<PaymentPromise>}
     */
    private function makeInstalmentPlan(User $user, Course $course, array $amounts): array
    {
        $group = (string) Str::uuid();

        $promises = [];
        foreach ($amounts as $i => $amount) {
            $promises[] = PaymentPromise::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'promised_at' => now()->addMonths($i)->toDateString(),
                'amount' => $amount,
                'status' => PaymentPromise::STATUS_ACTIVE,
                'installment_group_id' => $group,
            ]);
        }

        return [$group, $promises];
    }

    // ---------------------------------------------------------------
    // (a) Модель: стадии, журнал переходов, гард отката.
    // ---------------------------------------------------------------

    /** @test */
    public function stage_seed_defines_exactly_one_won_and_one_lost_stage(): void
    {
        $this->assertSame(1, DealStage::query()->where('is_won', true)->count());
        $this->assertSame(1, DealStage::query()->where('is_lost', true)->count());
        $this->assertSame('new', $this->firstStage()->key);
    }

    /** @test */
    public function moving_to_a_final_stage_closes_the_deal_and_logs_a_transition(): void
    {
        $deal = Deal::factory()->create();
        $from = $deal->stage_id;

        $deal->moveToStage($this->wonStage());

        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
        $this->assertSame(Deal::REASON_WON, $deal->closed_reason);

        $transition = DealTransition::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($from, $transition->from_stage_id);
        $this->assertSame($this->wonStage()->id, $transition->to_stage_id);
        $this->assertNull($transition->user_id, 'мост от оплаты пишет переход как «Система»');
    }

    /** @test */
    public function moving_back_to_a_working_stage_reopens_the_deal(): void
    {
        $deal = Deal::factory()->won()->create();

        $deal->moveToStage($this->firstStage());

        $deal->refresh();
        $this->assertNull($deal->closed_at);
        $this->assertNull($deal->closed_reason);
    }

    /** @test */
    public function guard_blocks_silent_rollback_of_a_closed_deal_to_the_first_stage(): void
    {
        $won = $this->wonStage();
        $first = $this->firstStage();

        $this->assertTrue(Deal::blocksRollbackToFirstStage($won->id, $first->id));
        $this->assertFalse(Deal::blocksRollbackToFirstStage($first->id, $won->id));
        $this->assertFalse(Deal::blocksRollbackToFirstStage(null, $first->id));
    }

    // ---------------------------------------------------------------
    // (b) Мост «оплата → сделка».
    // ---------------------------------------------------------------

    /** @test */
    public function qualifying_payment_records_a_won_deal(): void
    {
        $payment = $this->makeSale();

        $deal = Deal::query()->where('source_payment_id', $payment->id)->firstOrFail();
        $this->assertSame($this->wonStage()->id, $deal->stage_id);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame($payment->user_id, $deal->user_id);
        $this->assertSame($payment->course_id, $deal->course_id);
        $this->assertSame('4800.00', (string) $deal->amount);
    }

    /** @test */
    public function qualifying_payment_closes_an_already_open_deal_instead_of_creating_a_second(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        $open = Deal::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->makeSale(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->assertSame(1, Deal::query()->count(), 'мост создал вторую сделку вместо закрытия открытой');
        $open->refresh();
        $this->assertSame($this->wonStage()->id, $open->stage_id);
        $this->assertNotNull($open->closed_at);
    }

    /** @test */
    public function open_deal_is_matched_by_lead_id_when_the_payment_carries_one(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Пётр',
            'contact' => 'p@example.org',
            'email' => 'p@example.org',
            'status' => 'new',
        ]);
        $open = Deal::factory()->create(['lead_id' => $lead->id]);

        $this->makeSale(['lead_id' => $lead->id]);

        $this->assertSame(1, Deal::query()->count());
        $this->assertNotNull($open->refresh()->closed_at);
    }

    /**
     * @test
     *
     * @dataProvider excludedPaymentProvider
     */
    public function excluded_payment_types_never_produce_a_deal(array $attrs): void
    {
        $this->makeSale($attrs);

        $this->assertSame(0, Deal::query()->count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function excludedPaymentProvider(): array
    {
        return [
            'расход' => [['tariff' => 'Расход', 'amount' => -1000]],
            'выплата ЗП' => [['tariff' => 'salary_payout', 'amount' => -5000]],
            'депозит' => [['tariff' => 'deposit']],
            'пробное' => [['tariff' => 'trial']],
            'марафон с проверкой' => [['tariff' => 'marathon_paid', 'course_id' => null]],
            'conditional (доступ под обещание)' => [['is_conditional' => true]],
            'не оплачен' => [['status' => 'pending']],
        ];
    }

    /** @test */
    public function bridge_is_idempotent_across_repeated_saves_of_the_same_payment(): void
    {
        $payment = $this->makeSale();
        $this->assertSame(1, Deal::query()->count());

        // Повторная доставка вебхука / пересохранение той же строки.
        $payment->update(['status' => 'success']);
        $payment->update(['status' => 'paid']);
        app(PaymentDealBridgeObserver::class)->created($payment);

        $this->assertSame(1, Deal::query()->count(), 'мост не идемпотентен: сделка задвоилась');
    }

    // --- Регрессии по находкам adversarial-ревью H1641 ------------------

    /**
     * @test
     *
     * Дефект 1 ревью: ветка сопоставления по лиду игнорировала курс и брала
     * самую старую открытую сделку — оплата курса B закрывала сделку по курсу A.
     */
    public function payment_closes_the_deal_for_its_own_course_not_the_oldest_one(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Ольга', 'contact' => 'o@example.org', 'email' => 'o@example.org', 'status' => 'new',
        ]);
        $courseA = Course::factory()->create(['is_active' => true]);
        $courseB = Course::factory()->create(['is_active' => true]);

        $dealA = Deal::factory()->create(['lead_id' => $lead->id, 'course_id' => $courseA->id, 'amount' => 1000]);
        $dealB = Deal::factory()->create(['lead_id' => $lead->id, 'course_id' => $courseB->id, 'amount' => 9000]);

        $this->makeSale(['lead_id' => $lead->id, 'course_id' => $courseB->id, 'amount' => 9000]);

        $this->assertNull($dealA->refresh()->closed_at, 'закрыта сделка по ЧУЖОМУ курсу');
        $this->assertNotNull($dealB->refresh()->closed_at, 'сделка по оплаченному курсу осталась открытой');
        $this->assertSame(2, Deal::query()->count());
    }

    /** @test */
    public function payment_never_closes_a_deal_belonging_to_a_different_course(): void
    {
        $user = User::factory()->create();
        $courseA = Course::factory()->create(['is_active' => true]);
        $courseB = Course::factory()->create(['is_active' => true]);
        $dealA = Deal::factory()->create(['user_id' => $user->id, 'course_id' => $courseA->id]);

        $this->makeSale(['user_id' => $user->id, 'course_id' => $courseB->id]);

        $this->assertNull($dealA->refresh()->closed_at);
        // Продажа курса B зафиксирована собственной сделкой, а не чужой.
        $this->assertSame(1, Deal::query()->where('course_id', $courseB->id)->count());
    }

    // --- H1659: одна продажа = одна группа рассрочки -----------------------

    /**
     * @test
     *
     * Дефект 2 ревью H1641: рассрочка (несколько платежей по одной продаже)
     * раздувала воронку — каждый взнос заводил отдельную выигранную сделку.
     * Признак «одна продажа» теперь ЯВНЫЙ: общий installment_group_id обещаний
     * (ruling 26-07-2026), а не догадка «тот же человек + тот же курс».
     */
    public function instalments_of_one_plan_produce_a_single_deal(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        [$group, $promises] = $this->makeInstalmentPlan($user, $course, [2400, 2400]);

        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 2400,
            'linked_promise_id' => $promises[0]->id,
        ]);
        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 2400,
            'linked_promise_id' => $promises[1]->id,
        ]);

        $this->assertSame(1, Deal::query()->count(), 'второй взнос завёл вторую выигранную сделку');
        $this->assertSame($group, Deal::query()->firstOrFail()->installment_group_id);
    }

    /**
     * @test
     *
     * ЦЕНА ПРЕЖНЕЙ ЭВРИСТИКИ, которую и покупает ruling: повторная покупка того
     * же курса без рассрочки — ОТДЕЛЬНАЯ продажа. На эвристике «человек + курс»
     * этот тест падал: вторая сделка не заводилась и реальный повторный доход
     * был не виден в воронке.
     */
    public function repurchase_of_the_same_course_without_an_instalment_link_mints_a_second_deal(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        $this->makeSale(['user_id' => $user->id, 'course_id' => $course->id, 'amount' => 4800]);
        $this->makeSale(['user_id' => $user->id, 'course_id' => $course->id, 'amount' => 4800]);

        $this->assertSame(2, Deal::query()->count(), 'повторная покупка снова спрятана от воронки');
        $this->assertSame(2, Deal::query()->whereNotNull('closed_at')->count());
    }

    /**
     * @test
     *
     * Обещание без группы (одиночная договорённость об оплате, не рассрочка) —
     * не признак «это второй взнос уже учтённой продажи».
     */
    public function payment_linked_to_a_promise_without_a_group_is_its_own_sale(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        $this->makeSale(['user_id' => $user->id, 'course_id' => $course->id, 'amount' => 4800]);

        $solo = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        $this->assertNull($solo->installment_group_id);

        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 4800,
            'linked_promise_id' => $solo->id,
        ]);

        $this->assertSame(2, Deal::query()->count());
        $this->assertNull(Deal::query()->latest('id')->firstOrFail()->installment_group_id);
    }

    /**
     * @test
     *
     * ПОРЯДОК ШАГОВ. Взнос по уже учтённому плану обязан быть немым ЦЕЛИКОМ:
     * не только не создавать вторую сделку, но и не закрывать собой чужую
     * открытую сделку по тому же курсу. Стой проверка группы после
     * findOpenDealFor(), второй взнос закрыл бы менеджерскую сделку под
     * будущий апгрейд — та же «запись не в ту строку», что ловило ревью H1641.
     */
    public function second_instalment_never_closes_an_unrelated_open_deal_for_the_same_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        [, $promises] = $this->makeInstalmentPlan($user, $course, [2400, 2400]);

        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 2400,
            'linked_promise_id' => $promises[0]->id,
        ]);

        // Менеджер завёл вторую, ещё не закрытую сделку по тому же курсу.
        $unrelated = Deal::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 2400,
            'linked_promise_id' => $promises[1]->id,
        ]);

        $this->assertNull($unrelated->refresh()->closed_at, 'взнос закрыл чужую открытую сделку');
        $this->assertSame(2, Deal::query()->count(), 'взнос завёл третью сделку');
    }

    /**
     * @test
     *
     * Вторая ветвь цепочки: payment_promises.fulfilled_payment_id. Её ставит
     * PromiseAutoFulfiller внутри fireOnPaid, то есть ДО обсерверов — поэтому
     * группа видна и без linked_promise_id на самом платеже. Платёж создаём
     * молча и зовём мост руками, иначе связь не успела бы возникнуть.
     */
    public function instalment_is_recognised_through_the_reverse_promise_link(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        [, $promises] = $this->makeInstalmentPlan($user, $course, [2400, 2400]);

        $this->makeSale([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 2400,
            'linked_promise_id' => $promises[0]->id,
        ]);

        $second = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2400,
            'tariff' => 'full',
            'status' => 'paid',
        ]));
        $promises[1]->update([
            'status' => PaymentPromise::STATUS_FULFILLED,
            'fulfilled_at' => now(),
            'fulfilled_payment_id' => $second->id,
        ]);

        app(PaymentDealBridgeObserver::class)->created($second);

        $this->assertSame(1, Deal::query()->count(), 'обратная связь обещания не распознана как взнос');
    }

    /**
     * @test
     *
     * Возврат и повторная оплата ТОГО ЖЕ платежа — одна продажа, а не две.
     * Раньше это прикрывала эвристика «человек + курс»; после её снятия
     * держится на переоткрытии сделки (реверс снимает source_payment_id,
     * сделка остаётся открытой и находится снова).
     */
    public function refund_then_repayment_of_the_same_payment_does_not_double_count(): void
    {
        $payment = $this->makeSale();
        $this->assertSame(1, Deal::query()->count());

        $payment->update(['status' => 'canceled']);
        $payment->update(['status' => 'paid']);

        $this->assertSame(1, Deal::query()->count(), 'возврат с последующей оплатой задвоил продажу');
        $deal = Deal::query()->firstOrFail();
        $this->assertNotNull($deal->closed_at);
        $this->assertSame($payment->id, $deal->source_payment_id);
    }

    /** @test */
    public function reversal_does_not_resurrect_a_deal_a_human_moved_off_the_won_stage(): void
    {
        $payment = $this->makeSale();
        $deal = Deal::query()->where('source_payment_id', $payment->id)->firstOrFail();

        // Человек увёл сделку в «Проиграна» уже после автозакрытия.
        $lost = DealStage::query()->where('is_lost', true)->firstOrFail();
        $deal->moveToStage($lost, User::factory()->create()->id);

        $payment->update(['status' => 'canceled']);

        $deal->refresh();
        $this->assertSame($lost->id, $deal->stage_id, 'реверс перетёр решение человека');
        $this->assertNull($deal->source_payment_id, 'привязка к откатанному платежу должна сниматься всегда');
    }

    /**
     * @test
     *
     * Ранг 4 не имеет права вето над рангом 1: падение моста не должно
     * утаскивать за собой подтверждённый платёж.
     */
    public function a_failing_bridge_never_breaks_the_payment_itself(): void
    {
        // Роняем мост изнутри: убираем таблицу, на которую он пишет.
        DB::statement('DROP TABLE deal_transitions');

        $payment = $this->makeSale();

        $this->assertTrue($payment->exists);
        $this->assertSame('paid', $payment->fresh()->status, 'платёж пострадал от падения моста сделок');
    }

    /** @test */
    public function reversing_a_payment_reopens_the_deal_it_closed(): void
    {
        $payment = $this->makeSale();
        $deal = Deal::query()->where('source_payment_id', $payment->id)->firstOrFail();

        $payment->update(['status' => 'canceled']);

        $deal->refresh();
        $this->assertNull($deal->closed_at, 'откат платежа должен снова открыть сделку');
        $this->assertSame($this->firstStage()->id, $deal->stage_id);
        $this->assertNull($deal->source_payment_id);
        $this->assertSame(1, Deal::query()->count());
    }

    // ---------------------------------------------------------------
    // (c) Флаг: наблюдаемое поведение при OFF. Значение ПО УМОЛЧАНИЮ пиннится
    //     отдельным классом DealFlagDefaultTest — этот класс включает флаг в
    //     setUp(), поэтому проверять здесь дефолт было бы враньём (прецедент
    //     SrsFlagDefaultTest: тест дефолта не должен ничего переопределять).
    // ---------------------------------------------------------------

    /** @test */
    public function bridge_is_inert_while_the_flag_is_off(): void
    {
        config(['features.crm_pipeline_board' => false]);

        $this->makeSale();

        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, DealTransition::query()->count());
    }

    /** @test */
    public function board_is_not_registered_while_the_flag_is_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        config(['features.crm_pipeline_board' => false]);
        $this->assertFalse(DealKanbanBoard::canAccess());
        $this->assertFalse(DealKanbanBoard::shouldRegisterNavigation());

        config(['features.crm_pipeline_board' => true]);
        $this->assertTrue(DealKanbanBoard::canAccess());
    }

    // ---------------------------------------------------------------
    // (d) Правило денежной границы (спека §2.1/§2.2, ранг 4).
    // ---------------------------------------------------------------

    /**
     * Зеркало SegmentTest::test_segment_evaluation_never_writes_to_ranks_1_5_tables
     * и WebinarProviderSeamTest::test_zoom_create_meeting_stays_removed_per_gc_b1:
     * проверяем ОТСУТСТВИЕ права записи в рантайме, а не чтением исходника.
     *
     * Мост вызывается НАПРЯМУЮ по уже сохранённому платежу — иначе в замер попали
     * бы записи самого денежного ядра (Payment::booted → grantAccess и т.д.),
     * которые к рангу 4 отношения не имеют. Изолируем ровно мост.
     */
    /** @test */
    public function bridge_writes_only_to_deal_tables_and_never_to_ranks_1_5(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Гуард', 'contact' => 'g@example.org', 'email' => 'g@example.org', 'status' => 'new',
        ]);
        $payment = $this->makeSale(['lead_id' => $lead->id]);

        // Сделка по этому платежу уже создана обсервером при сохранении —
        // снимаем её, чтобы повторный прямой вызов реально что-то писал.
        Deal::query()->delete();
        DealTransition::query()->delete();

        // payment_promises добавлена в H1659: мост читает цепочку до группы
        // рассрочки и обязан оставаться по ней ТОЛЬКО читателем.
        $rankTables = ['payments', 'payment_promises', 'users', 'leads', 'courses', 'lead_stages', 'course_user', 'lesson_user', 'group_user'];
        $countsBefore = collect($rankTables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()]);

        $offending = [];
        DB::listen(function ($query) use (&$offending): void {
            $verb = strtoupper(trim(explode(' ', ltrim($query->sql))[0] ?? ''));
            if (in_array($verb, ['SELECT', 'PRAGMA'], true)) {
                return;
            }
            // Единственные легитимные цели записи — собственные таблицы ранга 4.
            if (! preg_match('/\b(deals|deal_transitions|deal_stages)\b/i', $query->sql)) {
                $offending[] = $verb.': '.$query->sql;
            }
        });

        app(PaymentDealBridgeObserver::class)->created($payment);

        $this->assertSame([], $offending, 'мост записал за пределы своих таблиц: '.implode(' | ', $offending));

        $countsAfter = collect($rankTables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()]);
        $this->assertSame($countsBefore->all(), $countsAfter->all(), 'мост изменил число строк в таблице ранга 1-5');

        // И всё же он отработал — иначе тест «зелёный» просто потому, что ничего не делал.
        $this->assertSame(1, Deal::query()->count());
    }

    /** @test */
    public function bridge_never_converts_the_lead_of_an_ordinary_course_purchase(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Анна', 'contact' => 'a@example.org', 'email' => 'a@example.org', 'status' => 'new',
        ]);

        $this->makeSale(['lead_id' => $lead->id]);

        // Спека §2.4: обычная покупка курса лид НЕ конвертирует. Мост это
        // поведение не «чинит» и статус лида не трогает.
        $lead->refresh();
        $this->assertSame('new', $lead->status);
        $this->assertNull($lead->converted_at);
    }
}
