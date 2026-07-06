<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\FinanceCockpit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceCockpitPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Renders the page, which compiles finance-cockpit.blade.php — this would have
     * caught the `use`-inside-@php ParseError that 500'd the page on prod. Also
     * exercises the report methods against an empty DB (must not error on zero data).
     */
    public function test_finance_cockpit_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Livewire::actingAs($admin)
            ->test(FinanceCockpit::class)
            ->assertSuccessful();
    }
}
