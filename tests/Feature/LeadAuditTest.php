<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Иван Тест',
            'contact' => '+70000000000',
            'status' => 'new',
        ], $overrides));
    }

    /** @test */
    public function creating_a_lead_logs_a_create_audit(): void
    {
        $lead = $this->makeLead();

        $audit = LeadAudit::where('lead_id', $lead->id)
            ->where('action', LeadAudit::ACTION_CREATED)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Система', $audit->admin_name); // нет авторизованного пользователя
        $this->assertSame('new', $audit->changes['status']);
    }

    /** @test */
    public function admin_is_recorded_when_authenticated(): void
    {
        $admin = User::factory()->create(['name' => 'Менеджер Пётр']);
        $this->actingAs($admin);

        $lead = $this->makeLead();

        $audit = LeadAudit::where('lead_id', $lead->id)->latest('id')->first();
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame('Менеджер Пётр', $audit->admin_name);
    }

    /** @test */
    public function updating_significant_field_logs_diff(): void
    {
        $lead = $this->makeLead(['status' => 'new']);
        LeadAudit::query()->delete(); // оставляем только апдейт

        $lead->update(['status' => 'in_work']);

        $audit = LeadAudit::where('action', LeadAudit::ACTION_UPDATED)->first();
        $this->assertNotNull($audit);
        $this->assertSame('new', (string) $audit->changes['status'][0]);
        $this->assertSame('in_work', (string) $audit->changes['status'][1]);
    }

    /** @test */
    public function touching_only_insignificant_fields_writes_no_update_audit(): void
    {
        $lead = $this->makeLead();
        LeadAudit::query()->delete();

        // Маркетинговое поле не входит в AUDITED.
        $lead->update(['utm_source' => 'yandex']);

        $this->assertSame(0, LeadAudit::where('action', LeadAudit::ACTION_UPDATED)->count());
    }

    /** @test */
    public function deleting_a_lead_logs_a_delete_audit_that_survives(): void
    {
        $lead = $this->makeLead();
        $id = $lead->id;

        $lead->delete();

        $audit = LeadAudit::where('lead_id', $id)
            ->where('action', LeadAudit::ACTION_DELETED)
            ->first();

        $this->assertNotNull($audit, 'Аудит удаления должен пережить сам лид.');
        $this->assertNull(Lead::find($id));
    }

    /** @test */
    public function summary_renders_changes_on_a_model_reloaded_from_db(): void
    {
        // Регресс H1932: `$this->changes` внутри модели попадал в protected-
        // свойство Eloquent (dirty-синк), пустое на перечитанной строке, —
        // таймлайн в админке показывал «—» вместо изменений.
        $lead = $this->makeLead(['status' => 'new']);
        LeadAudit::query()->delete();
        $lead->update(['status' => 'in_work']);

        $reloaded = LeadAudit::query()->sole()->fresh();

        $this->assertStringContainsString('→', $reloaded->summary());
        $this->assertStringNotContainsString('—', $reloaded->summary());
    }
}
