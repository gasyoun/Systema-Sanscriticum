<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Filament\Resources\PaymentResource;
use App\Models\Course;
use App\Models\Group;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\AssertsTrustBoundaries;
use Tests\TestCase;

/**
 * H5093 regression · trust-boundary contract matrix.
 *
 * The three representative invariants of the preventive contract matrix,
 * proven through the shared Tests\Concerns\AssertsTrustBoundaries layer:
 *
 * A. Guest-controlled strings never reach a staff spreadsheet export as
 *    formula-shaped cells (PR #2670 class — FormulaGuard stays wired).
 * B. Knowing a dedupe key (email/contact) never discloses the existing
 *    lead's bearer capability tokens (PR #2672 class — duplicate flash
 *    stays generic).
 * C. Deleting a paid payment row is authority-gated to admins (PR #2675
 *    class — RoleGate::adminOnly() floor stays in place).
 *
 * Each assertion is mutation-proven: removing the guard at the writer, the
 * genericity of the duplicate flash, or the admin-only gate turns the
 * matching test red (receipts in the H5093 PR description).
 */
class H5093TrustBoundaryContractMatrixTest extends TestCase
{
    use AssertsTrustBoundaries;
    use RefreshDatabase;

    private const TOKEN = 'tokH5093bearer';

    private const VICTIM_EMAIL = 'victim.h5093@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    /** @test */
    public function contract_helper_flags_exactly_the_formula_shaped_cells(): void
    {
        // Dangerous shapes (the classes FormulaGuard neutralizes), including
        // the unary-minus injection class ("-1+HYPERLINK(...)") that a bare
        // second-char heuristic would miss.
        foreach (['=SUM(A1)', '+cmd|/C calc', '@SUM(1)', "\tCMD", "\rCMD", '-cmd|/C calc', '-1+HYPERLINK("http://x","p")', '-2+3+cmd|/C calc', '-'] as $payload) {
            $this->assertTrue($this->trustBoundaryFormulaShapedCell($payload), "must flag «{$payload}»");
        }

        // Legitimate data (fully numeric negatives, plain strings, empties) must pass.
        foreach (['-12.5', '-42', '-1.2e3', '2026-09-17 12:00', 'обычная строка', '', null, '0'] as $payload) {
            $this->assertFalse($this->trustBoundaryFormulaShapedCell($payload), 'must not flag «'.var_export($payload, true).'»');
        }
    }

    /** @test */
    public function boundary_a_guest_formulas_never_reach_export_cells(): void
    {
        // 1) Anonymous store of crafted lead fields (the unauthenticated source).
        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => '+7 999 000 00 01',
            'name' => 'Гость H5093',
            'is_promo_agreed' => '1',
            'utm_source' => '=HYPERLINK("http://127.0.0.1:9/x","p")',
            'utm_campaign' => '+SUM(1+1)*cmd|/C calc!A0',
            'utm_term' => '-cmd|/C calc!A0',
            'utm_content' => '@SUM(A1:A9)',
        ])->assertRedirect();

        $lead = Lead::where('utm_source', '=HYPERLINK("http://127.0.0.1:9/x","p")')->first();
        $this->assertNotNull($lead, 'crafted lead stored from the anonymous form');

        // 2) Authority control: below the staff floor the export is denied.
        $this->actingAs(User::factory()->create())->get('/admin/leads/export')->assertForbidden();

        // 3) The staff export itself is formula-neutral CELL BY CELL.
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get('/admin/leads/export');
        $response->assertOk();

        $this->assertCsvCellsFormulaNeutral($response->streamedContent(), ';');
    }

    /** @test */
    public function boundary_b_duplicate_submission_discloses_no_victim_capability_tokens(): void
    {
        MarketingSetting::create([
            'tg_bot_username' => 'h5093_bot',
            'tg_bot_token' => '111:token',
        ]);
        MarketingSetting::flushCached();

        $landing = LandingPage::create([
            'title' => 'H5093 лендинг',
            'slug' => 'h5093-tb',
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_default_channel' => 'telegram',
        ]);

        Lead::create([
            'name' => 'Жертва H5093',
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'landing_page_id' => $landing->id,
            'magnet_channel' => 'telegram',
            'magnet_token' => self::TOKEN,
        ]);

        // Anonymous attacker submits only the dedupe key (the victim's email).
        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => self::VICTIM_EMAIL,
            'email' => self::VICTIM_EMAIL,
            'landing_page_id' => $landing->id,
            'is_promo_agreed' => '1',
        ], ['REMOTE_ADDR' => '127.0.0.1'])->assertRedirect(route('thank.you'));

        // Session half: no capability-bearing flash key survives the duplicate branch.
        $this->assertSessionExposesNoCapabilityKeys();

        // Render half: the anonymous page carries no link key and no bearer value.
        $page = $this->get(route('thank.you'));
        $page->assertOk();
        $this->assertResponseExposesNoCapabilityTokens($page, [self::TOKEN]);
    }

    /** @test */
    public function boundary_c_paid_payment_deletion_is_authority_gated(): void
    {
        $payment = $this->paidPayment();

        // Single-row delete probe across the authority floor.
        $this->assertTransitionAuthorityGated(
            fn (): bool => PaymentResource::canDelete($payment),
            ['guest', 'manager', 'accountant'],
            ['admin', 'super_admin'],
            'trust-boundary C: payment delete must be admin-gated'
        );

        // Bulk-delete probe — the canDeleteAny() hole (no policy = true).
        $this->assertTransitionAuthorityGated(
            fn (): bool => PaymentResource::canDeleteAny(),
            ['guest', 'manager', 'accountant'],
            ['admin', 'super_admin'],
            'trust-boundary C: payment bulk delete must be admin-gated'
        );
    }

    private function paidPayment(): Payment
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'H5093 группа']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => 'paid',
        ]);

        // The paid transition really granted the benefit the delete would strand.
        $this->assertTrue($student->fresh()->groups->contains($group->id), 'group granted on paid');

        return $payment;
    }
}
