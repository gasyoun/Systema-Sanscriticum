<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Support\UpcomingIntake;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpcomingIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Moscow'));
        config(['features.waitlist_voting' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function course(): Course
    {
        return Course::factory()->create([
            'slug' => 'grammatika-gasuns-2026',
            'title' => 'Грамматика санскрита с нуля — набор 2026',
            'meta_title' => 'Старый набор 2026',
        ]);
    }

    private function item(Course $course, string $slug = 'grammatika-sanskrita-jan2027'): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => $slug,
            'course_id' => $course->id,
            'course_title' => 'Грамматика санскрита с нуля',
            'teacher_name' => 'Преподаватель курса',
            'min_payers' => 999,
            'kind' => 'other',
            'status' => CourseWaitlistItem::STATUS_COLLECTING,
            'is_listed' => true,
            'earliest_start_at' => '2027-01-16',
        ]);
    }

    public function test_january_offer_uses_exact_application_even_when_another_intake_was_created_first(): void
    {
        $course = $this->course();
        $this->item($course, 'different-intake');
        $this->item($course);

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('16 января 2027')
            ->assertSee('По субботам в 09:00 (МСК, UTC+3)')
            ->assertSee('Подать заявку')
            ->assertSee('Заявка сохраняется после входа или регистрации.')
            ->assertSee('value="grammatika-sanskrita-jan2027"', false)
            ->assertDontSee('value="different-intake"', false)
            ->assertDontSee('набор 2026');
    }

    public function test_fixed_time_application_does_not_ask_for_time_preference(): void
    {
        $course = $this->course();
        $item = $this->item($course);
        $view = $this->view('shop.partials.waitlist-join-actions', ['item' => $item, 'application' => true]);
        $view->assertSee('Подать заявку')->assertDontSee('name="slot_preference"', false);
        $this->view('shop.partials.waitlist-join-actions', ['item' => $item])
            ->assertSee('Намерен участвовать')->assertSee('name="slot_preference"', false);
    }

    public function test_wrong_course_association_never_renders_january_application(): void
    {
        $course = $this->course();
        $this->item(Course::factory()->create());
        $this->item($course, 'different-intake');

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()->assertSee('16 января 2027')->assertDontSee('Подать заявку')
            ->assertDontSee('value="different-intake"', false);
    }

    public function test_announcement_expires_at_exact_moscow_start_and_keeps_evergreen_title(): void
    {
        $course = $this->course();
        $this->item($course);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-16T05:59:59Z'));
        $this->assertTrue(UpcomingIntake::for($course->slug)['upcoming']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-16T06:00:00Z'));
        $this->assertFalse(UpcomingIntake::for($course->slug)['upcoming']);

        $this->get(route('shop.course.show', $course->slug))->assertOk()
            ->assertDontSee('data-testid="upcoming-intake"', false)
            ->assertDontSee('Подать заявку')->assertDontSee('набор 2026')
            ->assertSee('Грамматика санскрита с нуля');
    }

    public function test_disabled_waitlist_does_not_publish_an_application_action(): void
    {
        $course = $this->course();
        $this->item($course);
        config(['features.waitlist_voting' => false]);
        $this->get(route('shop.course.show', $course->slug))->assertOk()
            ->assertSee('16 января 2027')->assertDontSee('Подать заявку')
            ->assertSee('https://t.me/rusamskrtam', false);
    }

    public function test_existing_cohort_calendar_is_retained_and_clearly_separated(): void
    {
        $course = $this->course();
        $course->update(['format' => 'live']);
        $this->item($course);
        $group = Group::create(['name' => 'Другой поток']);
        $course->groups()->attach($group->id);
        $schedule = Schedule::create([
            'title' => 'Занятие другого потока',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => '2026-11-14 12:00:00',
            'end' => '2026-11-14 13:30:00',
        ]);
        $before = $schedule->fresh()->getRawOriginal();

        $this->get(route('shop.course.show', $course->slug))->assertOk()
            ->assertSee('16 января 2027')
            ->assertSee('По субботам в 09:00 (МСК, UTC+3)')
            ->assertSee('Расписание других потоков')
            ->assertSee('День и время другого потока')
            ->assertSee('Ближайшее занятие другого потока')
            ->assertSee('Полное расписание других потоков');
        $this->assertSame($before, $schedule->fresh()->getRawOriginal());
    }

    public function test_application_uses_existing_guest_login_and_records_the_exact_intake(): void
    {
        $course = $this->course();
        $item = $this->item($course);
        $user = User::factory()->create(['email' => 'applicant@example.com', 'password' => Hash::make('example-password')]);

        $this->post(route('shop.waitlist.vote'), ['slug' => $item->slug])->assertRedirect(route('login'));
        $this->assertDatabaseCount('waitlist_votes', 0);
        $this->post(route('login.post'), ['email' => $user->email, 'password' => 'example-password'])
            ->assertRedirect(route('shop.waitlist'));
        $this->assertDatabaseHas('waitlist_votes', ['course_waitlist_item_id' => $item->id, 'user_id' => $user->id]);
    }
}
