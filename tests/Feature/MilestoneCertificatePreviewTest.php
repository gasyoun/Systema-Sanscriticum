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

class MilestoneCertificatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::factory()->create([
            'title' => 'Летний интенсив',
            'lessons_count' => 16,
            'hours_count' => 32,
        ]);
    }

    private function render(?string $title, string $template = 'gasuns', string $documentType = 'certificate'): string
    {
        return view('filament.certificates.milestone-preview', [
            'templateKey' => $template,
            'documentType' => $documentType,
            'certificateTitle' => $title,
            'course' => $this->course,
        ])->render();
    }

    /** @test */
    public function shows_manual_title_with_line_breaks_and_course_volume(): void
    {
        $html = $this->render('Летний интенсив|"Продленка санскрита"');

        $this->assertStringContainsString('Летний интенсив<br>', $html);
        $this->assertStringContainsString('&quot;Продленка санскрита&quot;', $html);
        $this->assertStringContainsString('успешно освоил(а) онлайн-курс', $html);
        $this->assertStringContainsString('в объеме 16 уроков (32 академических часов)', $html);
        $this->assertStringContainsString('ganesha_gasuns.jpg', $html);
    }

    /** @test */
    public function falls_back_to_course_title_when_manual_title_is_empty(): void
    {
        $this->assertStringContainsString('Летний интенсив', $this->render(null));
        $this->assertStringContainsString('Летний интенсив', $this->render('   '));
    }

    /** @test */
    public function spravka_switches_action_text_and_hides_course_volume(): void
    {
        $html = $this->render(null, documentType: 'spravka');

        $this->assertStringContainsString('проходит обучение и освоил(а) часть онлайн-курса', $html);
        $this->assertStringNotContainsString('в объеме 16 уроков', $html);
    }

    /** @test */
    public function sanka_template_shows_exam_block_with_pending_scores(): void
    {
        $html = $this->render(null, template: 'sanka');

        $this->assertStringContainsString('Выразительность', $html);
        $this->assertStringContainsString('Итого: <b>— / 30</b>', $html);
        $this->assertStringContainsString('exam_scores', $html);
    }

    /** @test */
    public function certificate_document_hides_exam_block_for_non_sanka_templates(): void
    {
        $this->assertStringNotContainsString('Итого', $this->render(null, template: 'gasuns'));
    }

    /** @test */
    public function milestone_create_modal_renders_preview(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CertificateMilestonesRelationManager::class, [
                'ownerRecord' => $this->course,
                'pageClass' => EditCourse::class,
            ])
            ->assertOk()
            ->mountTableAction('create')
            ->assertSee('Как будет выглядеть документ')
            ->assertSeeHtml('ganesha_gasuns.jpg');
    }

    /** @test */
    public function creating_milestone_through_action_still_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
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
