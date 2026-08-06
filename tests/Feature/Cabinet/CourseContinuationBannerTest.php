<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseContinuationBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2333 — continuation shells expose a self-service path to lessons 1…N−1.
 */
class CourseContinuationBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_null_without_predecessor(): void
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();

        $this->assertNull(app(CourseContinuationBanner::class)->for($course, $user));
    }

    public function test_banner_payload_when_enrolled_in_predecessor(): void
    {
        $begin = Course::factory()->create(['title' => 'Хинди начало', 'slug' => 'hindi-begin']);
        $cont = Course::factory()->create([
            'title' => 'Хинди продолжение',
            'slug' => 'hindi-cont',
            'predecessor_course_id' => $begin->id,
            'continues_from_lesson' => 13,
        ]);

        $groupBegin = Group::create(['name' => 'Begin']);
        $groupCont = Group::create(['name' => 'Cont']);
        $begin->groups()->attach($groupBegin->id);
        $cont->groups()->attach($groupCont->id);

        $first = Lesson::factory()->for($begin)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        Lesson::factory()->for($cont)->create([
            'title' => '13-е занятие',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $user = User::factory()->create();
        $user->groups()->attach([$groupBegin->id, $groupCont->id]);

        $banner = app(CourseContinuationBanner::class)->for($cont, $user);

        $this->assertNotNull($banner);
        $this->assertSame(13, $banner['continues_from']);
        $this->assertTrue($banner['student_enrolled']);
        $this->assertSame($begin->title, $banner['predecessor_title']);
        $this->assertStringContainsString($begin->slug, $banner['course_url']);
        $this->assertNotNull($banner['first_lesson_url']);
        $this->assertStringContainsString((string) $first->id, $banner['first_lesson_url']);
    }

    public function test_course_page_renders_banner_for_enrolled_student(): void
    {
        config(['features.cabinet_hybrid' => false]);

        $begin = Course::factory()->create(['title' => 'Архив с 1-го', 'slug' => 'arch-1']);
        $cont = Course::factory()->create([
            'title' => 'Поток с 13-го',
            'slug' => 'stream-13',
            'predecessor_course_id' => $begin->id,
            'continues_from_lesson' => 13,
        ]);

        $groupBegin = Group::create(['name' => 'G-begin']);
        $groupCont = Group::create(['name' => 'G-cont']);
        $begin->groups()->attach($groupBegin->id);
        $cont->groups()->attach($groupCont->id);

        Lesson::factory()->for($begin)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        Lesson::factory()->for($cont)->create([
            'title' => '13-е занятие',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $user = User::factory()->create();
        $user->groups()->attach([$groupBegin->id, $groupCont->id]);

        $this->actingAs($user)
            ->get(route('student.course', $cont->slug))
            ->assertOk()
            ->assertSee('data-testid="course-continuation-banner"', false)
            ->assertSee('с 13-го занятия', false)
            ->assertSee('Архив с 1-го', false)
            ->assertSee('Открыть 1-е занятие', false);
    }

    public function test_infer_continues_from_lesson_title(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->for($course)->create([
            'title' => 'Курс хинди, 13-е занятие, начальная №5',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->assertSame(13, app(CourseContinuationBanner::class)->inferContinuesFrom($course));
    }

    public function test_dashboard_card_shows_continuation_hint(): void
    {
        config(['features.cabinet_hybrid' => false]);

        $begin = Course::factory()->create();
        $cont = Course::factory()->create([
            'predecessor_course_id' => $begin->id,
            'continues_from_lesson' => 13,
        ]);
        $group = Group::create(['name' => 'G']);
        $cont->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-testid="dashboard-continuation-hint"', false)
            ->assertSee('с 13-го', false);
    }
}
