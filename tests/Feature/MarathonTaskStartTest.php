<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarathonTaskStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_successful_day1_view_stamps_start_only_once(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => 'valid-pilot-token']);
        $enrollment = MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'day1_completed_at' => now()]);
        $this->assertNull($enrollment->day1_started_at);
        $this->get(route('marathon.day', ['day' => 2, 'token' => $lead->magnet_token]))->assertOk();
        $this->assertNull($enrollment->fresh()->day1_started_at);
        $url = route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]);
        $this->get($url)->assertOk();
        $first = $enrollment->fresh()->day1_started_at;
        $this->assertNotNull($first);
        $this->assertNull($enrollment->fresh()->day1_engaged_at);
        $this->travel(5)->minutes();
        $this->get($url)->assertOk();
        $this->assertTrue($first->equalTo($enrollment->fresh()->day1_started_at));
    }

    public function test_invalid_tokens_and_unavailable_content_do_not_record_starts(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => 'valid-pilot-token']);
        $enrollment = MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);
        $this->get(route('marathon.day', ['day' => 1, 'token' => 'bad-token']))->assertNotFound();
        $this->assertNull($enrollment->fresh()->day1_started_at);
        config(['marathon.day1_quiz' => null, 'marathon.cohorts' => []]);
        $this->get(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]))->assertNotFound();
        $this->assertNull($enrollment->fresh()->day1_started_at);
    }
}
