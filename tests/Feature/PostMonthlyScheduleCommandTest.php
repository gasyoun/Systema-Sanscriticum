<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostMonthlyScheduleCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://n8n.test/webhook/monthly-schedule-post';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('services.n8n.monthly_schedule_webhook', self::WEBHOOK);
        config()->set('services.n8n.monthly_schedule_secret', 'sek');
        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);
    }

    private function makeCurrentCourse(): Course
    {
        $course = Course::factory()->create(['is_active' => true, 'is_visible' => true, 'slug' => 'kurs', 'title' => 'Курс']);
        CourseBlock::factory()->for($course)
            ->withDates(now()->startOfMonth(), now()->endOfMonth())
            ->create(['number' => 1]);

        return $course;
    }

    /** @test */
    public function it_posts_payload_with_secret_header_when_courses_exist(): void
    {
        $this->makeCurrentCourse();

        $this->artisan('schedule:post-monthly')->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === self::WEBHOOK
                && $request->header('X-Webhook-Secret')[0] === 'sek'
                && ($request->data()['action'] ?? null) === 'monthly_schedule_post'
                && str_contains($request->data()['text_tg'] ?? '', 'Курс')
                && is_array($request->data()['courses'] ?? null)
                && count($request->data()['courses']) === 1;
        });
    }

    /** @test */
    public function it_sends_nothing_when_no_courses_this_month(): void
    {
        $this->artisan('schedule:post-monthly')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** @test */
    public function dry_run_does_not_send(): void
    {
        $this->makeCurrentCourse();

        $this->artisan('schedule:post-monthly', ['--dry' => true])->assertSuccessful();

        Http::assertNothingSent();
    }
}
