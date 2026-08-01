<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\WorkQueueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NOBORING Wave 1b (H2119): бакет «open Deal старше N часов» в WorkQueueReport.
 * Флаг dozhim_queue OFF → пусто; ON → только aged open, не young и не won.
 */
class DozhimUnpaidDealQueueTest extends TestCase
{
    use RefreshDatabase;

    private function report(): WorkQueueReport
    {
        return app(WorkQueueReport::class);
    }

    private function seedOpenDeal(array $attrs = []): Deal
    {
        $stage = DealStage::first() ?? DealStage::factory()->create();
        $course = Course::factory()->create();
        $user = User::factory()->create();

        return Deal::factory()->create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'stage_id' => $stage->id,
            'closed_at' => null,
            'amount' => 12000,
        ], $attrs));
    }

    /** @test */
    public function unpaid_open_deals_empty_when_flag_off(): void
    {
        config(['features.dozhim_queue' => false, 'dozhim.unpaid_deal_hours' => 24]);

        $deal = $this->seedOpenDeal();
        $deal->forceFill(['created_at' => now()->subHours(48)])->save();

        $this->assertCount(0, $this->report()->unpaidOpenDeals());
        $this->assertSame(0, $this->report()->counts()['unpaid_deals']);
    }

    /** @test */
    public function unpaid_open_deals_includes_aged_open_excludes_young_and_won(): void
    {
        config(['features.dozhim_queue' => true, 'dozhim.unpaid_deal_hours' => 24]);

        $aged = $this->seedOpenDeal();
        $aged->forceFill(['created_at' => now()->subHours(30)])->save();

        $young = $this->seedOpenDeal();
        $young->forceFill(['created_at' => now()->subHours(2)])->save();

        $won = $this->seedOpenDeal([
            'closed_at' => now()->subHour(),
            'closed_reason' => Deal::REASON_WON,
        ]);
        $won->forceFill(['created_at' => now()->subHours(72)])->save();

        $bucket = $this->report()->unpaidOpenDeals();

        $this->assertCount(1, $bucket);
        $this->assertTrue($bucket->contains('id', $aged->id));
        $this->assertFalse($bucket->contains('id', $young->id));
        $this->assertFalse($bucket->contains('id', $won->id));
        $this->assertSame(1, $this->report()->counts()['unpaid_deals']);
    }

    /** @test */
    public function dozhim_queue_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('features.dozhim_queue'));
    }
}
