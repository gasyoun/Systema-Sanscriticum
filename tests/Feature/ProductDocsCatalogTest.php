<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\DocumentationCatalog;
use App\Filament\Resources\ProductDocResource;
use App\Filament\Resources\ProductDocResource\Pages\CreateProductDoc;
use App\Models\ProductDoc;
use App\Models\User;
use App\Support\ProductDocSearch;
use App\Support\Roles;
use Database\Seeders\ProductDocSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDocsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function seedCatalog(): void
    {
        $this->seed(ProductDocSeeder::class);
    }

    private function panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_and_admin_see_the_catalog_page(): void
    {
        $this->seedCatalog();
        $this->panel();

        foreach ([Roles::SUPER_ADMIN, Roles::ADMIN] as $role) {
            $this->actingAs($this->user($role));
            $this->assertTrue(DocumentationCatalog::canAccess(), $role);
            $this->get(DocumentationCatalog::getUrl())
                ->assertOk()
                ->assertSee('Документация', false)
                ->assertSee('Как пользоваться кабинетом', false)
                ->assertSee('Как работать бухгалтеру', false);
        }
    }

    public function test_admin_does_not_see_super_meta(): void
    {
        $this->seedCatalog();
        $this->panel();
        $this->actingAs($this->user(Roles::ADMIN));

        $this->get(DocumentationCatalog::getUrl())
            ->assertOk()
            ->assertDontSee('data-super-meta', false)
            ->assertDontSee('docs/STUDENT_CABINET_GUIDE_RU.md', false);
    }

    public function test_super_admin_sees_super_meta(): void
    {
        $this->seedCatalog();
        $this->panel();
        $this->actingAs($this->user(Roles::SUPER_ADMIN));

        $this->get(DocumentationCatalog::getUrl())
            ->assertOk()
            ->assertSee('data-super-meta', false)
            ->assertSee('docs/STUDENT_CABINET_GUIDE_RU.md', false);
    }

    public function test_other_roles_cannot_open_the_catalog(): void
    {
        $this->seedCatalog();
        $this->panel();

        foreach ([Roles::TEACHER, Roles::MANAGER, Roles::ACCOUNTANT, null] as $role) {
            $this->actingAs($this->user($role));
            $this->assertFalse(DocumentationCatalog::canAccess(), $role ?? 'student');
            $response = $this->get(DocumentationCatalog::getUrl());
            $this->assertNotSame(200, $response->status(), $role ?? 'student');
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->panel();
        $this->get('/admin/documentation')->assertRedirect();
    }

    public function test_resource_create_is_super_admin_only(): void
    {
        $this->panel();

        $this->actingAs($this->user(Roles::ADMIN));
        $this->assertFalse(ProductDocResource::canCreate());
        $this->assertFalse(ProductDocResource::canViewAny());
        $this->get(ProductDocResource::getUrl('create'))->assertForbidden();

        $this->actingAs($this->user(Roles::SUPER_ADMIN));
        $this->assertTrue(ProductDocResource::canCreate());
        Livewire::test(CreateProductDoc::class)->assertOk();
    }

    public function test_seeded_row_cannot_be_deleted(): void
    {
        $this->seedCatalog();
        $this->panel();
        $this->actingAs($this->user(Roles::SUPER_ADMIN));

        $seeded = ProductDoc::query()->where('slug', 'student')->firstOrFail();
        $this->assertFalse(ProductDocResource::canDelete($seeded));

        $extra = ProductDoc::factory()->create(['is_seeded' => false]);
        $this->assertTrue(ProductDocResource::canDelete($extra));
    }

    public function test_seeder_is_idempotent_and_keeps_human_title(): void
    {
        $this->seed(ProductDocSeeder::class);
        $this->assertSame(8, ProductDoc::query()->count());

        ProductDoc::query()->where('slug', 'student')->update(['title' => 'Заголовок куратора']);
        $this->seed(ProductDocSeeder::class);

        $this->assertSame(8, ProductDoc::query()->count());
        $this->assertSame(
            'Заголовок куратора',
            ProductDoc::query()->where('slug', 'student')->value('title'),
        );
        $this->assertSame(8, ProductDoc::query()->where('is_seeded', true)->count());
    }

    public function test_search_finds_homework_book(): void
    {
        $this->seedCatalog();
        $hits = ProductDocSearch::search(null, 'домашнее');

        $this->assertTrue(
            $hits->contains(fn (array $hit): bool => $hit['doc']->slug === 'homework'
                || mb_stripos($hit['heading'], 'домашн') !== false),
        );
    }

    public function test_source_path_jail_rejects_env(): void
    {
        $this->expectException(ValidationException::class);

        ProductDoc::factory()->create([
            'source_path' => '../.env',
        ]);
    }

    public function test_source_path_jail_rejects_absolute_escape(): void
    {
        $this->assertNull(ProductDoc::assertSafeSourcePath('../.env'));
        $this->assertNull(ProductDoc::assertSafeSourcePath('app/Models/User.php'));
        $this->assertNotNull(ProductDoc::assertSafeSourcePath('docs/STUDENT_CABINET_GUIDE_RU.md'));
    }
}
