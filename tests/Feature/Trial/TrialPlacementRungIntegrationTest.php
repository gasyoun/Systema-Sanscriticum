<?php

declare(strict_types=1);

namespace Tests\Feature\Trial;

use App\Models\Course;
use App\Models\Deal;
use App\Models\Schedule;
use App\Services\Crm\TrialBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4818 (R2609-01) — F2 placement_rung actually landing on a Deal, both
 * flows (free widget's explicit param, paid checkout's session). Both
 * require crm_trial_booking ON too, same as every other TrialBookingService
 * write (Rank 4 fence, H3247) — f2_placement_quiz never bypasses that gate.
 */
class TrialPlacementRungIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function bookings(): TrialBookingService
    {
        return app(TrialBookingService::class);
    }

    private function introSchedule(): Schedule
    {
        $course = Course::factory()->create(['is_active' => true]);
        $schedule = Schedule::create([
            'title' => 'Вводное',
            'course_id' => $course->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHours(2),
        ]);
        $course->update(['trial_schedule_id' => $schedule->id]);

        return $schedule->fresh();
    }

    /** @test */
    public function book_free_persists_a_valid_placement_rung_on_the_new_deal(): void
    {
        config(['features.crm_trial_booking' => true]);
        $schedule = $this->introSchedule();

        $deal = $this->bookings()->bookFree('guest@example.test', $schedule, [
            'placement_rung' => 'B1',
        ]);

        $this->assertNotNull($deal);
        $this->assertSame('B1', $deal->placement_rung);
    }

    /** @test */
    public function book_free_ignores_a_rung_outside_the_pedagogy_vocabulary(): void
    {
        config(['features.crm_trial_booking' => true]);
        $schedule = $this->introSchedule();

        $deal = $this->bookings()->bookFree('guest@example.test', $schedule, [
            'placement_rung' => 'not-a-rung',
        ]);

        $this->assertNotNull($deal);
        $this->assertNull($deal->placement_rung);
    }

    /** @test */
    public function record_placement_rung_updates_an_existing_deal(): void
    {
        config(['features.crm_trial_booking' => true]);
        $deal = Deal::factory()->trialFree()->create(['placement_rung' => null]);

        $updated = $this->bookings()->recordPlacementRung($deal, 'C2');

        $this->assertSame('C2', $updated->placement_rung);
        $this->assertSame('C2', $deal->fresh()->placement_rung);
    }

    /** @test */
    public function paid_trial_checkout_tags_a_deal_with_the_session_placement_rung_when_flag_on(): void
    {
        config([
            'features.f2_placement_quiz' => true,
            'features.crm_trial_booking' => true,
        ]);

        $course = Course::factory()->create(['slug' => 'grammatika-vvedenie']);
        $schedule = Schedule::create([
            'title' => 'Грамматика (введение)',
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(7, 0),
            'link' => 'https://zoom.us/j/999',
        ]);
        $course->update(['trial_price' => 500, 'trial_schedule_id' => $schedule->id]);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/xyz', 'paymentLinkId' => 'pl_2'],
        ], 200)]);

        session(['placement_rung' => 'A2']);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Петров', 'name' => 'Пётр', 'email' => 'placed@example.test', 'city' => 'Тверь',
        ])->assertRedirect('https://pay.tochka/xyz');

        $deal = Deal::query()->where('trial_source', Deal::TRIAL_SOURCE_PAID)->first();
        $this->assertNotNull($deal, 'paid-trial Deal should have been opened eagerly, same request');
        $this->assertSame('A2', $deal->placement_rung);

        // Payment itself is untouched by the placement-rung block — same shape
        // as the non-H4818 paid-trial path.
        $this->assertDatabaseHas('payments', [
            'course_id' => $course->id, 'tariff' => 'trial', 'status' => 'pending', 'amount' => 500,
        ]);
    }

    /** @test */
    public function paid_trial_checkout_leaves_placement_rung_null_when_session_has_no_rung(): void
    {
        // crm_trial_booking ON already opens/tags a Deal for every trial Payment
        // via PaymentDealBridgeObserver::created() (pre-existing H3247 behaviour,
        // independent of H4818). What H4818 must NOT do is invent a rung when
        // none was answered — this pins placement_rung staying NULL.
        config([
            'features.f2_placement_quiz' => true,
            'features.crm_trial_booking' => true,
        ]);

        $course = Course::factory()->create(['slug' => 'grammatika-bez-kviza']);
        $schedule = Schedule::create([
            'title' => 'Грамматика (без квиза)',
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(7, 0),
            'link' => 'https://zoom.us/j/999',
        ]);
        $course->update(['trial_price' => 500, 'trial_schedule_id' => $schedule->id]);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/xyz', 'paymentLinkId' => 'pl_3'],
        ], 200)]);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Сидоров', 'name' => 'Иван', 'email' => 'noquiz@example.test', 'city' => 'Тверь',
        ])->assertRedirect('https://pay.tochka/xyz');

        $deal = Deal::query()->where('trial_source', Deal::TRIAL_SOURCE_PAID)->first();
        $this->assertNotNull($deal);
        $this->assertNull($deal->placement_rung);
    }

    /** @test */
    public function paid_trial_checkout_opens_no_deal_when_crm_trial_booking_is_off_even_with_a_rung_in_session(): void
    {
        // f2_placement_quiz alone must never bypass the crm_trial_booking gate
        // (Rank 4 fence, H3247) — same discipline as bookFree()/tagPaidPayment().
        config([
            'features.f2_placement_quiz' => true,
            'features.crm_trial_booking' => false,
        ]);

        $course = Course::factory()->create(['slug' => 'grammatika-booking-off']);
        $schedule = Schedule::create([
            'title' => 'Грамматика (booking off)',
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(7, 0),
            'link' => 'https://zoom.us/j/999',
        ]);
        $course->update(['trial_price' => 500, 'trial_schedule_id' => $schedule->id]);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/xyz', 'paymentLinkId' => 'pl_4'],
        ], 200)]);

        session(['placement_rung' => 'C1']);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Кузнецов', 'name' => 'Олег', 'email' => 'bookingoff@example.test', 'city' => 'Казань',
        ])->assertRedirect('https://pay.tochka/xyz');

        $this->assertSame(0, Deal::query()->count());
    }
}
