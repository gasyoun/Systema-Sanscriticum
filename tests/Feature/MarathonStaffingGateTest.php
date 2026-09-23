<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithStaffedIntroSession;
use Tests\TestCase;

class MarathonStaffingGateTest extends TestCase
{
    use RefreshDatabase;
    use WithStaffedIntroSession;

    public function test_unstaffed_zero_cohort_shows_only_free_choice_in_every_skin(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $skin) {
            $this->get(route('marathon.show', ['skin' => $skin]))
                ->assertOk()
                ->assertSee('value="free"', false)
                ->assertDontSee('value="paid"', false)
                ->assertSee('Платная запись откроется')
                ->assertDontSee('Вы получите личный ответ');
        }
    }

    public function test_unstaffed_paid_registration_creates_no_lead_or_enrollment(): void
    {
        LandingPage::create(['title' => 'Intro', 'slug' => config('marathon.landing_slug'), 'is_active' => true]);

        $this->post(route('marathon.register'), [
            'name' => 'Анна',
            'contact' => 'unstaffed@example.test',
            'track' => 'paid',
            'quiz_goal' => 'try',
        ])->assertSessionHasErrors('track');

        $this->assertDatabaseMissing('leads', ['contact' => 'unstaffed@example.test']);
        $this->assertDatabaseCount('marathon_enrollments', 0);
    }

    public function test_unstaffed_existing_unpaid_enrollment_cannot_start_checkout(): void
    {
        $landing = LandingPage::create(['title' => 'Intro', 'slug' => config('marathon.landing_slug'), 'is_active' => true]);
        $lead = Lead::factory()->create(['contact' => 'pending@example.test', 'landing_page_id' => $landing->id]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_PAID]);
        Http::fake();

        $this->post(route('marathon.pay'), [
            'contact' => 'pending@example.test',
            'email' => 'pending@example.test',
        ])->assertRedirect(route('marathon.show'))->assertSessionHas('error');

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
        Http::assertNothingSent();
    }

    public function test_old_paid_flash_does_not_restore_checkout_form_while_unstaffed(): void
    {
        $this->withSession([
            'marathon_result' => 'try',
            'marathon_track' => MarathonEnrollment::TRACK_PAID,
            'marathon_paid' => false,
        ])->get(route('marathon.show'))
            ->assertOk()
            ->assertDontSee('Шаг 2 из 2 — оплата трека');
    }

    public function test_free_day_two_does_not_promise_a_personal_reply_or_live_date(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => 'beginner-day-two-token']);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_FREE]);

        $this->get(route('marathon.day', ['day' => 2, 'token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('Дата следующей групповой консультации пока не подтверждена')
            ->assertDontSee('мы разберем его лично');
    }

    public function test_verified_staffing_reopens_paid_choice_and_january_is_independent(): void
    {
        $this->get(route('marathon.january.show'))
            ->assertOk()
            ->assertSee('value="paid"', false)
            ->assertDontSee('Платная запись откроется');

        $this->confirmIntroSession();

        $this->get(route('marathon.show'))
            ->assertOk()
            ->assertSee('value="paid"', false)
            ->assertDontSee('Платная запись откроется');
    }
}
