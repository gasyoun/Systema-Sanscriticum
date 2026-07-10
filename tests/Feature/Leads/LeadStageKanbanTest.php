<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Filament\Pages\LeadKanbanBoard;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GC-C1 / H451: lead_stages как источник данных для Lead::statuses()/finalStatuses(),
 * и гард финальной стадии на канбан-доске (тот же Lead::blocksRollbackToFirstStage,
 * что и в LeadResource — см. LeadWorkflowUxTest).
 */
class LeadStageKanbanTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);

        return $manager;
    }

    /** @test */
    public function migration_seeds_five_stages_matching_legacy_statuses(): void
    {
        $this->assertSame(5, LeadStage::query()->count());

        $this->assertSame(
            ['new', 'in_work', 'qualified', 'converted', 'rejected'],
            LeadStage::query()->ordered()->pluck('key')->all(),
        );

        $this->assertSame(['converted', 'rejected'], Lead::finalStatuses());
        $this->assertSame('new', Lead::firstStageKey());
        $this->assertSame(
            ['new' => 'Новый', 'in_work' => 'В работе', 'qualified' => 'Квалифицирован', 'converted' => 'Конверсия', 'rejected' => 'Отказ'],
            Lead::statuses(),
        );
    }

    /** @test */
    public function payment_auto_convert_guard_is_unchanged_by_data_driven_stages(): void
    {
        $paid = Lead::create(['contact' => '+70000000000', 'name' => 'Оплатил', 'status' => 'qualified']);
        $paid->markConverted();
        $this->assertSame('converted', $paid->refresh()->status);
        $this->assertNotNull($paid->converted_at);

        // Менеджер уже поставил «Отказ» вручную — повторная markConverted (идемпотентность
        // job/observer) не должна перетирать финальный статус.
        $rejected = Lead::create(['contact' => '+70000000000', 'name' => 'Отказ', 'status' => 'rejected', 'rejection_reason' => 'нет бюджета']);
        $rejected->markConverted();
        $this->assertSame('rejected', $rejected->refresh()->status);
        $this->assertNotNull($rejected->converted_at);
    }

    /** @test */
    public function kanban_drag_blocks_rollback_from_final_stage_to_first_stage(): void
    {
        $this->manager();

        $converted = Lead::create(['contact' => '+70000000000', 'name' => 'Оплатил', 'status' => 'converted']);

        Livewire::test(LeadKanbanBoard::class)
            ->call('onStatusChanged', $converted->id, 'new', [], []);

        $this->assertSame('converted', $converted->refresh()->status);
    }

    /** @test */
    public function kanban_drag_allows_normal_stage_transition(): void
    {
        $this->manager();

        $lead = Lead::create(['contact' => '+70000000000', 'name' => 'Кандидат', 'status' => 'new']);

        Livewire::test(LeadKanbanBoard::class)
            ->call('onStatusChanged', $lead->id, 'in_work', [], []);

        $this->assertSame('in_work', $lead->refresh()->status);
    }
}
