<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherLoad as TeacherLoadPage;
use App\Filament\Widgets\TeacherLoadByDirectionChart;
use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class TeacherLoadReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two teachers, two directions, three groups spread across them:
     * Teacher A leads a grammar group + a vedic group (2 total, both directions);
     * Teacher B leads one grammar group (1 total, grammar only).
     *
     * @return array{teacherA: Teacher, teacherB: Teacher, grammar: Category, vedic: Category}
     */
    private function seedScenario(): array
    {
        $grammar = Category::factory()->create(['name' => 'Грамматика', 'sort_order' => 1]);
        $vedic = Category::factory()->create(['name' => 'Ведийская литература', 'sort_order' => 2]);

        $teacherA = Teacher::create(['name' => 'Teacher A', 'email' => 'a@example.test']);
        $teacherB = Teacher::create(['name' => 'Teacher B', 'email' => 'b@example.test']);

        $courseGrammarA = Course::factory()->create(['teacher_id' => $teacherA->id]);
        $courseGrammarA->categories()->attach($grammar);

        $courseVedicA = Course::factory()->create(['teacher_id' => $teacherA->id]);
        $courseVedicA->categories()->attach($vedic);

        $courseGrammarB = Course::factory()->create(['teacher_id' => $teacherB->id]);
        $courseGrammarB->categories()->attach($grammar);

        $groupGrammarA = Group::factory()->create();
        $groupGrammarA->courses()->attach($courseGrammarA);

        $groupVedicA = Group::factory()->create();
        $groupVedicA->courses()->attach($courseVedicA);

        $groupGrammarB = Group::factory()->create();
        $groupGrammarB->courses()->attach($courseGrammarB);

        return compact('teacherA', 'teacherB', 'grammar', 'vedic');
    }

    /** @test */
    public function teacher_group_counts_and_directions_match_seeded_data(): void
    {
        $scenario = $this->seedScenario();

        $this->assertSame(2, $scenario['teacherA']->groupsLed()->count());
        $this->assertSame(1, $scenario['teacherB']->groupsLed()->count());

        $directionsA = $scenario['teacherA']->groupsLed()
            ->flatMap(fn (Group $group) => $group->directions())
            ->unique('id')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['Ведийская литература', 'Грамматика'], $directionsA);

        $directionsB = $scenario['teacherB']->groupsLed()
            ->flatMap(fn (Group $group) => $group->directions())
            ->unique('id')
            ->pluck('name')
            ->all();
        $this->assertSame(['Грамматика'], $directionsB);
    }

    /** @test */
    public function chart_dataset_matches_seeded_counts_per_direction(): void
    {
        $this->seedScenario();

        $widget = new TeacherLoadByDirectionChart;
        $method = new ReflectionMethod($widget, 'getData');
        $method->setAccessible(true);

        /** @var array{datasets: array<int, array{label: string, data: array<int, int>}>, labels: array<int, string>} $data */
        $data = $method->invoke($widget);

        $this->assertSame(['Teacher A', 'Teacher B'], $data['labels']);

        $byLabel = collect($data['datasets'])->keyBy('label');
        $this->assertSame([1, 1], $byLabel['Грамматика']['data']);
        $this->assertSame([1, 0], $byLabel['Ведийская литература']['data']);
    }

    /** @test */
    public function page_renders_with_table_and_chart_for_admin(): void
    {
        $this->seedScenario();
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertTrue(TeacherLoadPage::canAccess());

        Livewire::test(TeacherLoadPage::class)
            ->assertOk()
            ->assertSee('Teacher A')
            ->assertSee('Teacher B')
            ->assertSee('Грамматика');
    }

    /** @test */
    public function teacher_role_sees_only_their_own_row(): void
    {
        $scenario = $this->seedScenario();
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $scenario['teacherA']->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        Livewire::test(TeacherLoadPage::class)
            ->assertOk()
            ->assertSee('Teacher A')
            ->assertDontSee('Teacher B');
    }
}
