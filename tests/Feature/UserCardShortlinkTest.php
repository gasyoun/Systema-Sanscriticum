<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserCardShortlinkTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function view_card_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => Roles::SUPER_ADMIN, 'is_admin' => true]);
        $student = User::factory()->create([
            'name' => 'Иван Тестов',
            'phone' => '+79991234567',
            'note' => 'Связь: @durov и https://vk.com/id1',
            'global_status' => 'VIP',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ViewUser::class, ['record' => $student->getRouteKey()])
            ->assertOk()
            ->assertSee('Иван Тестов')
            ->assertSee(url('/u/'.$student->id));
    }

    /** @test */
    public function shortlink_redirects_to_view_card(): void
    {
        $admin = User::factory()->create(['role' => Roles::SUPER_ADMIN, 'is_admin' => true]);
        $student = User::factory()->create();

        $this->actingAs($admin)
            ->get('/u/'.$student->id)
            ->assertRedirect('/admin/users/'.$student->id);
    }

    /** @test */
    public function shortlink_requires_admin_auth(): void
    {
        $student = User::factory()->create();

        // Гость уходит на логин админки, а не видит карточку.
        $this->get('/u/'.$student->id)
            ->assertRedirect('/admin/users/'.$student->id);

        // Целевая view-страница недоступна гостю.
        $this->get('/admin/users/'.$student->id)->assertRedirect();
    }
}
