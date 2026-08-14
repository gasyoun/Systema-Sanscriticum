<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Services\GrammarLab\GrammarLabEntitlementService;
use App\Support\GrammarLabAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * H2495 — sandbox entitlement matrix. Default dry-run (no rows).
 * --apply writes synthetic users/payments inside withoutEvents for the
 * payment create; product grants go through the same service the webhook uses.
 * Never charges, never flips GRAMMAR_LAB.
 */
class RehearseGrammarLabEntitlement extends Command
{
    protected $signature = 'grammar-lab:rehearse-entitlement
                            {--apply : Persist a sandbox matrix (local/testing only)}
                            {--json : Print machine-readable log}';

    protected $description = 'Rehearse Grammar Lab guest/course/subscription/revoke/expiry/admin matrix (sandbox, no charge)';

    public function handle(GrammarLabEntitlementService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $log = [
            'command' => 'grammar-lab:rehearse-entitlement',
            'apply' => $apply,
            'grammar_lab_flag' => GrammarLabAccess::featureOn() ? 'ON' : 'OFF',
            'generated_at' => now()->toIso8601String(),
            'steps' => [],
        ];

        if (! $apply) {
            $log['steps'][] = $this->step('dry_run', true, 'No rows written. Pass --apply in sandbox/testing.');
            $this->emit($log);

            return self::SUCCESS;
        }

        config([
            'features.grammar_lab' => true,
            'grammar_lab.entitlement_course_slugs' => ['grammar-lab-included'],
            'grammar_lab.subscription_course_slug' => 'grammar-lab-standalone',
        ]);

        $included = Course::factory()->create([
            'slug' => 'grammar-lab-included',
            'title' => 'G4 included course',
        ]);
        $standalone = Course::factory()->create([
            'slug' => 'grammar-lab-standalone',
            'title' => 'G4 standalone subscription',
        ]);
        foreach ([$included, $standalone] as $course) {
            if (! $course->groups()->exists()) {
                $course->groups()->attach(Group::factory()->create());
            }
        }

        $guestDenied = ! GrammarLabAccess::canUse(null);
        $log['steps'][] = $this->step('guest', $guestDenied, 'guest canUse=false');

        $unentitled = User::factory()->create(['email' => 'g4-unentitled-'.Str::random(6).'@example.test']);
        $log['steps'][] = $this->step('unentitled', ! GrammarLabAccess::canUse($unentitled), 'authenticated without grant');

        $courseUser = User::factory()->create(['email' => 'g4-course-'.Str::random(6).'@example.test']);
        $payment = Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => $courseUser->id,
            'course_id' => $included->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        $svc->syncFromPayment($payment);
        $log['steps'][] = $this->step('course', GrammarLabAccess::canUse($courseUser->fresh()), 'paid included course');

        $subUser = User::factory()->create(['email' => 'g4-sub-'.Str::random(6).'@example.test']);
        $sub = BillingSubscription::factory()->create([
            'user_id' => $subUser->id,
            'course_id' => $standalone->id,
            'provider' => 'paypal',
            'provider_subscription_id' => 'I-G4-'.Str::upper(Str::random(8)),
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'mode' => BillingSubscriptionMode::PerCourse,
            'amount_rub' => 1900,
        ]);
        $sub->status = BillingSubscriptionStatus::Active;
        $sub->save();
        $svc->syncFromSubscription($sub->fresh());
        $log['steps'][] = $this->step('subscription', GrammarLabAccess::canUse($subUser->fresh()), 'active standalone subscription');

        $sub->status = BillingSubscriptionStatus::Cancelled;
        $sub->save();
        $svc->syncFromSubscription($sub->fresh());
        $log['steps'][] = $this->step('revoked', ! GrammarLabAccess::canUse($subUser->fresh()), 'cancelled subscription revokes');

        $expiredUser = User::factory()->create(['email' => 'g4-exp-'.Str::random(6).'@example.test']);
        $svc->grant($expiredUser, GrammarLabEntitlementService::KIND_PILOT, 'rehearse:expired', now()->subDay(), now()->subMinute());
        $log['steps'][] = $this->step('expired', ! GrammarLabAccess::canUse($expiredUser->fresh()), 'ends_at in the past');

        $adminGrantUser = User::factory()->create(['email' => 'g4-admin-grant-'.Str::random(6).'@example.test']);
        $svc->grant($adminGrantUser, GrammarLabEntitlementService::KIND_ADMIN, 'rehearse:admin', now(), now()->addDay());
        $log['steps'][] = $this->step('admin_granted', GrammarLabAccess::canUse($adminGrantUser->fresh()), 'time-bounded admin grant');

        $pass = collect($log['steps'])->every(fn ($s) => $s['pass']);
        $log['verdict'] = $pass ? 'PASS' : 'FAIL';
        $this->emit($log);

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{name:string,pass:bool,detail:string}
     */
    private function step(string $name, bool $pass, string $detail): array
    {
        $this->line(($pass ? 'PASS' : 'FAIL')."  {$name}  {$detail}");

        return ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    }

    /**
     * @param  array<string, mixed>  $log
     */
    private function emit(array $log): void
    {
        $json = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        if ($this->option('json')) {
            $this->output->write($json);
        }
        $dir = storage_path('app/grammar_lab');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/g4_sandbox_lifecycle.json', $json);
    }
}
