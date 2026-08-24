<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportChannelRoiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_channels_with_revenue_and_caveat(): void
    {
        $leadVk = Lead::factory()->create(['utm_source' => 'vk', 'utm_campaign' => 'probe_sep26']);
        $leadOrganic = Lead::factory()->create(['utm_source' => null]);
        User::factory()->create(['lead_id' => $leadVk->id]);
        $userNoRevenue = User::factory()->create(['lead_id' => $leadOrganic->id]);

        $course = Course::factory()->create();
        $buyer = User::where('lead_id', $leadVk->id)->first();
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $buyer->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'first_paid_at' => now()->subDays(2),
        ]));

        Artisan::call('report:channel-roi');
        $output = Artisan::output();

        $this->assertStringContainsString('vk / probe_sep26', $output);
        $this->assertStringContainsString('6 000', str_replace("\u{00a0}", ' ', $output));
        $this->assertStringContainsString('caveat', $output);
        $this->assertSame(0, DB::table('payments')->count() - 1, 'read-only: no writes');
    }
}
