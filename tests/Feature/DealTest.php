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
use App\Models\User;
use App\Observers\PaymentDealBridgeObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GC-C1 сделки + канбан + мост от оплаты (H1641). Покрывает:
 *  (a) модель и гард финальных стадий;
 *  (b) мост: создание/закрытие, исключения, идемпотентность, реверс;
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
        return DealStage::first() ?? $this->fail('deal_stages пуста');
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

        $rankTables = ['payments', 'users', 'leads', 'courses', 'lead_stages', 'course_user', 'lesson_user', 'group_user'];
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
