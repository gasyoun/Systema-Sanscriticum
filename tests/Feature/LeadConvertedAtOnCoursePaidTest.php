<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2186 — Rate B instrumentation: Lead.converted_at on ordinary course paid path.
 * Flag features.lead_converted_at_on_course_paid default OFF (prod inert).
 */
class LeadConvertedAtOnCoursePaidTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flag_defaults_to_off(): void
    {
        $this->assertFalse((bool) config('features.lead_converted_at_on_course_paid'));
    }

    /** @test */
    public function ordinary_course_paid_does_not_convert_lead_when_flag_off(): void
    {
        config(['features.lead_converted_at_on_course_paid' => false]);

        $user = User::factory()->create(['email' => 'buyer@example.test']);
        $lead = Lead::query()->create([
            'name' => 'Buyer',
            'contact' => 'buyer@example.test',
            'email' => 'buyer@example.test',
            'status' => 'new',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $lead->refresh();
        $this->assertNull($lead->converted_at);
        $this->assertSame('new', $lead->status);
    }

    /** @test */
    public function ordinary_course_paid_converts_linked_lead_when_flag_on(): void
    {
        config(['features.lead_converted_at_on_course_paid' => true]);

        $user = User::factory()->create(['email' => 'linked@example.test']);
        $lead = Lead::query()->create([
            'name' => 'Linked',
            'contact' => 'linked@example.test',
            'email' => 'linked@example.test',
            'status' => 'new',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->converted_at);
        $this->assertSame('converted', $lead->status);
    }

    /** @test */
    public function ordinary_course_paid_resolves_lead_by_email_when_lead_id_null_and_flag_on(): void
    {
        config(['features.lead_converted_at_on_course_paid' => true]);

        $user = User::factory()->create(['email' => 'email-match@example.test']);
        $lead = Lead::query()->create([
            'name' => 'Email Match',
            'contact' => 'email-match@example.test',
            'email' => 'email-match@example.test',
            'status' => 'new',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'lead_id' => null,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->converted_at);
        $this->assertSame($lead->id, $payment->fresh()->lead_id);
    }

    /** @test */
    public function conditional_course_paid_never_converts_even_when_flag_on(): void
    {
        config(['features.lead_converted_at_on_course_paid' => true]);

        $user = User::factory()->create(['email' => 'cond@example.test']);
        $lead = Lead::query()->create([
            'name' => 'Cond',
            'contact' => 'cond@example.test',
            'email' => 'cond@example.test',
            'status' => 'new',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => Course::factory()->create(['is_active' => true])->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => true,
        ]);

        $lead->refresh();
        $this->assertNull($lead->converted_at);
    }
}
