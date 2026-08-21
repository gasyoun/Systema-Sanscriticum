<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HindiProgrammePlaylist;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2441 — one ordered Hindi lesson list across owned shells.
 */
class HindiProgrammePlaylistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Course, 2: Course, 3: Course, 4: Lesson, 5: Lesson, 6: Lesson}
     */
    private function seedVicSableShape(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $sanskrit = Category::factory()->create(['name' => 'Санскрит', 'slug' => 'sanskrit']);

        $gr2 = Course::factory()->create(['title' => 'Хинди гр. 2', 'slug' => 'hindi-gr2']);
        $gr3 = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3']);
        $gr5 = Course::factory()->create([
            'title' => 'Хинди гр. 5',
            'slug' => 'hindi-gr5',
            'predecessor_course_id' => $gr3->id,
            'continues_from_lesson' => 13,
        ]);
        $sanskritCourse = Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-1']);

        $gr2->categories()->attach($hindi->id);
        $gr3->categories()->attach($hindi->id);
        $gr5->categories()->attach($hindi->id);
        $sanskritCourse->categories()->attach($sanskrit->id);

        $g2 = Group::create(['name' => 'Hindi-2']);
        $g3 = Group::create(['name' => 'Hindi-3']);
        $g5 = Group::create(['name' => 'Hindi-5']);
        $gSa = Group::create(['name' => 'Sa-1']);
        $gr2->groups()->attach($g2->id);
        $gr3->groups()->attach($g3->id);
        $gr5->groups()->attach($g5->id);
        $sanskritCourse->groups()->attach($gSa->id);

        $l2 = Lesson::factory()->for($gr2)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);
        $l3 = Lesson::factory()->for($gr3)->create([
            'title' => '5-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);
        $l5 = Lesson::factory()->for($gr5)->create([
            'title' => '13-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);
        Lesson::factory()->for($sanskritCourse)->create([
            'title' => 'Санскрит 1',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
        ]);

        $user = User::factory()->create();
        $user->groups()->attach([$g2->id, $g3->id, $g5->id, $gSa->id]);

        foreach ([$gr2, $gr3, $gr5, $sanskritCourse] as $course) {
            Payment::withoutEvents(fn () => Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 1000,
                'tariff' => 'full',
                'status' => 'paid',
            ]));
        }

        return [$user, $gr2, $gr3, $gr5, $l2, $l3, $l5];
    }

    public function test_flag_off_returns_404(): void
    {
        config(['features.hindi_programme_playlist' => false, 'features.cabinet_hybrid' => false]);
        [$user] = $this->seedVicSableShape();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertNotFound();
    }

    public function test_guest_is_redirected(): void
    {
        config(['features.hindi_programme_playlist' => true]);

        $this->get(route('student.programme.hindi'))
            ->assertRedirect();
    }

    public function test_playlist_unions_accessible_hindi_lessons_in_predecessor_order(): void
    {
        config(['features.hindi_programme_playlist' => true, 'features.cabinet_hybrid' => false]);
        [$user, $gr2, $gr3, $gr5, $l2, $l3, $l5] = $this->seedVicSableShape();

        $html = $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-programme-playlist"', false)
            ->assertSee('Мой хинди', false)
            ->assertSee($l2->title, false)
            ->assertSee($l3->title, false)
            ->assertSee($l5->title, false)
            ->assertSee('гр. 2', false)
            ->assertSee('гр. 3', false)
            ->assertSee('гр. 5', false)
            ->assertDontSee('Санскрит 1', false)
            ->getContent();

        $pos2 = strpos($html, 'data-lesson-id="'.$l2->id.'"');
        $pos3 = strpos($html, 'data-lesson-id="'.$l3->id.'"');
        $pos5 = strpos($html, 'data-lesson-id="'.$l5->id.'"');
        $this->assertNotFalse($pos2);
        $this->assertNotFalse($pos3);
        $this->assertNotFalse($pos5);
        $this->assertLessThan($pos3, $pos2);
        $this->assertLessThan($pos5, $pos3);

        $this->assertStringContainsString(
            route('student.lesson', [$gr2->slug, $l2->id], false),
            $html,
        );
        $this->assertStringContainsString(
            route('student.lesson', [$gr5->slug, $l5->id], false),
            $html,
        );
    }

    public function test_locked_lessons_are_not_listed(): void
    {
        config(['features.hindi_programme_playlist' => true, 'features.cabinet_hybrid' => false]);

        $hindi = Category::factory()->create(['slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'H3']);
        $course->groups()->attach($group->id);

        $open = Lesson::factory()->for($course)->create([
            'title' => 'Открытый блок 1',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);
        $locked = Lesson::factory()->for($course)->create([
            'title' => 'Закрытый блок 2',
            'sort_order' => 2,
            'block_number' => 2,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]));

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee($open->title, false)
            ->assertDontSee($locked->title, false);
    }

    public function test_dashboard_card_and_nav_when_flag_on(): void
    {
        config(['features.hindi_programme_playlist' => true, 'features.cabinet_hybrid' => false]);
        [$user] = $this->seedVicSableShape();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-testid="hindi-programme-playlist-card"', false)
            ->assertSee('Мой хинди', false);
    }

    public function test_dashboard_hides_card_when_flag_off(): void
    {
        config(['features.hindi_programme_playlist' => false, 'features.cabinet_hybrid' => false]);
        [$user] = $this->seedVicSableShape();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('data-testid="hindi-programme-playlist-card"', false);
    }

    public function test_probe_counts_accessible_lessons_without_sanskrit(): void
    {
        config(['features.hindi_programme_playlist' => false]);
        [$user] = $this->seedVicSableShape();

        $this->artisan('hindi:playlist-probe', ['user' => $user->id, '--json' => true])
            ->assertSuccessful();

        $report = app(HindiProgrammePlaylist::class)->probe($user);
        $this->assertSame(3, $report['accessible_total']);
        $this->assertCount(3, $report['shells']);
        $this->assertFalse($report['flag']);
        $this->assertSame(3, collect($report['shells'])->sum('accessible'));
    }

    public function test_probe_finds_shells_without_hindi_category(): void
    {
        config([
            'features.hindi_programme_playlist' => false,
            'programme.hindi.category_slug' => 'hindi',
            'programme.hindi.course_ids' => [],
        ]);

        $course = Course::factory()->create([
            'title' => 'Грамматика хинди гр. 3, пятница (2025)',
            'slug' => 'grammatika-xindi-gr-3-pt',
        ]);
        $group = Group::create(['name' => 'H3-prod-shape']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->assertFalse(Category::query()->where('slug', 'hindi')->exists());

        $report = app(HindiProgrammePlaylist::class)->probe($user);
        $this->assertSame(1, $report['accessible_total']);
        $this->assertSame([$course->id], collect($report['shells'])->pluck('id')->all());
        $this->assertTrue($lesson->isUnlockedBy(['full']));
    }

    public function test_student_without_hindi_access_sees_empty_list(): void
    {
        config(['features.hindi_programme_playlist' => true, 'features.cabinet_hybrid' => false]);
        Category::factory()->create(['slug' => 'hindi']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-playlist-empty"', false);
    }

    public function test_hindi_teacher_sees_her_published_lessons_without_payment(): void
    {
        config([
            'features.hindi_programme_playlist' => true,
            'features.hindi_tg_curated_practice' => false,
            'features.hindi_attachment_drills' => false,
            'features.hindi_my_srs_deck' => false,
            'features.cabinet_hybrid' => false,
        ]);
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $staff = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->create([
            'title' => 'Хинди гр. 1',
            'slug' => 'hindi-gr1-teacher',
            'teacher_id' => $staff->id,
        ]);
        $course->categories()->attach($hindi->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
        ]);
        $teacher = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $staff->id,
        ]);

        $this->actingAs($teacher)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-testid="hindi-programme-playlist-card"', false)
            ->assertSee('data-testid="hindi-teacher-brief"', false)
            ->assertSee(HindiProgrammePlaylist::TEACHER_BRIEF_URL, false);

        $this->actingAs($teacher)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee($lesson->title, false)
            ->assertSee('data-testid="hindi-playlist-tg-practice"', false)
            ->assertSee('data-testid="hindi-teacher-brief"', false);

        $this->actingAs($teacher)
            ->get(route('student.programme.hindi.tg'))
            ->assertOk()
            ->assertSee('data-testid="hindi-tg-curated-practice"', false);
    }

    public function test_admin_sees_hindi_teacher_preview_without_payment(): void
    {
        config([
            'features.hindi_programme_playlist' => true,
            'features.hindi_tg_curated_practice' => false,
            'features.hindi_attachment_drills' => false,
            'features.hindi_my_srs_deck' => false,
            'features.cabinet_hybrid' => false,
        ]);
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $staff = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->create([
            'title' => 'Хинди гр. 1',
            'slug' => 'hindi-gr1-admin-preview',
            'teacher_id' => $staff->id,
        ]);
        $course->categories()->attach($hindi->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
        ]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee($lesson->title, false)
            ->assertSee('data-testid="hindi-teacher-brief"', false)
            ->assertSee('data-testid="hindi-agent-drills-review-link"', false);
    }
}
