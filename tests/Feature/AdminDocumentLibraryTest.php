<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AdminDocumentResource;
use App\Filament\Resources\AdminDocumentResource\Pages\ListAdminDocuments;
use App\Models\AdminDocument;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\AdminDocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Библиотека важных файлов (H2570): доступы, вывод типа из ссылки и сужение
 * строк «только для суперадмина».
 *
 * Гейт проверяется на всех ролях, а не только на «своей»: ресурс живёт в общей
 * панели admin, и менеджер/бухгалтер попадают в неё по legacy-флагу is_admin —
 * забыть про них здесь значит открыть внутренние ссылки половине команды.
 */
class AdminDocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @test */
    public function super_admin_and_admin_see_the_resource(): void
    {
        foreach ([Roles::SUPER_ADMIN, Roles::ADMIN] as $role) {
            $this->actingAs($this->user($role));
            $this->assertTrue(
                AdminDocumentResource::canViewAny(),
                $role.' должен видеть библиотеку важных файлов',
            );
        }
    }

    /** @test */
    public function manager_accountant_teacher_and_student_do_not_see_the_resource(): void
    {
        foreach ([Roles::MANAGER, Roles::ACCOUNTANT, Roles::TEACHER, null] as $role) {
            $this->actingAs($this->user($role));
            $this->assertFalse(
                AdminDocumentResource::canViewAny(),
                ($role ?? 'студент').' не должен видеть библиотеку важных файлов',
            );
            $this->assertFalse(AdminDocumentResource::canCreate());
        }
    }

    /**
     * Галочка «только суперадмин» обязана убирать строку из выборки, а не
     * только прятать колонку: иначе обычный админ дошёл бы до неё по прямому
     * /admin/admin-documents/{id}/edit.
     *
     * @test
     */
    public function a_super_admin_only_row_is_hidden_from_a_plain_admin(): void
    {
        $shared = AdminDocument::create([
            'title' => 'Общая таблица',
            'source_url' => 'https://docs.google.com/spreadsheets/d/shared/edit',
        ]);
        $secret = AdminDocument::create([
            'title' => 'Только для владельца',
            'source_url' => 'https://docs.google.com/spreadsheets/d/secret/edit',
            'super_admin_only' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->user(Roles::ADMIN));
        Livewire::test(ListAdminDocuments::class)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$secret]);

        $this->actingAs($this->user(Roles::SUPER_ADMIN));
        Livewire::test(ListAdminDocuments::class)
            ->assertCanSeeTableRecords([$shared, $secret]);
    }

    /** @test */
    public function a_non_admin_role_gets_an_empty_query_even_if_the_gate_were_bypassed(): void
    {
        AdminDocument::create([
            'title' => 'Общая таблица',
            'source_url' => 'https://docs.google.com/spreadsheets/d/shared/edit',
        ]);

        $this->assertSame(
            0,
            AdminDocument::query()->visibleTo($this->user(Roles::MANAGER))->count(),
            'менеджер не должен получать ни одной строки',
        );
        $this->assertSame(
            0,
            AdminDocument::query()->visibleTo(null)->count(),
            'гость не должен получать ни одной строки',
        );
    }

    /**
     * @test
     *
     * @dataProvider urlKindProvider
     */
    public function the_kind_is_derived_from_the_url(string $url, string $expected): void
    {
        $document = AdminDocument::create([
            'title' => 'Проверка типа',
            'source_url' => $url,
        ]);

        $this->assertSame($expected, $document->kind);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlKindProvider(): array
    {
        return [
            'google sheets' => ['https://docs.google.com/spreadsheets/d/abc/edit?gid=1#gid=1', 'sheet'],
            'google docs' => ['https://docs.google.com/document/d/abc/edit', 'doc'],
            'google forms' => ['https://docs.google.com/forms/d/abc/edit', 'form'],
            'google slides' => ['https://docs.google.com/presentation/d/abc/edit', 'presentation'],
            'google drive folder' => ['https://drive.google.com/drive/folders/abc', 'drive'],
            'pdf' => ['https://example.test/regulation.pdf', 'pdf'],
            'plain page' => ['https://example.test/wiki/page', 'page'],
            // MaterialUrl вывел бы 'audio' — для внутреннего документа такого
            // типа нет, и модель обязана свести его к нейтральной ссылке.
            'audio falls back to link' => ['https://example.test/call.mp3', 'link'],
        ];
    }

    /** @test */
    public function the_host_is_derived_and_the_url_keeps_its_gid_fragment(): void
    {
        $url = 'https://docs.google.com/spreadsheets/d/abc/edit?gid=435092428#gid=435092428';

        $document = AdminDocument::create(['title' => 'Таблица с вкладкой', 'source_url' => $url]);

        $this->assertSame('docs.google.com', $document->host);
        $this->assertSame($url, $document->source_url, 'ссылку храним дословно, вкладка не должна потеряться');
    }

    /**
     * Ручная правка типа не должна откатываться при сохранении других полей —
     * иначе «это не таблица, а форма» жило бы до первого редактирования.
     *
     * @test
     */
    public function a_manual_kind_survives_an_unrelated_save(): void
    {
        $document = AdminDocument::create([
            'title' => 'Таблица',
            'source_url' => 'https://docs.google.com/spreadsheets/d/abc/edit',
            'kind' => 'form',
        ]);

        $document->update(['description' => 'уточнили назначение']);

        $this->assertSame('form', $document->fresh()->kind);
    }

    /** @test */
    public function changing_the_url_recomputes_the_derived_fields(): void
    {
        $document = AdminDocument::create([
            'title' => 'Переехал',
            'source_url' => 'https://docs.google.com/spreadsheets/d/abc/edit',
        ]);

        $document->update(['source_url' => 'https://example.test/regulation.pdf']);
        $document->refresh();

        $this->assertSame('example.test', $document->host);
        $this->assertSame('pdf', $document->kind);
    }

    /** @test */
    public function an_admin_can_add_a_document_and_authorship_is_recorded(): void
    {
        $admin = $this->user(Roles::ADMIN);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(AdminDocumentResource\Pages\CreateAdminDocument::class)
            ->fillForm([
                'title' => 'Реестр договоров',
                'source_url' => 'https://docs.google.com/spreadsheets/d/new/edit',
                'category' => 'legal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = AdminDocument::where('title', 'Реестр договоров')->firstOrFail();

        $this->assertSame($admin->id, $document->added_by_user_id);
        $this->assertSame('legal', $document->category);
        $this->assertSame('sheet', $document->kind);
        $this->assertTrue($document->is_active);
    }

    /**
     * Обычный админ не должен уметь пометить строку как «только суперадмин»:
     * поле выключено в форме и не долетает до модели (dehydrated).
     *
     * @test
     */
    public function a_plain_admin_cannot_mark_a_row_super_admin_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(Roles::ADMIN));

        Livewire::test(AdminDocumentResource\Pages\CreateAdminDocument::class)
            ->fillForm([
                'title' => 'Попытка сузить',
                'source_url' => 'https://docs.google.com/spreadsheets/d/attempt/edit',
                'super_admin_only' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(
            AdminDocument::where('title', 'Попытка сузить')->firstOrFail()->super_admin_only,
            'обычный админ не вправе выставлять флаг «только суперадмин»',
        );
    }

    /** @test */
    public function the_seeder_is_idempotent_and_registers_the_management_sheet(): void
    {
        $this->seed(AdminDocumentSeeder::class);
        $this->seed(AdminDocumentSeeder::class);

        $documents = AdminDocument::where('source_url', 'like', '%1sHTKEEHa0uIzbna%')->get();

        $this->assertCount(1, $documents, 'повторный прогон сидера не должен дублировать строку');
        $this->assertSame('sheet', $documents->first()->kind);
        $this->assertSame('docs.google.com', $documents->first()->host);
        $this->assertSame('ops', $documents->first()->category);
    }

    /** @test */
    public function a_seeded_title_is_not_overwritten_on_a_second_run(): void
    {
        $this->seed(AdminDocumentSeeder::class);

        $document = AdminDocument::where('source_url', 'like', '%1sHTKEEHa0uIzbna%')->firstOrFail();
        $document->update(['title' => 'Название, данное владельцем']);

        $this->seed(AdminDocumentSeeder::class);

        $this->assertSame('Название, данное владельцем', $document->fresh()->title);
    }
}
