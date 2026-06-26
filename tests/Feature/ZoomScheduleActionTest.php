<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1 (#78): экшен «Создать Zoom-встречу» в ScheduleResource сохраняет
 * zoom_* поля и дублирует join_url в `link` (кнопка «Подключиться» в кабинете).
 */
class ZoomScheduleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.zoom.account_id' => 'acc',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    /** @test */
    public function action_creates_meeting_and_stores_join_url_in_link(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 555000111,
                'join_url' => 'https://zoom.us/j/555000111',
                'start_url' => 'https://zoom.us/s/555000111?zak=k',
            ]),
        ]);

        $schedule = Schedule::create([
            'title' => 'Лекция 1',
            'start' => now()->addDays(2),
            'end' => now()->addDays(2)->addHours(1),
        ]);

        Livewire::test(ListSchedules::class)
            ->callTableAction('create_zoom', $schedule);

        $schedule->refresh();
        $this->assertSame('555000111', $schedule->zoom_meeting_id);
        $this->assertSame('https://zoom.us/j/555000111', $schedule->zoom_join_url);
        $this->assertSame('https://zoom.us/j/555000111', $schedule->link); // кабинет покажет «Подключиться»
        $this->assertStringContainsString('zak=', $schedule->zoom_start_url);
    }

    /** @test */
    public function action_hidden_when_meeting_already_exists(): void
    {
        $schedule = Schedule::create([
            'title' => 'Уже с Zoom',
            'start' => now()->addDay(),
            'zoom_meeting_id' => '999',
        ]);

        Livewire::test(ListSchedules::class)
            ->assertTableActionHidden('create_zoom', $schedule);
    }
}
