<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Models\Course;
use App\Models\GrammarLabEntitlement;
use App\Models\GrammarLabPilotEvent;
use App\Models\GrammarLabPilotParticipant;
use App\Models\Payment;
use App\Models\User;
use App\Services\GrammarLab\GrammarLabPilot;
use App\Support\GrammarLabAccess;
use Illuminate\Support\Facades\Artisan;

class GrammarLabPilotTest extends GrammarLabTestCase
{
    private function enablePilot(): void
    {
        $this->enableLab();
        config([
            'features.grammar_lab_pilot' => true,
            'grammar_lab.entitlement_course_slugs' => ['lab-course'],
            'grammar_lab.pilot.eligibility_course_slugs' => ['lab-course'],
        ]);
    }

    private function eligibleStudent(): User
    {
        $course = Course::factory()->create(['slug' => 'lab-course']);
        $user = $this->student();
        Payment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
            'starts_at' => now()->subHour(),
        ]);

        return $user;
    }

    public function test_pilot_routes_404_when_flag_off(): void
    {
        $this->enableLab();
        config(['features.grammar_lab_pilot' => false]);
        $this->actingAs($this->student())
            ->get('/dvaram/grammar-lab/pilot')
            ->assertNotFound();
    }

    public function test_ineligible_student_cannot_enroll(): void
    {
        $this->enablePilot();
        $this->actingAs($this->student())
            ->get('/dvaram/grammar-lab/pilot')
            ->assertOk()
            ->assertSee('только для текущих студентов');

        $this->actingAs($this->student())
            ->post('/dvaram/grammar-lab/pilot', [
                'prior_exposure' => 'kochergina',
                'consent' => '1',
            ])
            ->assertForbidden();
        $this->assertSame(0, GrammarLabPilotParticipant::query()->count());
    }

    public function test_consent_enrolls_and_instrumentation_has_denominators(): void
    {
        $this->enablePilot();
        $this->importFixture();
        $user = $this->eligibleStudent();
        $this->assertTrue(GrammarLabAccess::canUse($user));

        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/pilot', [
                'prior_exposure' => 'kochergina',
                'consent' => '1',
            ])
            ->assertRedirect(route('student.grammar-lab.index'));

        $this->assertSame(1, GrammarLabPilotParticipant::query()->where('user_id', $user->id)->count());
        $this->assertNotNull(GrammarLabPilotParticipant::query()->where('user_id', $user->id)->value('consented_at'));

        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/t/ablaut-grades/compare')
            ->assertOk();
        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/t/ablaut-grades/practice', ['answer' => 'wrong-on-purpose'])
            ->assertOk();
        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/pilot/confusion', [
                'code' => 'search_miss',
                'topic_id' => 'subject:grammar-lab:ablaut-grades',
            ])
            ->assertRedirect();

        $this->assertGreaterThanOrEqual(1, GrammarLabPilotEvent::query()->where('event_type', GrammarLabPilot::EVENT_TASK)->count());
        $this->assertGreaterThanOrEqual(1, GrammarLabPilotEvent::query()->where('event_type', GrammarLabPilot::EVENT_QUIZ)->count());
        $this->assertSame(1, GrammarLabPilotEvent::query()->where('event_type', GrammarLabPilot::EVENT_CONFUSION)->count());

        $report = app(GrammarLabPilot::class)->report();
        $this->assertFalse($report['pilot_authorized']);
        $this->assertSame(1, $report['n_consented']);
        $this->assertSame(1, $report['task_completion']['numerator']);
        $this->assertSame(1, $report['task_completion']['denominator']);
        $this->assertSame(1, $report['quiz_accuracy']['denominator']);
        $this->assertArrayHasKey('search_miss', $report['confusion']['coded']);
        $this->assertStringNotContainsString($user->email, json_encode($report));
    }

    public function test_report_command_has_no_email(): void
    {
        $this->enablePilot();
        $user = $this->eligibleStudent();
        app(GrammarLabPilot::class)->enroll($user, 'none');
        Artisan::call('grammar-lab:pilot-report');
        $out = Artisan::output();
        $this->assertStringContainsString('n_consented', $out);
        $this->assertStringNotContainsString($user->email, $out);
    }

    public function test_eligibility_command_is_count_only(): void
    {
        $this->enablePilot();
        $user = $this->eligibleStudent();
        Artisan::call('grammar-lab:pilot-eligibility', ['--json' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('"n_eligible":1', $out);
        $this->assertStringNotContainsString($user->email, $out);
    }
}
