<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4672 — money:sli-hourly-reconcile: read-only, must never write to
 * payments/payment_webhook_events. Covers the H2085 silent-grant class this
 * check exists to catch (paid, but the buyer never actually landed in the
 * course's group) plus the healthy/no-false-positive case.
 */
class MoneySliHourlyReconcileTest extends TestCase
{
    use RefreshDatabase;

    private string $tsvPath;

    private string $tgStatePath;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->tsvPath = storage_path('app/money_sli/test_'.uniqid('reconcile_', true).'.tsv');
        $this->tgStatePath = storage_path('app/money_sli/test_'.uniqid('tgstate_', true).'.json');

        config([
            'features.money_sli_hourly_reconcile' => true,
            'money_sli.tsv_path' => $this->tsvPath,
            'money_sli.tg_state_path' => $this->tgStatePath,
            'money_sli.hourly_ping_url' => '',
            'services.telegram.bot_token' => '',
        ]);

        Http::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->tsvPath);
        @unlink($this->tgStatePath);
        parent::tearDown();
    }

    private function readTsvRows(): array
    {
        if (! is_file($this->tsvPath)) {
            return [];
        }
        // rtrim only the trailing newline — trim() would also eat a trailing
        // tab on the LAST line's empty final field (e.g. an empty "notes"),
        // silently shortening only that one row and breaking array_combine.
        $lines = array_filter(explode("\n", rtrim(file_get_contents($this->tsvPath), "\n")), fn ($l) => $l !== '');
        $header = str_getcsv(array_shift($lines), "\t");
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($header, str_getcsv($line, "\t"));
        }

        return $rows;
    }

    /** @test */
    public function a_paid_payment_whose_buyer_is_not_in_the_course_group_is_flagged_as_a_silent_grant(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        // Paid, course needs a group, but the user was never actually synced
        // into it — the exact H2085 failure this check exists to catch.
        // withoutEvents: a normal create-as-paid runs fireOnPaid ->
        // grantAccess() immediately (that's the correct, non-buggy path);
        // the silent-grant class this reconcile exists to catch is a paid
        // row that reached the DB WITHOUT that grant ever running (a stuck
        // job, a manual status fix, a race) — bypass the observer to model
        // that gap directly instead of asserting a state the app itself
        // would never actually produce.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->assertFalse($user->groups()->where('groups.id', $group->id)->exists());

        $this->artisan('money:sli-hourly-reconcile')->assertExitCode(1);

        $rows = $this->readTsvRows();
        $this->assertCount(1, $rows);
        $this->assertSame('hourly_reconcile', $rows[0]['check']);
        $this->assertSame('fail', $rows[0]['status']);
        $this->assertSame('1', $rows[0]['silent_grants']);

        // Read-only: the payment itself must be untouched.
        $this->assertSame('paid', Payment::first()->status);
    }

    /** @test */
    public function a_paid_payment_whose_buyer_is_correctly_in_the_group_is_healthy(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        // fireOnPaid's grantAccess() already synced the group above (no-op,
        // already attached) — the point of this fixture is the ordinary,
        // non-buggy path stays green.

        $this->artisan('money:sli-hourly-reconcile')->assertExitCode(0);

        $rows = $this->readTsvRows();
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame('0', $rows[0]['silent_grants']);
    }
}
