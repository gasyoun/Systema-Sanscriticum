<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DictionaryWordResource;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2050: DictionaryWord Filament CRUD open to teachers (Elena maintainer).
 */
class DictionaryWordResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_maintain_dictionary_words(): void
    {
        $this->assertFalse(DictionaryWordResource::canViewAny());
        $this->assertFalse(DictionaryWordResource::canCreate());
    }

    public function test_teacher_can_maintain_dictionary_words(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));

        $this->assertTrue(DictionaryWordResource::canMaintain());
        $this->assertTrue(DictionaryWordResource::canViewAny());
        $this->assertTrue(DictionaryWordResource::canCreate());
        $this->assertTrue(DictionaryWordResource::canEdit(null));
        $this->assertTrue(DictionaryWordResource::canDelete(null));
    }

    public function test_admin_can_maintain_dictionary_words(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertTrue(DictionaryWordResource::canMaintain());
    }

    public function test_manager_cannot_maintain_dictionary_words(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));

        $this->assertFalse(DictionaryWordResource::canViewAny());
        $this->assertFalse(DictionaryWordResource::canCreate());
    }
}
