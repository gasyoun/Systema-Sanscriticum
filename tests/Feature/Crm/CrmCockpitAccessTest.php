<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\WorkQueue;
use App\Filament\Resources\MessageTemplateResource;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Гейтинг кокпита (H221): всё живёт за флагом crm_cockpit. Флаг OFF → интерфейс
 * не меняется (страница и ресурс недоступны). Флаг ON → «Моя работа сегодня»
 * доступна admin/manager, шаблоны — только admin (AdminOnly).
 */
class CrmCockpitAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @test */
    public function work_queue_hidden_when_flag_off_even_for_manager(): void
    {
        config(['features.crm_cockpit' => false]);
        $this->actingAs($this->user(Roles::MANAGER));

        $this->assertFalse(WorkQueue::canAccess());
        $this->assertFalse(MessageTemplateResource::canViewAny());
    }

    /** @test */
    public function work_queue_visible_to_manager_and_admin_when_flag_on(): void
    {
        config(['features.crm_cockpit' => true]);

        $this->actingAs($this->user(Roles::MANAGER));
        $this->assertTrue(WorkQueue::canAccess());

        $this->actingAs($this->user(Roles::ADMIN));
        $this->assertTrue(WorkQueue::canAccess());
    }

    /** @test */
    public function work_queue_denied_to_teacher_and_student_when_flag_on(): void
    {
        config(['features.crm_cockpit' => true]);

        $this->actingAs($this->user(Roles::TEACHER));
        $this->assertFalse(WorkQueue::canAccess());

        $this->actingAs($this->user(null));
        $this->assertFalse(WorkQueue::canAccess());
    }

    /** @test */
    public function templates_admin_only_even_with_flag_on(): void
    {
        config(['features.crm_cockpit' => true]);

        $this->actingAs($this->user(Roles::ADMIN));
        $this->assertTrue(MessageTemplateResource::canViewAny());

        $this->actingAs($this->user(Roles::MANAGER));
        $this->assertFalse(MessageTemplateResource::canViewAny(), 'менеджер не редактирует библиотеку');
    }
}
