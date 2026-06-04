<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSplitGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lesson_sync.secret' => self::SECRET]);
    }

    private function headers(): array
    {
        return ['X-Secret-Key' => self::SECRET];
    }

    /** @test */
    public function lesson_scope_and_visibility_respect_groups(): void
    {
        $course = Course::factory()->create();
        $groupA = Group::create(['name' => 'Поток A']);
        $groupB = Group::create(['name' => 'Поток B']);
        $course->groups()->attach([$groupA->id, $groupB->id]);

        $userA = User::factory()->create();
        $userA->groups()->attach($groupA->id);

        $lessonNull = Lesson::factory()->for($course)->create(['group_id' => null]);
        $lessonA = Lesson::factory()->for($course)->create(['group_id' => $groupA->id]);
        $lessonB = Lesson::factory()->for($course)->create(['group_id' => $groupB->id]);

        $visibleIds = $course->lessons()->forUserGroups($userA)->pluck('id')->all();

        $this->assertContains($lessonNull->id, $visibleIds, 'Уроки без группы видны всем.');
        $this->assertContains($lessonA->id, $visibleIds, 'Свою группу видит.');
        $this->assertNotContains($lessonB->id, $visibleIds, 'Чужую группу не видит.');

        $this->assertTrue($lessonNull->isVisibleToGroupsOf($userA));
        $this->assertTrue($lessonA->isVisibleToGroupsOf($userA));
        $this->assertFalse($lessonB->isVisibleToGroupsOf($userA));
    }

    /** @test */
    public function sync_keys_lessons_by_group_same_date_no_overwrite(): void
    {
        $course = Course::factory()->create();
        $groupA = Group::create(['name' => 'A']);
        $groupB = Group::create(['name' => 'B']);

        $payload = [
            ['id' => $course->id, 'title' => 'Урок A', 'group_id' => $groupA->id, 'videoLinks' => ['2026-06-01' => 'https://a.example/v']],
            ['id' => $course->id, 'title' => 'Урок B', 'group_id' => $groupB->id, 'videoLinks' => ['2026-06-01' => 'https://b.example/v']],
        ];

        $this->postJson('/api/sync-lessons', $payload, $this->headers())->assertOk();

        $lessons = Lesson::where('course_id', $course->id)->whereDate('lesson_date', '2026-06-01')->get();
        $this->assertCount(2, $lessons, 'Две группы на одну дату → два урока, не затёрли друг друга.');
        $this->assertSame('https://a.example/v', $lessons->firstWhere('group_id', $groupA->id)->video_url);
        $this->assertSame('https://b.example/v', $lessons->firstWhere('group_id', $groupB->id)->video_url);
    }

    /** @test */
    public function sync_without_group_keeps_group_id_null(): void
    {
        $course = Course::factory()->create();

        $payload = [
            ['id' => $course->id, 'title' => 'Общий урок', 'videoLinks' => ['2026-06-02' => 'https://x.example/v']],
        ];

        $this->postJson('/api/sync-lessons', $payload, $this->headers())->assertOk();

        $lesson = Lesson::where('course_id', $course->id)->whereDate('lesson_date', '2026-06-02')->sole();
        $this->assertNull($lesson->group_id, 'Без группы в payload → group_id NULL (обратная совместимость).');
    }

    /** @test */
    public function store_from_zoom_keys_by_group(): void
    {
        $course = Course::factory()->create();
        $groupA = Group::create(['name' => 'A']);
        $groupB = Group::create(['name' => 'B']);

        $base = ['course_id' => $course->id, 'title' => 'Zoom-урок', 'lesson_date' => '2026-06-03'];

        $this->postJson('/api/lessons/from-zoom', $base + ['group_id' => $groupA->id], $this->headers())->assertOk();
        $this->postJson('/api/lessons/from-zoom', $base + ['group_id' => $groupB->id], $this->headers())->assertOk();
        // Повторный прогон той же группы — идемпотентно, не плодит дубль.
        $this->postJson('/api/lessons/from-zoom', $base + ['group_id' => $groupA->id], $this->headers())->assertOk();

        $lessons = Lesson::where('course_id', $course->id)->whereDate('lesson_date', '2026-06-03')->get();
        $this->assertCount(2, $lessons, 'Две группы → два урока; повтор группы A не дублирует.');
        $this->assertEqualsCanonicalizing(
            [$groupA->id, $groupB->id],
            $lessons->pluck('group_id')->all()
        );
    }
}
