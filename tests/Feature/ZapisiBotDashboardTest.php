<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ZapisiBot\ZapisiBotDashboard;
use App\Models\Group;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Мультичат-дашборд @zapisi_ORSbot: реестр чатов = учебные группы с
 * telegram_chat_id; выбор группы слева переключает состав/сообщения справа.
 */
class ZapisiBotDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
    }

    public function test_lists_only_groups_with_chat_id_and_switches_selection(): void
    {
        $this->admin();

        $a = Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $b = Group::create(['name' => 'Группа B', 'telegram_chat_id' => '-100222']);
        Group::create(['name' => 'Группа без чата']); // нет telegram_chat_id → не в списке

        Livewire::test(ZapisiBotDashboard::class)
            ->assertSet('selectedGroupId', $a->id) // mount берёт первую по имени
            ->assertSee('Группа A')
            ->assertSee('Группа B')
            ->assertDontSee('Группа без чата')
            ->call('selectGroup', $b->id)
            ->assertSet('selectedGroupId', $b->id);
    }

    public function test_deep_link_group_query_param_preselects(): void
    {
        $this->admin();

        Group::create(['name' => 'Первая', 'telegram_chat_id' => '-100111']);
        $target = Group::create(['name' => 'Вторая', 'telegram_chat_id' => '-100222']);

        Livewire::withQueryParams(['group' => $target->id])
            ->test(ZapisiBotDashboard::class)
            ->assertSet('selectedGroupId', $target->id);
    }

    public function test_empty_state_when_no_group_has_chat_id(): void
    {
        $this->admin();
        Group::create(['name' => 'Без чата']);

        Livewire::test(ZapisiBotDashboard::class)
            ->assertSet('selectedGroupId', null)
            ->assertSee('telegram_chat_id');
    }
}
