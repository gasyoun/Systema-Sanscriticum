<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\DelegationKpiService;
use App\Services\StudentPulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Пульс «активные платные ученики» (H4908): кандидат-определения строками,
 * read-only, staff исключён, окна детерминированы via asOf.
 */
class StudentPulseServiceTest extends TestCase
{
    use RefreshDatabase;

    private StudentPulseService $svc;

    /** asOf, от которого считаются все окна. */
    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(StudentPulseService::class);
        $this->asOf = now();
    }

    private function paid(User|int $user, string $createdAt, int $amount = 1000): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'amount' => $amount,
            'status' => 'paid',
            'created_at' => $this->asOf->copy()->subDays($createdAt === 'today' ? 0 : (int) $createdAt)->startOfDay(),
        ]));
    }

    /** @test */
    public function counts_payers_by_windows_excluding_staff(): void
    {
        $in90 = User::factory()->create();
        $this->paid($in90, '10'); // платил 10 дн назад

        $in180 = User::factory()->create();
        $this->paid($in180, '180'); // платил 180 дн назад (вне ≤120, в ≤365)

        $staff = User::factory()->create(['role' => 'teacher']);
        $this->paid($staff, '5'); // staff не считается никогда

        $snap = $this->svc->snapshot($this->asOf);

        $this->assertSame(1, $snap['paid_30d']); // in90 платил 10 дн назад
        $this->assertSame(1, $snap['paid_90d']); // только in90
        $this->assertSame(1, $snap['paid_120d']);
        $this->assertSame(2, $snap['paid_365d']); // in90 + in180
        $this->assertSame(2, $snap['paid_ever']);
    }

    /** @test */
    public function repeat_payer_counts_users_with_two_payments(): void
    {
        $repeat = User::factory()->create();
        $this->paid($repeat, '30');
        $this->paid($repeat, '100');

        $single = User::factory()->create();
        $this->paid($single, '50');

        $snap = $this->svc->snapshot($this->asOf);

        $this->assertSame(2, $snap['paid_120d']); // оба
        $this->assertSame(1, $snap['repeat_120d']); // только repeat
    }

    /** @test */
    public function non_paid_statuses_never_count(): void
    {
        $user = User::factory()->create();
        $p = $this->paid($user, '5');
        $p->status = 'pending';
        $p->save();

        $snap = $this->svc->snapshot($this->asOf);

        $this->assertSame(0, $snap['paid_30d']);
        $this->assertSame(0, $snap['paid_ever']);
    }

    /** @test */
    public function cabinet_active_counts_only_recent_logins_among_payers(): void
    {
        $payer = User::factory()->create(['last_login_at' => $this->asOf->copy()->subDays(2)]);
        $this->paid($payer, '10');

        $payerStale = User::factory()->create(['last_login_at' => $this->asOf->copy()->subDays(90)]);
        $this->paid($payerStale, '10');

        $visitorNoPay = User::factory()->create(['last_login_at' => $this->asOf->copy()->subDays(1)]);

        $snap = $this->svc->snapshot($this->asOf);

        $this->assertSame(1, $snap['cabinet_active_30d']);
        $this->assertSame(2, $snap['paid_30d']); // оба платили ≤30 дн
    }

    /** @test */
    public function lines_and_headline_render_all_candidates(): void
    {
        $repeat = User::factory()->create();
        $this->paid($repeat, '30');
        $this->paid($repeat, '100');

        $lines = $this->svc->pulseLines($this->asOf);

        $this->assertCount(7, $lines);
        $this->assertStringContainsString('платил ≤90 дн — 1', $lines[2]);
        $this->assertStringContainsString('≥2 оплат за ≤120 дн — 1', $lines[4]);
        $this->assertStringContainsString('всего плативших когда-либо — 1', $lines[6]);

        $headline = $this->svc->headlineFromSnapshot($this->svc->snapshot($this->asOf));
        $this->assertStringContainsString('≤90 дн: 1', $headline);
    }

    /** @test */
    public function delegation_snapshot_carries_pulse_card_and_lines(): void
    {
        $repeat = User::factory()->create();
        $this->paid($repeat, '30');
        $this->paid($repeat, '100');

        $snap = app(DelegationKpiService::class)->snapshot($this->asOf);

        $pulseCard = collect($snap['cards'])->firstWhere('key', 'student_pulse');
        $this->assertNotNull($pulseCard);
        $this->assertSame('gray', $pulseCard['level']);
        $this->assertStringContainsString('≤90 дн: 1', $pulseCard['value']);
        $this->assertCount(7, $snap['pulse_lines']);
        $this->assertStringContainsString('платил ≤120 дн — 1', $snap['pulse_lines'][3]);
    }
}
