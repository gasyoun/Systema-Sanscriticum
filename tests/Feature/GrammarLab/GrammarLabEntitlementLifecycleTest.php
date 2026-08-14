<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Course;
use App\Models\GrammarLabEntitlement;
use App\Models\Group;
use App\Models\Payment;
use App\Services\GrammarLab\GrammarLabEntitlementService;
use App\Support\GrammarLabAccess;
use Illuminate\Support\Facades\Artisan;

class GrammarLabEntitlementLifecycleTest extends GrammarLabTestCase
{
    private function wireProducts(): array
    {
        $this->enableLab();
        $included = Course::factory()->create(['slug' => 'lab-included']);
        $standalone = Course::factory()->create(['slug' => 'lab-standalone']);
        foreach ([$included, $standalone] as $course) {
            $course->groups()->attach(Group::factory()->create());
        }
        config([
            'grammar_lab.entitlement_course_slugs' => ['lab-included'],
            'grammar_lab.subscription_course_slug' => 'lab-standalone',
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.client_id' => 'test-client',
            'services.paypal.subscriptions.client_secret' => 'test-secret',
            'services.paypal.subscriptions.webhook_id' => 'WH-test',
            'services.paypal.subscriptions.mode' => 'sandbox',
            'services.paypal.subscriptions.skip_signature_verify' => true,
        ]);

        return [$included, $standalone];
    }

    public function test_matrix_guest_unentitled_course_subscription_expired_revoked_admin(): void
    {
        [$included, $standalone] = $this->wireProducts();
        $svc = app(GrammarLabEntitlementService::class);

        $this->assertFalse(GrammarLabAccess::canUse(null));

        $unentitled = $this->student();
        $this->assertFalse(GrammarLabAccess::canUse($unentitled));

        $courseUser = $this->student();
        $payment = Payment::query()->create([
            'user_id' => $courseUser->id,
            'course_id' => $included->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        $svc->syncFromPayment($payment);
        $this->assertTrue(GrammarLabAccess::canUse($courseUser->fresh()));
        $this->assertSame(
            GrammarLabEntitlementService::KIND_COURSE,
            GrammarLabEntitlement::query()->where('user_id', $courseUser->id)->value('grant_kind')
        );

        $subUser = $this->student();
        $sub = BillingSubscription::factory()->create([
            'user_id' => $subUser->id,
            'course_id' => $standalone->id,
            'provider' => 'paypal',
            'provider_subscription_id' => 'I-G4MATRIX',
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'mode' => BillingSubscriptionMode::PerCourse,
            'amount_rub' => 1900,
        ]);
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-G4-ACT',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-G4MATRIX', 'status' => 'ACTIVE'],
        ])->assertOk();
        $this->assertTrue(GrammarLabAccess::canUse($subUser->fresh()));
        $this->assertSame(
            GrammarLabEntitlementService::KIND_SUBSCRIPTION,
            GrammarLabEntitlement::query()->where('user_id', $subUser->id)->value('grant_kind')
        );

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-G4-CAN',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource' => ['id' => 'I-G4MATRIX', 'status' => 'CANCELLED'],
        ])->assertOk();
        $this->assertFalse(GrammarLabAccess::canUse($subUser->fresh()));
        $this->assertNotNull(
            GrammarLabEntitlement::query()->where('user_id', $subUser->id)->value('revoked_at')
        );

        $expired = $this->student();
        $svc->grant($expired, GrammarLabEntitlementService::KIND_PILOT, 'matrix:expired', now()->subHour(), now()->subMinute());
        $this->assertFalse(GrammarLabAccess::canUse($expired->fresh()));

        $adminGrant = $this->student();
        $svc->grant($adminGrant, GrammarLabEntitlementService::KIND_ADMIN, 'matrix:admin', now(), now()->addDay());
        $this->assertTrue(GrammarLabAccess::canUse($adminGrant->fresh()));

        $this->assertSame(BillingSubscriptionStatus::Cancelled, $sub->fresh()->status);
    }

    public function test_unrelated_subscription_does_not_grant(): void
    {
        $this->wireProducts();
        $other = Course::factory()->create(['slug' => 'unrelated-course']);
        $user = $this->student();
        BillingSubscription::factory()->create([
            'user_id' => $user->id,
            'course_id' => $other->id,
            'provider' => 'paypal',
            'provider_subscription_id' => 'I-OTHER',
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'mode' => BillingSubscriptionMode::PerCourse,
            'amount_rub' => 1900,
        ]);
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-G4-OTHER',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-OTHER', 'status' => 'ACTIVE'],
        ])->assertOk();
        $this->assertFalse(GrammarLabAccess::canUse($user->fresh()));
        $this->assertSame(0, GrammarLabEntitlement::query()->where('user_id', $user->id)->count());
    }

    public function test_grant_from_payment_is_idempotent(): void
    {
        [$included] = $this->wireProducts();
        $svc = app(GrammarLabEntitlementService::class);
        $user = $this->student();
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'course_id' => $included->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        $a = $svc->syncFromPayment($payment);
        $b = $svc->syncFromPayment($payment);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, GrammarLabEntitlement::query()->where('user_id', $user->id)->count());
    }

    public function test_rehearse_dry_run_writes_no_rows(): void
    {
        $before = GrammarLabEntitlement::query()->count();
        $code = Artisan::call('grammar-lab:rehearse-entitlement', ['--json' => true]);
        $this->assertSame(0, $code);
        $this->assertSame($before, GrammarLabEntitlement::query()->count());
        $this->assertStringContainsString('dry_run', Artisan::output());
    }

    public function test_rehearse_apply_matrix_is_green(): void
    {
        $code = Artisan::call('grammar-lab:rehearse-entitlement', ['--apply' => true, '--json' => true]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/"verdict"\s*:\s*"PASS"/', $out);
        $this->assertFalse(config('features.grammar_lab_pilot'));
    }

    public function test_protected_payload_still_denied_for_unentitled(): void
    {
        $this->wireProducts();
        $this->importFixture();
        $this->actingAs($this->student())
            ->get('/dvaram/grammar-lab')
            ->assertForbidden()
            ->assertDontSee('Три ступени чередования', false);
    }
}
