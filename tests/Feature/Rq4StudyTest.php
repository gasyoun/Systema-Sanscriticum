<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rq4Participant;
use App\Models\Rq4Response;
use App\Models\ScheduledReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H987 -- RQ4 study harness (SanskritGrammar docs/RQ4_EVALUATION_PROTOCOL_2026.md).
 * Behind `features.rq4_study` (default OFF).
 */
class Rq4StudyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.rq4_study' => true]);
    }

    public function test_flag_off_routes_404(): void
    {
        config(['features.rq4_study' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/rq4-study')->assertNotFound();
    }

    public function test_enroll_creates_participant_with_arm_assigned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rq4-study/enroll', [
            'prior_exposure' => 'kochergina',
            'consent' => '1',
        ])->assertRedirect(route('rq4.diagnostic', ['phase' => 'pre_test']));

        $participant = Rq4Participant::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('kochergina', $participant->prior_exposure);
        $this->assertContains($participant->arm, [Rq4Participant::ARM_ON_RAMP, Rq4Participant::ARM_TALMUD]);
        $this->assertNotNull($participant->consented_at);
    }

    public function test_enroll_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/rq4-study/enroll', ['prior_exposure' => 'none', 'consent' => '1']);
        $this->actingAs($user)->post('/rq4-study/enroll', ['prior_exposure' => 'beyond', 'consent' => '1']);

        $this->assertSame(1, Rq4Participant::where('user_id', $user->id)->count());
        // First enrollment wins -- second call doesn't overwrite the stratum/arm.
        $this->assertSame('none', Rq4Participant::where('user_id', $user->id)->first()->prior_exposure);
    }

    public function test_stratified_assignment_balances_within_stratum(): void
    {
        // 10 participants in the same stratum -> assignArm should never let one
        // arm run more than 1 ahead of the other (the minimisation guarantee).
        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create();
            $this->actingAs($user)->post('/rq4-study/enroll', ['prior_exposure' => 'kochergina', 'consent' => '1']);
        }

        $counts = Rq4Participant::where('prior_exposure', 'kochergina')
            ->selectRaw('arm, count(*) as c')->groupBy('arm')->pluck('c', 'arm');

        $this->assertLessThanOrEqual(1, abs(($counts['A'] ?? 0) - ($counts['B'] ?? 0)));
        $this->assertSame(10, ($counts['A'] ?? 0) + ($counts['B'] ?? 0));
    }

    public function test_diagnostic_flow_serves_items_then_completes_phase(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/rq4-study/enroll', ['prior_exposure' => 'kochergina', 'consent' => '1']);
        $participant = Rq4Participant::where('user_id', $user->id)->firstOrFail();

        // Answer all 8 pre_test items.
        for ($i = 0; $i < 8; $i++) {
            $res = $this->actingAs($user)->get(route('rq4.diagnostic', ['phase' => 'pre_test']));
            $res->assertOk();
            $item = $res->viewData('item');

            $this->actingAs($user)->post(route('rq4.diagnostic.submit', ['phase' => 'pre_test']), [
                'item_slp1' => $item['slp1'],
                'answered_ryad' => $item['ryad'], // answer correctly
                'answered_tip' => $item['tip'],
                'answered_set' => $item['set'],
            ])->assertRedirect(route('rq4.diagnostic', ['phase' => 'pre_test']));
        }

        $this->assertSame(8, Rq4Response::where('participant_id', $participant->id)->where('phase', 'pre_test')->count());
        $this->assertTrue(Rq4Response::where('participant_id', $participant->id)->where('phase', 'pre_test')->where('correct', true)->exists());

        // 9th visit to pre_test now shows the phase-complete view, not another item.
        $this->actingAs($user)->get(route('rq4.diagnostic', ['phase' => 'pre_test']))
            ->assertOk()
            ->assertSee('Первый тест пройден', false)
            ->assertSee('Пройти второй тест', false);

        $participant->refresh();
        $this->assertNotNull($participant->pre_test_completed_at);
    }

    public function test_post_test_completion_sets_retention_due_in_4_weeks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/rq4-study/enroll', ['prior_exposure' => 'kochergina', 'consent' => '1']);
        $participant = Rq4Participant::where('user_id', $user->id)->firstOrFail();

        $bank = json_decode(file_get_contents(resource_path('data/rq4_item_bank.json')), true);
        foreach ($bank['sets']['post_test'] as $item) {
            $this->actingAs($user)->post(route('rq4.diagnostic.submit', ['phase' => 'post_test']), [
                'item_slp1' => $item['slp1'],
                'answered_ryad' => $item['ryad'],
                'answered_tip' => $item['tip'],
                'answered_set' => $item['set'],
            ]);
        }
        $this->actingAs($user)->get(route('rq4.diagnostic', ['phase' => 'post_test']));

        $participant->refresh();
        $this->assertNotNull($participant->post_test_completed_at);
        $this->assertNotNull($participant->retention_due_at);
        $this->assertEqualsWithDelta(
            $participant->post_test_completed_at->addDays(28)->timestamp,
            $participant->retention_due_at->timestamp,
            2,
        );
    }

    public function test_retention_reminder_command_queues_scheduled_reminder_once(): void
    {
        $user = User::factory()->create();
        $participant = Rq4Participant::create([
            'user_id' => $user->id,
            'prior_exposure' => 'kochergina',
            'arm' => 'A',
            'consented_at' => now()->subWeeks(6),
            'post_test_completed_at' => now()->subWeeks(5),
            'retention_due_at' => now()->subDay(), // already due
        ]);

        Artisan::call('rq4:send-retention-reminders');
        Artisan::call('rq4:send-retention-reminders'); // second run must not double-queue

        $this->assertSame(1, ScheduledReminder::where('user_id', $user->id)->count());
        $this->assertNotNull($participant->fresh()->retention_reminder_sent_at);
    }

    public function test_retention_reminder_command_flag_off_noop(): void
    {
        config(['features.rq4_study' => false]);
        Rq4Participant::create([
            'user_id' => User::factory()->create()->id,
            'prior_exposure' => 'kochergina',
            'arm' => 'A',
            'consented_at' => now()->subWeeks(6),
            'post_test_completed_at' => now()->subWeeks(5),
            'retention_due_at' => now()->subDay(),
        ]);

        Artisan::call('rq4:send-retention-reminders');

        $this->assertSame(0, ScheduledReminder::count());
    }
}
