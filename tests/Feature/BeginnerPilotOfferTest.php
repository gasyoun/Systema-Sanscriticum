<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\Testimonial;
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

    public function test_future_schedule_requires_staffing_confirmation_and_explicit_end(): void
    {
        $schedule = Schedule::create(['title' => 'Intro', 'start' => now()->addDays(2), 'end' => now()->addDays(2)->addHour()]);
        config(['marathon.schedule_id' => $schedule->id]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
        config(['beginner_pilot.staffed_schedule_id' => $schedule->id, 'beginner_pilot.staffing_confirmed_at' => now()->toIso8601String()]);
        $this->get(route('beginner-pilot.show'))->assertSee('Выбрать участие с проверкой')->assertSee('мск');
        $schedule->update(['end' => null]);
        $this->get(route('beginner-pilot.show'))->assertDontSee('Выбрать участие с проверкой');
    }

    public function test_past_consultation_never_becomes_available_from_old_approval(): void
    {
        $schedule = Schedule::create(['title' => 'Old intro', 'start' => now()->subDays(20), 'end' => now()->subDays(20)->addHour()]);
        config(['marathon.schedule_id' => $schedule->id, 'beginner_pilot.staffed_schedule_id' => $schedule->id, 'beginner_pilot.staffing_confirmed_at' => now()->subDays(21)->toIso8601String()]);
        $this->get(route('beginner-pilot.show'))->assertSee('пока не подтверждена')->assertDontSee('Выбрать участие с проверкой');
        $this->get(route('marathon.show'))->assertOk()->assertSee('до оплаты');
    }
}
