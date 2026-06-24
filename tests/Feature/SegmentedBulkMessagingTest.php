<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Jobs\SendMessengerAlerts;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SegmentedBulkMessagingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function messenger_reach_counts_linked_channels(): void
    {
        $records = new Collection([
            User::factory()->make(['telegram_id' => '1', 'vk_id' => null]),
            User::factory()->make(['telegram_id' => null, 'vk_id' => '2']),
            User::factory()->make(['telegram_id' => null, 'vk_id' => null]),
        ]);

        $reach = UserResource::messengerReach($records);

        $this->assertSame(3, $reach['total']);
        $this->assertSame(1, $reach['tg']);
        $this->assertSame(1, $reach['vk']);
        $this->assertSame(2, $reach['reachable']);
    }

    /** @test */
    public function bulk_action_dispatches_only_to_reachable_students(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_admin' => true, 'role' => Roles::ADMIN]);
        $withTg = User::factory()->create(['telegram_id' => '111']);
        $withVk = User::factory()->create(['vk_id' => '222']);
        $noMessenger = User::factory()->create(['telegram_id' => null, 'vk_id' => null]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('send_messenger', [$withTg, $withVk, $noMessenger], data: [
                'message' => 'Возвращайтесь к занятиям! 🙏',
                'to_telegram' => true,
                'to_vk' => true,
            ])
            ->assertHasNoTableBulkActionErrors();

        // Дважды: только у кого есть хоть один канал (TG и VK), без мессенджера — пропущен.
        Queue::assertPushed(SendMessengerAlerts::class, 2);
    }

    /** @test */
    public function message_is_required(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => Roles::ADMIN]);
        $student = User::factory()->create(['telegram_id' => '111']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('send_messenger', [$student], data: [
                'message' => '',
                'to_telegram' => true,
                'to_vk' => true,
            ])
            ->assertHasTableBulkActionErrors(['message' => 'required']);
    }

    /** @test */
    public function stuck_filter_segments_inactive_students(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => Roles::ADMIN]);
        $stuck = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
        ]);
        $active = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subMinutes(10),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->filterTable('stuck')
            ->assertCanSeeTableRecords([$stuck])
            ->assertCanNotSeeTableRecords([$active]);
    }
}
