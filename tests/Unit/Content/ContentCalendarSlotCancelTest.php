<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Models\ContentCalendarSlot;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ContentCalendarSlot::canCancel() — D15/24h cancel window (H1568, content
 * calendar W5). VERIFICATION criterion E2. Pure model logic, no DB needed.
 */
class ContentCalendarSlotCancelTest extends TestCase
{
    private function slot(string $status, ?Carbon $publishAt): ContentCalendarSlot
    {
        $slot = new ContentCalendarSlot;
        $slot->status = $status;
        $slot->publish_at = $publishAt;

        return $slot;
    }

    public function test_scheduled_more_than_24h_out_can_cancel(): void
    {
        $now = Carbon::parse('2026-08-01 12:00:00', 'Europe/Moscow');
        $slot = $this->slot(ContentCalendarSlot::STATUS_SCHEDULED, $now->copy()->addHours(48));

        $this->assertTrue($slot->canCancel($now));
    }

    public function test_scheduled_inside_24h_window_cannot_cancel(): void
    {
        $now = Carbon::parse('2026-08-01 12:00:00', 'Europe/Moscow');
        $slot = $this->slot(ContentCalendarSlot::STATUS_SCHEDULED, $now->copy()->addHours(23));

        $this->assertFalse($slot->canCancel($now));
    }

    public function test_scheduled_exactly_at_boundary_cannot_cancel(): void
    {
        $now = Carbon::parse('2026-08-01 12:00:00', 'Europe/Moscow');
        $slot = $this->slot(ContentCalendarSlot::STATUS_SCHEDULED, $now->copy()->addHours(24));

        // now < publish_at - 24h is strict; at exactly publish_at-24h it's false.
        $this->assertFalse($slot->canCancel($now));
    }

    public function test_scheduled_just_past_boundary_can_cancel(): void
    {
        $now = Carbon::parse('2026-08-01 12:00:00', 'Europe/Moscow');
        $slot = $this->slot(ContentCalendarSlot::STATUS_SCHEDULED, $now->copy()->addHours(24)->addMinute());

        $this->assertTrue($slot->canCancel($now));
    }

    public function test_published_can_never_cancel(): void
    {
        $slot = $this->slot(ContentCalendarSlot::STATUS_PUBLISHED, null);

        $this->assertFalse($slot->canCancel());
    }

    public function test_canceled_can_never_cancel_again(): void
    {
        $slot = $this->slot(ContentCalendarSlot::STATUS_CANCELED, null);

        $this->assertFalse($slot->canCancel());
    }

    public function test_draft_without_publish_at_can_cancel(): void
    {
        $slot = $this->slot(ContentCalendarSlot::STATUS_DRAFT, null);

        $this->assertTrue($slot->canCancel());
    }
}
