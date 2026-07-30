<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\MessageTemplate;
use App\Models\MessageTemplateAudit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Аудит правок библиотеки шаблонов (H1932): каждая правка пишет append-only
 * строку «кто, когда, что поменял» (MessageTemplateAuditObserver, зеркало
 * LeadAudit). Заведён вместе с открытием редактирования куратору (manager).
 */
class MessageTemplateAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function creating_a_template_writes_a_created_audit_with_author(): void
    {
        $curator = User::factory()->create(['role' => Roles::MANAGER, 'name' => 'Куратор Ума']);
        $this->actingAs($curator);

        $template = MessageTemplate::factory()->create(['title' => 'Приветствие']);

        $audit = MessageTemplateAudit::query()->sole();
        $this->assertSame($template->id, $audit->message_template_id);
        $this->assertSame(MessageTemplateAudit::ACTION_CREATED, $audit->action);
        $this->assertSame($curator->id, $audit->admin_id);
        $this->assertSame('Куратор Ума', $audit->admin_name);
        $this->assertSame('Приветствие', $audit->changes['title']);
    }

    /** @test */
    public function updating_body_writes_old_and_new_values(): void
    {
        $template = MessageTemplate::factory()->create(['body' => 'Старый текст']);
        $curator = User::factory()->create(['role' => Roles::MANAGER, 'name' => 'Куратор Ума']);
        $this->actingAs($curator);

        $template->update(['body' => 'Новый текст']);

        $audit = MessageTemplateAudit::query()
            ->where('action', MessageTemplateAudit::ACTION_UPDATED)
            ->sole();
        $this->assertSame(['Старый текст', 'Новый текст'], $audit->changes['body']);
        $this->assertSame('Куратор Ума', $audit->admin_name);
        $this->assertStringContainsString('Старый текст → Новый текст', $audit->summary());
    }

    /** @test */
    public function touch_without_field_changes_writes_no_update_audit(): void
    {
        $template = MessageTemplate::factory()->create();

        $template->touch();

        $this->assertDatabaseMissing('message_template_audits', [
            'action' => MessageTemplateAudit::ACTION_UPDATED,
        ]);
    }

    /** @test */
    public function unauthenticated_write_is_recorded_as_system(): void
    {
        // Путь tinker/CLI/сидов: авторизованного пользователя нет.
        $template = MessageTemplate::factory()->create();
        $template->update(['is_active' => false]);

        $audit = MessageTemplateAudit::query()
            ->where('action', MessageTemplateAudit::ACTION_UPDATED)
            ->sole();
        $this->assertNull($audit->admin_id);
        $this->assertSame('Система', $audit->admin_name);
        $this->assertSame([true, false], $audit->changes['is_active']);
    }

    /** @test */
    public function deleting_writes_a_snapshot_that_survives_the_template(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'name' => 'Админ']);
        $this->actingAs($admin);
        $template = MessageTemplate::factory()->create(['title' => 'Уходящий шаблон']);

        $template->delete();

        $audit = MessageTemplateAudit::query()
            ->where('action', MessageTemplateAudit::ACTION_DELETED)
            ->sole();
        $this->assertSame('Уходящий шаблон', $audit->changes['title']);
        $this->assertSame('Админ', $audit->admin_name);
        // Строка аудита переживает удаление шаблона (без FK на message_templates).
        $this->assertDatabaseMissing('message_templates', ['id' => $audit->message_template_id]);
    }

    /** @test */
    public function suggester_binding_change_is_audited_with_labels(): void
    {
        $template = MessageTemplate::factory()->create(['suggester_category' => null]);
        $template->update(['suggester_category' => 'D']);

        $audit = MessageTemplateAudit::query()
            ->where('action', MessageTemplateAudit::ACTION_UPDATED)
            ->sole();
        $this->assertSame([null, 'D'], $audit->changes['suggester_category']);
        $this->assertStringContainsString('оплата', $audit->summary());
    }
}
