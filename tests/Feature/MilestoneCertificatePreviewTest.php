<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\RelationManagers\CertificateMilestonesRelationManager;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Живой предпросмотр документа вехи: отдельный роут рендерит настоящий
 * certificates.default (тот же блейд, что dompdf), форма вехи показывает его
 * в iframe. Изоляция iframe — защита от CSS админки, поэтому превью и PDF
 * совпадают по вёрстке.
 */
class MilestoneCertificatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::factory()->create([
            'title' => 'Летний интенсив',
            'lessons_count' => 16,
            'hours_count' => 32,
        ]);
        $this->admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    private function preview(array $params = [])
    {
        return $this->actingAs($this->admin)->get(route('certificates.milestone-preview', $params));
    }

    /** @test */
    public function guests_are_sent_to_login(): void
    {
        $this->get(route('certificates.milestone-preview', ['course_id' => $this->course->id]))
            ->assertRedirect();
    }

    /** @test */
    public function users_without_admin_or_teacher_role_are_forbidden(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('certificates.milestone-preview', ['course_id' => $this->course->id]))
            ->assertForbidden();
    }

    /** @test */
    public function renders_real_template_with_manual_title_and_course_volume(): void
    {
        $this->preview([
            'course_id' => $this->course->id,
            'title' => 'Летний интенсив|"Продленка санскрита"',
        ])
            ->assertOk()
            ->assertSee('Фамилия Имя')
            ->assertSee('Летний интенсив<br>', false)
            ->assertSee('&quot;Продленка санскрита&quot;', false)
            ->assertSee('успешно освоил(а) онлайн-курс', false)
            ->assertSee('в объеме 16 уроков (32 академических часов)', false)
            ->assertSee('ganesha_gasuns.jpg', false)
            ->assertSee('ГГГГ-XXXXX', false)
            // Без явного холста браузер схлопывает .cert-container (height:100%) в 0 px.
            ->assertSee('html, body { width: 1122px; height: 793px;', false);
    }

    /** @test */
    public function falls_back_to_course_title_when_title_is_empty(): void
    {
        $this->preview(['course_id' => $this->course->id])
            ->assertOk()
            ->assertSee('Летний интенсив');
    }

    /** @test */
    public function spravka_switches_action_text_and_hides_course_volume(): void
    {
        $this->preview(['course_id' => $this->course->id, 'document_type' => 'spravka'])
            ->assertOk()
            ->assertSee('проходит обучение и освоил(а) часть онлайн-курса', false)
            ->assertDontSee('в объеме 16 уроков', false);
    }

    /** @test */
    public function sanka_template_without_scores_hides_exam_block(): void
    {
        $this->preview(['course_id' => $this->course->id, 'template' => 'sanka'])
            ->assertOk()
            ->assertSee('ganesha_sanka.jpg', false)
            ->assertDontSee('Итого:', false);
    }

    /** @test */
    public function milestone_create_modal_renders_preview_iframe(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->admin)
            ->test(CertificateMilestonesRelationManager::class, [
                'ownerRecord' => $this->course,
                'pageClass' => EditCourse::class,
            ])
            ->assertOk()
            ->mountTableAction('create')
            ->assertSee('Как будет выглядеть документ')
            ->assertSee('certificates/milestone-preview', false);
    }

    /** @test */
    public function creating_milestone_through_action_still_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->admin)
            ->test(CertificateMilestonesRelationManager::class, [
                'ownerRecord' => $this->course,
                'pageClass' => EditCourse::class,
            ])
            ->assertOk()
            ->callTableAction('create', data: [
                'title' => 'Окончание курса',
                'certificate_title' => 'Летний интенсив|"Продленка санскрита"',
                'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
                'document_type' => CertificateMilestone::DOC_CERTIFICATE,
                'trigger_lesson' => 16,
                'template' => 'gasuns',
                'auto_issue_enabled' => true,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas(CertificateMilestone::class, [
            'course_id' => $this->course->id,
            'title' => 'Окончание курса',
            'trigger_lesson' => 16,
        ]);
    }
}
