<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Bot\StudentSelfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSelfServiceLmsFactTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledStudent(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Группа 1']);
        $user->groups()->attach($group->id);

        return [$user, $group];
    }

    public function test_ors_zoom_phrase_returns_fact_not_null(): void
    {
        [$user, $group] = $this->enrolledStudent();
        Schedule::create([
            'title' => 'Йога-сутры',
            'start' => now()->addDay(),
            'link' => 'https://zoom.us/j/99',
            'group_id' => $group->id,
        ]);

        $reply = app(StudentSelfService::class)->lmsFactReply($user, 'Будет ссылка на занятие?');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('https://zoom.us/j/99', $reply);
    }

    public function test_ors_schedule_link_phrase_is_schedule_not_zoom(): void
    {
        [$user, $group] = $this->enrolledStudent();
        Schedule::create([
            'title' => 'Йога-сутры',
            'start' => now()->addDay(),
            'link' => 'https://zoom.us/j/99',
            'group_id' => $group->id,
        ]);

        $reply = app(StudentSelfService::class)->lmsFactReply($user, 'Пришлите ссылку на расписание курсов');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Ближайшие занятия', $reply);
        $this->assertStringNotContainsString('https://zoom.us/j/99', $reply);
    }

    public function test_ors_missed_class_phrase_returns_recording(): void
    {
        [$user, $group] = $this->enrolledStudent();
        $course = Course::factory()->create();
        Lesson::create([
            'title' => 'Урок 1',
            'is_published' => true,
            'youtube_url' => 'https://youtu.be/ors05',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'lesson_date' => now()->subDay(),
            'block_number' => 1,
        ]);

        $reply = app(StudentSelfService::class)->lmsFactReply($user, 'Первое занятие пропустила, к сожалению');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('https://youtu.be/ors05', $reply);
    }

    public function test_payment_phrase_is_not_intercepted(): void
    {
        [$user] = $this->enrolledStudent();

        $this->assertNull(
            app(StudentSelfService::class)->lmsFactReply($user, 'Сколько стоит курс?'),
        );
    }

    public function test_help_menu_names_lms_facts(): void
    {
        $menu = app(StudentSelfService::class)->helpMenu();

        $this->assertStringContainsString('ссылка на занятие', $menu);
    }
}
