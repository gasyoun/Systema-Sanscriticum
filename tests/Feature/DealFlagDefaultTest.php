<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\DealKanbanBoard;
use App\Models\Course;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GC-C1 (H1641) — пиннинг дефолта флага `crm_pipeline_board`, по прецеденту
 * SrsFlagDefaultTest: две независимые проверки — значение конфига И
 * наблюдаемое поведение при OFF.
 *
 * Этот класс НИЧЕГО не переопределяет: ни config(), ни env — иначе он
 * проверял бы собственную подстановку, а не реальный дефолт. Спека §6
 * отмечает, что ни один тест в репозитории не пиннит дефолты features.* —
 * для флага GC-C1 эта дыра закрыта здесь.
 */
class DealFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_pipeline_board_defaults_to_false(): void
    {
        $this->assertFalse(
            (bool) config('features.crm_pipeline_board'),
            'GC-C1 обязан быть ВЫКЛ по умолчанию — это deploy-рубильник, включение отдельным шагом деплоя'
        );
    }

    public function test_deal_board_is_unreachable_by_default_even_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertFalse(DealKanbanBoard::canAccess());
        $this->assertFalse(DealKanbanBoard::shouldRegisterNavigation());
    }

    public function test_payment_bridge_writes_nothing_by_default(): void
    {
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertSame(0, Deal::query()->count(), 'при выключенном флаге мост обязан быть инертен на проде');
    }

    /** H2102: pending open path must also stay inert while flag is OFF. */
    public function test_pending_intent_writes_nothing_by_default(): void
    {
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $this->assertSame(0, Deal::query()->count(), 'pending open-on-intent must not write deals while CRM_PIPELINE_BOARD is OFF');
    }
}
