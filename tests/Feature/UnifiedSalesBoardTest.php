<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\DealKanbanBoard;
use App\Filament\Pages\LeadKanbanBoard;
use App\Filament\Pages\UnifiedSalesBoard;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use App\Support\Roles;
use App\Support\UnifiedSalesStage;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Сводная доска продаж (F9, H1658) — заявки и сделки в общих колонках.
 *
 * Ключевое, что здесь защищается: унификация живёт ТОЛЬКО в слое
 * представления. Соответствие колонок ни во что не записывается, перенос
 * карточки пишет в родную сущность своим путём (leads.status напрямую,
 * сделка — через moveToStage с журналом), и оба гарда отката финальной стадии
 * ведут себя ровно как на одиночных досках.
 */
class UnifiedSalesBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Тот же deploy-рубильник, что и у доски сделок (см. DealTest).
        config(['features.crm_pipeline_board' => true]);
    }

    private function manager(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);

        return $manager;
    }

    private function lead(string $status, string $name = 'Заявка'): Lead
    {
        return Lead::create(['contact' => '+70000000000', 'name' => $name, 'status' => $status]);
    }

    private function stage(string $key): DealStage
    {
        return DealStage::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Раскладка карточек по колонкам так, как её увидит блейд.
     *
     * @return array<string, list<string>> колонка => id карточек
     */
    private function board(): array
    {
        $component = Livewire::test(UnifiedSalesBoard::class)->instance();
        $data = (fn (): array => $this->getViewData())->call($component);

        return collect($data['statuses'])
            ->mapWithKeys(fn (array $status): array => [
                $status['id'] => collect($status['records'])
                    ->map(fn (Model $record): string => UnifiedSalesBoard::cardId($record))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    // ---------------------------------------------------------------
    // (a) Общий словарь колонок — в обе стороны.
    // ---------------------------------------------------------------

    /** @test */
    public function seeded_lead_and_deal_stages_map_onto_the_four_common_columns(): void
    {
        $this->assertSame(
            ['new', 'in_work', 'won', 'lost'],
            array_keys(UnifiedSalesStage::columns()),
        );

        $this->assertSame([
            'new' => UnifiedSalesStage::NEW,
            'in_work' => UnifiedSalesStage::IN_WORK,
            'qualified' => UnifiedSalesStage::IN_WORK,
            'converted' => UnifiedSalesStage::WON,
            'rejected' => UnifiedSalesStage::LOST,
        ], UnifiedSalesStage::leadStageColumns());

        $this->assertSame([
            'new' => UnifiedSalesStage::NEW,
            'in_progress' => UnifiedSalesStage::IN_WORK,
            'negotiation' => UnifiedSalesStage::IN_WORK,
            'won' => UnifiedSalesStage::WON,
            'lost' => UnifiedSalesStage::LOST,
        ], DealStage::query()->ordered()->get()
            ->mapWithKeys(fn (DealStage $s): array => [$s->key => UnifiedSalesStage::forDeal($s->id)])
            ->all());
    }

    /**
     * @test
     *
     * Обратный проход: колонка → целевая стадия → снова колонка. Если бы
     * подбор целевой стадии расходился с раскладкой, карточка после переноса
     * прыгала бы в чужую колонку.
     */
    public function every_column_resolves_to_a_stage_that_maps_back_to_it(): void
    {
        foreach (array_keys(UnifiedSalesStage::columns()) as $column) {
            $leadKey = UnifiedSalesStage::leadStatusFor($column);
            $this->assertNotNull($leadKey, "нет стадии заявки для колонки {$column}");
            $this->assertSame($column, UnifiedSalesStage::forLead($leadKey));

            $dealStage = UnifiedSalesStage::dealStageFor($column);
            $this->assertNotNull($dealStage, "нет стадии сделки для колонки {$column}");
            $this->assertSame($column, UnifiedSalesStage::forDeal($dealStage->id));
        }

        // Канонические цели переноса на сегодняшнем сиде.
        $this->assertSame('new', UnifiedSalesStage::leadStatusFor(UnifiedSalesStage::NEW));
        $this->assertSame('in_work', UnifiedSalesStage::leadStatusFor(UnifiedSalesStage::IN_WORK));
        $this->assertSame('converted', UnifiedSalesStage::leadStatusFor(UnifiedSalesStage::WON));
        $this->assertSame('rejected', UnifiedSalesStage::leadStatusFor(UnifiedSalesStage::LOST));
        $this->assertSame('in_progress', UnifiedSalesStage::dealStageFor(UnifiedSalesStage::IN_WORK)?->key);
        $this->assertSame('won', UnifiedSalesStage::dealStageFor(UnifiedSalesStage::WON)?->key);
    }

    /**
     * @test
     *
     * Стадия, которой нет в словаре (админ завёл свою), обязана остаться
     * видимой в рабочей колонке, а не исчезнуть с доски.
     */
    public function an_unknown_stage_falls_back_to_the_working_column_instead_of_vanishing(): void
    {
        LeadStage::create(['key' => 'on_hold', 'label' => 'Пауза', 'sort_order' => 6, 'is_final' => false]);
        $custom = DealStage::create(['key' => 'demo', 'name' => 'Демо', 'position' => 6]);

        $this->assertSame(UnifiedSalesStage::IN_WORK, UnifiedSalesStage::forLead('on_hold'));
        $this->assertSame(UnifiedSalesStage::IN_WORK, UnifiedSalesStage::forDeal($custom->id));

        $this->manager();
        $lead = $this->lead('on_hold', 'На паузе');
        $deal = Deal::factory()->create(['stage_id' => $custom->id]);

        $this->assertContains('lead-'.$lead->id, $this->board()[UnifiedSalesStage::IN_WORK]);
        $this->assertContains('deal-'.$deal->id, $this->board()[UnifiedSalesStage::IN_WORK]);
    }

    // ---------------------------------------------------------------
    // (b) Обе сущности на одной доске.
    // ---------------------------------------------------------------

    /** @test */
    public function board_shows_cards_from_both_entities(): void
    {
        $this->manager();

        $lead = $this->lead('qualified', 'Пётр');
        $deal = Deal::factory()->create(['stage_id' => $this->stage('negotiation')->id]);
        $won = Deal::factory()->won()->create();
        $rejected = $this->lead('rejected', 'Ольга');

        $board = $this->board();

        $this->assertContains('lead-'.$lead->id, $board[UnifiedSalesStage::IN_WORK]);
        $this->assertContains('deal-'.$deal->id, $board[UnifiedSalesStage::IN_WORK]);
        $this->assertContains('deal-'.$won->id, $board[UnifiedSalesStage::WON]);
        $this->assertContains('lead-'.$rejected->id, $board[UnifiedSalesStage::LOST]);
    }

    /**
     * @test
     *
     * Ключи двух сущностей пересекаются, поэтому карточки различаются по
     * составному id — иначе drag-drop переносил бы не ту запись.
     */
    public function cards_of_both_kinds_are_rendered_with_distinct_ids_and_a_kind_badge(): void
    {
        $this->manager();

        $lead = $this->lead('new', 'Пётр');
        $deal = Deal::factory()->create();

        $this->assertSame($lead->id, $deal->id, 'предпосылка теста: одинаковые числовые ключи');

        Livewire::test(UnifiedSalesBoard::class)
            ->assertSeeHtml('id="lead-'.$lead->id.'"')
            ->assertSeeHtml('id="deal-'.$deal->id.'"')
            ->assertSee('Заявка')
            ->assertSee('Сделка');

        $this->assertSame(['lead', $lead->id], UnifiedSalesBoard::parseCardId('lead-'.$lead->id));
        $this->assertSame(['deal', $deal->id], UnifiedSalesBoard::parseCardId('deal-'.$deal->id));
        $this->assertNull(UnifiedSalesBoard::parseCardId((string) $lead->id));
        $this->assertNull(UnifiedSalesBoard::parseCardId('user-1'));
    }

    // ---------------------------------------------------------------
    // (c) Перенос пишет в родную сущность — и только в неё.
    // ---------------------------------------------------------------

    /** @test */
    public function dragging_a_lead_writes_the_lead_status_and_nothing_else(): void
    {
        $this->manager();
        $lead = $this->lead('new');

        // Легитимные цели записи ровно те же, что у одиночной доски заявок:
        // сама строка и её аудит-таймлайн (LeadAuditObserver). Всё остальное —
        // и в первую очередь любая таблица сделок — здесь запись не имеет права.
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $verb = strtoupper(trim(explode(' ', ltrim($query->sql))[0] ?? ''));
            if (in_array($verb, ['SELECT', 'PRAGMA'], true)) {
                return;
            }
            $writes[] = $verb.': '.$query->sql;
        });

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'lead-'.$lead->id, UnifiedSalesStage::WON, [], []);

        $this->assertSame('converted', $lead->refresh()->status);

        $offending = array_values(array_filter(
            $writes,
            static fn (string $sql): bool => ! preg_match('/\b(leads|lead_audits)\b/i', $sql),
        ));
        $this->assertSame([], $offending, 'перенос заявки задел чужие таблицы: '.implode(' | ', $offending));
        $this->assertNotSame([], array_filter(
            $writes,
            static fn (string $sql): bool => (bool) preg_match('/^UPDATE: update "?leads"?/i', $sql),
        ), 'перенос заявки вообще ничего не записал в leads');

        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, DealTransition::query()->count());
    }

    /** @test */
    public function dragging_a_deal_goes_through_move_to_stage_and_journals_the_transition(): void
    {
        $manager = $this->manager();
        $deal = Deal::factory()->create();
        $from = $deal->stage_id;

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'deal-'.$deal->id, UnifiedSalesStage::WON, [], []);

        $deal->refresh();
        $this->assertSame($this->stage('won')->id, $deal->stage_id);
        // moveToStage выводит закрытие из самой стадии — прямой update этого не дал бы.
        $this->assertNotNull($deal->closed_at);
        $this->assertSame(Deal::REASON_WON, $deal->closed_reason);

        $transition = DealTransition::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($from, $transition->from_stage_id);
        $this->assertSame($this->stage('won')->id, $transition->to_stage_id);
        $this->assertSame($manager->id, $transition->user_id, 'перенос руками обязан быть подписан менеджером');
    }

    /**
     * @test
     *
     * Колонка «В работе» объединяет две стадии с каждой стороны. Перенос
     * внутри своей же колонки не должен молча понижать стадию и плодить
     * строки журнала.
     */
    public function a_move_inside_the_same_column_changes_nothing(): void
    {
        $this->manager();
        $lead = $this->lead('qualified');
        $deal = Deal::factory()->create(['stage_id' => $this->stage('negotiation')->id]);

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'lead-'.$lead->id, UnifiedSalesStage::IN_WORK, [], [])
            ->call('onStatusChanged', 'deal-'.$deal->id, UnifiedSalesStage::IN_WORK, [], []);

        $this->assertSame('qualified', $lead->refresh()->status, '«Квалифицирован» понижен до «В работе»');
        $this->assertSame($this->stage('negotiation')->id, $deal->refresh()->stage_id);
        $this->assertSame(0, DealTransition::query()->count(), 'холостой перенос записал переход');
    }

    /** @test */
    public function a_card_id_that_is_not_ours_is_ignored(): void
    {
        $this->manager();
        $lead = $this->lead('new');

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', (string) $lead->id, UnifiedSalesStage::WON, [], [])
            ->call('onStatusChanged', 'lead-'.$lead->id, 'qualified', [], []);

        $this->assertSame('new', $lead->refresh()->status);
    }

    // ---------------------------------------------------------------
    // (d) Оба гарда отката — как на одиночных досках.
    // ---------------------------------------------------------------

    /** @test */
    public function both_rollback_guards_refuse_exactly_as_on_the_single_boards(): void
    {
        $this->manager();

        $converted = $this->lead('converted', 'Оплатил');
        $wonDeal = Deal::factory()->won()->create();

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'lead-'.$converted->id, UnifiedSalesStage::NEW, [], [])
            ->call('onStatusChanged', 'deal-'.$wonDeal->id, UnifiedSalesStage::NEW, [], []);

        $this->assertSame('converted', $converted->refresh()->status);
        $this->assertSame($this->stage('won')->id, $wonDeal->refresh()->stage_id);
        $this->assertNotNull($wonDeal->closed_at);
        $this->assertSame(0, DealTransition::query()->count(), 'отклонённый перенос записал переход');
    }

    /** @test */
    public function a_final_card_can_still_be_moved_to_an_intermediate_column(): void
    {
        $this->manager();

        $converted = $this->lead('converted', 'Оплатил');
        $wonDeal = Deal::factory()->won()->create();

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'lead-'.$converted->id, UnifiedSalesStage::IN_WORK, [], [])
            ->call('onStatusChanged', 'deal-'.$wonDeal->id, UnifiedSalesStage::IN_WORK, [], []);

        $this->assertSame('in_work', $converted->refresh()->status);
        $this->assertSame($this->stage('in_progress')->id, $wonDeal->refresh()->stage_id);
        $this->assertNull($wonDeal->closed_at, 'возврат в рабочую стадию обязан переоткрыть сделку');
    }

    // ---------------------------------------------------------------
    // (e) Соответствие колонок никуда не записывается.
    // ---------------------------------------------------------------

    /** @test */
    public function the_common_vocabulary_is_never_persisted_into_either_stage_table(): void
    {
        $this->manager();
        $lead = $this->lead('new');
        $deal = Deal::factory()->create();

        $leadStagesBefore = LeadStage::query()->orderBy('id')->get()->toArray();
        $dealStagesBefore = DealStage::query()->orderBy('id')->get()->toArray();

        Livewire::test(UnifiedSalesBoard::class)
            ->call('onStatusChanged', 'lead-'.$lead->id, UnifiedSalesStage::WON, [], [])
            ->call('onStatusChanged', 'deal-'.$deal->id, UnifiedSalesStage::LOST, [], []);

        $this->assertSame($leadStagesBefore, LeadStage::query()->orderBy('id')->get()->toArray());
        $this->assertSame($dealStagesBefore, DealStage::query()->orderBy('id')->get()->toArray());

        // И всё же переносы отработали — иначе тест зелен просто от бездействия.
        $this->assertSame('converted', $lead->refresh()->status);
        $this->assertSame($this->stage('lost')->id, $deal->refresh()->stage_id);
    }

    /** @test */
    public function the_two_original_boards_are_left_in_place(): void
    {
        $this->manager();

        // Рулинг F9 аддитивен: сводная доска НЕ заменяет ни одну из двух.
        $this->assertTrue(LeadKanbanBoard::canAccess());
        $this->assertTrue(DealKanbanBoard::canAccess());
        $this->assertTrue(UnifiedSalesBoard::canAccess());
    }

    // ---------------------------------------------------------------
    // (f) Флаг.
    // ---------------------------------------------------------------

    /** @test */
    public function page_is_unreachable_while_the_flag_is_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        config(['features.crm_pipeline_board' => false]);
        $this->assertFalse(UnifiedSalesBoard::canAccess());
        $this->assertFalse(UnifiedSalesBoard::shouldRegisterNavigation());

        config(['features.crm_pipeline_board' => true]);
        $this->assertTrue(UnifiedSalesBoard::canAccess());
    }

    /** @test */
    public function page_is_unreachable_for_a_role_outside_the_gate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));

        $this->assertFalse(UnifiedSalesBoard::canAccess());
        $this->assertFalse(UnifiedSalesBoard::shouldRegisterNavigation());
    }
}
