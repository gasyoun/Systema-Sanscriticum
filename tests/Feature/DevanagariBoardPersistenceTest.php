<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\DevanagariBoard;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6327 — постоянная доска прописи (Excalidraw) в кабинете.
 *
 * Сцена JSON: PUT сохраняет на «студент × занятие», GET отдаёт обратно —
 * это server-side половина persistence-контракта (браузерная половина —
 * playwright: tests/Browser/devanagari-board-persistence.spec.mjs).
 */
class DevanagariBoardPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        $this->student = User::factory()->create();
        $this->student->groups()->attach($group->id);

        $this->lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 1 — прописи',
        ]);
    }

    public function test_scene_saves_and_survives_a_fresh_session(): void
    {
        $scene = [
            'type' => 'excalidraw',
            'version' => 2,
            'source' => 'https://samskrte.ru',
            'elements' => [
                [
                    'type' => 'draw',
                    'id' => 'el-1',
                    'x' => 10, 'y' => 10, 'width' => 50, 'height' => 50,
                    'strokeColor' => '#1e1e1e',
                    'points' => [[0, 0], [25, 25], [50, 0]],
                ],
            ],
            'appState' => ['viewBackgroundColor' => '#ffffff'],
        ];

        // Сессия 1: рисуем и сохраняем.
        $save = $this->actingAs($this->student)
            ->putJson('/dvaram/propisi/scene?lesson='.$this->lesson->id, ['scene' => $scene]);

        $save->assertOk()->assertJsonPath('saved', true)->assertJsonPath('elements_count', 1);

        $this->assertDatabaseHas('devanagari_boards', [
            'student_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'elements_count' => 1,
        ]);

        // Сессия 2: «новый браузер» — без куки-сессии первой, только тот же студент.
        $fresh = User::query()->findOrFail($this->student->id);

        $load = $this->actingAs($fresh)
            ->getJson('/dvaram/propisi/scene?lesson='.$this->lesson->id);

        $load->assertOk();
        $payload = $load->json();
        $this->assertNotNull($payload['scene']);
        $this->assertSame('draw', $payload['scene']['elements'][0]['type']);
        $this->assertSame($this->lesson->id, $payload['lesson']['id']);
    }

    public function test_personal_board_without_lesson_isolated_per_student(): void
    {
        $scene = ['type' => 'excalidraw', 'version' => 2, 'elements' => [['type' => 'draw', 'id' => 'x', 'points' => []]]];

        $this->actingAs($this->student)
            ->putJson('/dvaram/propisi/scene', ['scene' => $scene])
            ->assertOk();

        $other = User::factory()->create();

        // Чужой студент не видит доску первого — у него пусто.
        $this->actingAs($other)
            ->getJson('/dvaram/propisi/scene')
            ->assertOk()
            ->assertJsonPath('scene', null);

        // Доски разные — личная доска второго пуста, первой не затёрта.
        $this->assertSame(1, DevanagariBoard::query()->where('student_id', $this->student->id)->count());
        $this->assertSame(0, DevanagariBoard::query()->where('student_id', $other->id)->count());
    }

    public function test_board_page_renders_with_lazy_chunk_and_library_url(): void
    {
        $response = $this->actingAs($this->student)->get('/dvaram/propisi');

        $response->assertOk()
            ->assertSee('devanagari-board-root', false)
            ->assertSee(asset('libraries/devanagari-stencils.excalidrawlib'), false);
    }

    public function test_no_lesson_access_for_foreign_lesson(): void
    {
        $foreignCourse = Course::factory()->create();
        $foreignLesson = Lesson::factory()->create(['course_id' => $foreignCourse->id]);

        $this->actingAs($this->student)
            ->getJson('/dvaram/propisi/scene?lesson='.$foreignLesson->id)
            ->assertStatus(403);
    }

    public function test_scene_over_cap_is_rejected_with_413(): void
    {
        $huge = str_repeat('a', DevanagariBoard::SCENE_MAX_BYTES + 1);

        $this->actingAs($this->student)
            ->putJson('/dvaram/propisi/scene', ['scene' => ['elements' => [['blob' => $huge]]]])
            ->assertStatus(413);
    }
}
