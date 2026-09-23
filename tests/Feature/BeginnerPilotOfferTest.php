<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\Testimonial;
use App\Support\BeginnerPilotOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeginnerPilotOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_leads_to_beginner_route_without_unverified_testimonials(): void
    {
        Testimonial::create(['author_name' => 'Private example', 'body' => 'Unverified story text', 'is_visible' => true, 'is_featured' => true]);
        $this->get('/')->assertOk()->assertSee(route('beginner-pilot.show'), false)
            ->assertSee(route('shop.index'), false)->assertDontSee('Unverified story text');
    }

    public function test_only_published_free_video_is_promoted_and_price_comes_from_config(): void
    {
        $lesson = Lesson::factory()->free()->create(['course_id' => Course::factory()->create()->id, 'rutube_url' => 'https://rutube.ru/play/embed/example/']);
        config(['beginner_pilot.preview_lesson_id' => $lesson->id, 'marathon.paid_track_price' => 750]);
        $this->get(route('beginner-pilot.show'))->assertOk()->assertSee($lesson->rutube_url, false)
            ->assertSee('750 ₽')->assertSee('Полная запись')->assertSee('пока не подтверждена');
        $lesson->update(['is_free' => false]);
        $this->get(route('beginner-pilot.show'))->assertDontSee($lesson->rutube_url, false);
    }

    public function test_excerpt_is_bound_to_the_verified_public_video(): void
    {
        $lesson = Lesson::factory()->free()->create(['course_id' => Course::factory()->create()->id, 'youtube_url' => 'https://www.youtube.com/embed/FmdnLXZ4UFo']);
        config(['beginner_pilot.preview_lesson_id' => $lesson->id]);
        $this->get(route('beginner-pilot.show'))->assertSee('start=5337&amp;end=5440', false)->assertSee('1 минуту 43 секунды');
        $lesson->update(['is_published' => false]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('start=5337', false);
    }

    public function test_future_schedule_requires_staffing_confirmation_and_explicit_end(): void
    {
        $schedule = Schedule::create(['title' => 'Intro', 'start' => now()->addDays(4), 'end' => now()->addDays(4)->addHour(), 'link' => 'https://example.test/join/beginner']);
        config(['marathon.schedule_id' => $schedule->id]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
        config(['beginner_pilot.staffed_schedule_id' => $schedule->id, 'beginner_pilot.staffing_confirmed_at' => now()->toIso8601String(), 'beginner_pilot.staffed_schedule_start' => $schedule->start->toIso8601String(), 'beginner_pilot.staffed_schedule_end' => $schedule->end->toIso8601String()]);
        $this->get(route('beginner-pilot.show'))->assertSee('Выбрать участие с проверкой')->assertSee('мск');
        $schedule->update(['start' => now()->addDays(3), 'end' => now()->addDays(3)->addHour()]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
        $schedule->update(['end' => null]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
    }

    public function test_paid_choice_requires_a_join_link_and_closes_before_personal_day_three(): void
    {
        $schedule = Schedule::create([
            'title' => 'Intro',
            'start' => now()->addDays(7)->startOfDay()->setTime(19, 0),
            'end' => now()->addDays(7)->startOfDay()->setTime(20, 0),
        ]);
        config([
            'marathon.schedule_id' => $schedule->id,
            'beginner_pilot.staffed_schedule_id' => $schedule->id,
            'beginner_pilot.staffing_confirmed_at' => now()->toDateString(),
            'beginner_pilot.staffed_schedule_start' => $schedule->start->toIso8601String(),
            'beginner_pilot.staffed_schedule_end' => $schedule->end->toIso8601String(),
        ]);

        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
        $schedule->update(['link' => 'https://example.test/join/beginner']);
        $this->get(route('beginner-pilot.show'))->assertSee('Выбрать участие с проверкой');

        $this->travelTo(BeginnerPilotOffer::registrationCutoff());
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
    }

    public function test_past_consultation_never_becomes_available_from_old_approval(): void
    {
        $schedule = Schedule::create(['title' => 'Old intro', 'start' => now()->subDays(20), 'end' => now()->subDays(20)->addHour()]);
        config(['marathon.schedule_id' => $schedule->id, 'beginner_pilot.staffed_schedule_id' => $schedule->id, 'beginner_pilot.staffing_confirmed_at' => now()->subDays(21)->toIso8601String()]);
        $this->get(route('beginner-pilot.show'))->assertSee('пока не подтверждена')->assertDontSee('Выбрать участие с проверкой');
        $this->get(route('marathon.show'))->assertOk()->assertSee('Платная запись откроется');
    }
}
