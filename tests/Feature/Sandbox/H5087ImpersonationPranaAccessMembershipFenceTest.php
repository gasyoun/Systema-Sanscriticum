<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5087 regression · impersonation-money-fence-prefixes-miss-prana-and-access-writes.
 *
 * Same impersonated session as the NV-10 proof, assertions flipped to the
 * fixed invariant: prana/access/membership writes now 403 inside the
 * impersonated session (belonging to the money/access contour the fence
 * declares read-only), while the real student's own session still works.
 */
class H5087ImpersonationPranaAccessMembershipFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.staff_impersonation' => true,
            'prana.daily_p2p_limit' => 1000,
            'prana.daily_p2p_per_user_limit' => 1000,
        ]);
    }

    /** @return array{User, User, User} super, student (100 prana), friend */
    private function fixture(): array
    {
        $super = User::factory()->create(['role' => Roles::SUPER_ADMIN]);
        $student = User::factory()->create(['email' => 'h5087student@example.test']);
        $friend = User::factory()->create(['email' => 'h5087friend@example.test']);

        DB::table('users')->where('id', $student->id)->update(['prana_balance' => 100]);
        $student->refresh();

        return [$super, $student, $friend];
    }

    private function enterImpersonation(User $super, User $student): void
    {
        $this->actingAs($super);
        $this->get(Impersonation::startUrl($student, Impersonation::MODE_STUDENT))->assertRedirect();
        $this->assertTrue(Impersonation::isActive());
        $this->assertSame($student->id, auth()->id(), 'acting as the student now');
    }

    /** @test */
    public function prana_transfer_is_blocked_inside_impersonation(): void
    {
        [$super, $student, $friend] = $this->fixture();
        $this->enterImpersonation($super, $student);

        $this->post(route('student.prana.transfer'), [
            'email' => $friend->email,
            'amount' => 25,
        ])->assertForbidden();

        $this->assertSame(100, (int) DB::table('users')->where('id', $student->id)->value('prana_balance'), 'balance unchanged');
        $this->assertSame(0, (int) DB::table('users')->where('id', $friend->id)->value('prana_balance'), 'friend got nothing');
        $this->assertTrue(Impersonation::isActive(), 'impersonation still active (blocked write, not session kill)');
    }

    /** @test */
    public function prana_redeem_access_materialize_and_membership_writes_are_blocked_too(): void
    {
        [$super, $student] = $this->fixture();
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(1)->create();
        $this->enterImpersonation($super, $student);

        // All three contours carry money/access semantics -> 403 in the mode.
        $this->post(route('student.prana.redeem', ['perk' => 'some-perk']))->assertForbidden();
        $this->post(route('student.access.materialize', ['slug' => $course->slug]))->assertForbidden();
        $this->post(route('student.membership.cancel'))->assertForbidden();
        $this->post(route('student.membership.resume'))->assertForbidden();
    }

    /** @test */
    public function control_checkout_stays_blocked_and_real_student_can_still_transfer(): void
    {
        [$super, $student, $friend] = $this->fixture();
        $this->enterImpersonation($super, $student);

        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(1)->create();
        $this->post('/checkout/'.$tariff->id.'/promo', ['code' => 'X'])->assertForbidden();

        // Leave the mode; the real student's prana transfer works normally.
        $this->post(route('impersonate.stop'))->assertRedirect();
        $this->assertFalse(Impersonation::isActive());

        // Impersonation::stop restores the super-admin session — re-auth
        // as the student for the control leg.
        $this->actingAs($student);
        $this->post(route('student.prana.transfer'), [
            'email' => $friend->email,
            'amount' => 25,
        ])->assertRedirect();

        $this->assertSame(75, (int) DB::table('users')->where('id', $student->id)->value('prana_balance'), 'own session moves the balance');
        $this->assertSame(25, (int) DB::table('users')->where('id', $friend->id)->value('prana_balance'));
    }
}
