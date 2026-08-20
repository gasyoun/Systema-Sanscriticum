<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\HindiAgentDrillsReview;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H3206 — teacher cabinet list of agent Hindi drills.
 */
class HindiAgentDrillsReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_teacher_sees_fixture_cloze_on_the_review_page(): void
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $staff = Teacher::create(['name' => 'Kostina']);
        $course = Course::factory()->create([
            'title' => 'Хинди гр. 1',
            'slug' => 'hindi-gr1-agent-review',
            'teacher_id' => $staff->id,
        ]);
        $course->categories()->attach($hindi->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Начальная №1',
            'sort_order' => 1,
            'is_published' => true,
            'transcript_file' => 'transcripts/lesson-agent-review.json',
        ]);
        Storage::disk('public')->put(
            $lesson->transcript_file,
            (string) file_get_contents(base_path('tests/fixtures/hindi_transcript/hindi_lesson_sample.json')),
        );

        $teacher = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $staff->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacher);

        $this->assertTrue(HindiAgentDrillsReview::canAccess());
        $this->get(HindiAgentDrillsReview::getUrl())
            ->assertOk()
            ->assertSee('data-testid="hindi-agent-drills-review"', false)
            ->assertSee('data-testid="hindi-agent-drills-item"', false)
            ->assertSee('Как по-русски: kitāb', false);
    }

    public function test_manager_is_forbidden(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($manager);

        $this->assertFalse(HindiAgentDrillsReview::canAccess());
        $this->get(HindiAgentDrillsReview::getUrl())->assertForbidden();
    }
}
