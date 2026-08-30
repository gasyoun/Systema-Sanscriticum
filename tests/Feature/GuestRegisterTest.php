<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MembershipTier;
use App\Http\Middleware\CaptureAttribution;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Payment;
use App\Models\SrsDeck;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;
use App\Support\ClubFreeTierSrsDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H3643 — guest /register: flag OFF → 404; ON → user + Free-tier + SRS, zero payments.
 * H3692 — optional signup_source + birth_year persist; invalid year is non-blocking.
 */
class GuestRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function enableOn(): void
    {
        config()->set('features.guest_registration', true);
        config()->set('features.club_membership', true);
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_advanced_features', true);
        config()->set('membership.club.course_slug', 'club');
    }

    public function test_flag_default_is_false(): void
    {
        $this->assertFalse((bool) config('features.guest_registration'));
    }

    public function test_flag_off_get_register_returns_404(): void
    {
        config()->set('features.guest_registration', false);

        $this->get('/register')->assertNotFound();
    }

    public function test_flag_off_post_register_returns_404(): void
    {
        config()->set('features.guest_registration', false);

        $this->post('/register', [
            'email' => 'guest@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'signup_source' => 'telegram',
            'birth_year' => 1990,
        ])->assertNotFound();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_flag_on_get_shows_form(): void
    {
        $this->enableOn();

        $this->get('/register')
            ->assertOk()
            ->assertSee('Создать кабинет', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="signup_source"', false)
            ->assertSee('name="birth_year"', false);
    }

    public function test_flag_on_creates_user_free_tier_srs_and_zero_payments(): void
    {
        $this->enableOn();

        $response = $this->post('/register', [
            'email' => 'Guest.User@Example.COM',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ]);

        $response->assertRedirect(route('student.dashboard'));

        $user = User::where('email', 'guest.user@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password1', $user->password));
        $this->assertAuthenticatedAs($user);

        $membership = ClubMembership::where('user_id', $user->id)->first();
        $this->assertNotNull($membership, 'Free-tier period must exist');
        $this->assertSame(MembershipTier::Free, $membership->tier_code);
        $this->assertNull($membership->payment_id);
        $this->assertSame(ClubMembership::SOURCE_GUEST_REGISTER, $membership->source);
        $this->assertTrue($membership->isActive());

        $deck = SrsDeck::where('user_id', $user->id)
            ->where('slug', ClubFreeTierSrsDeck::DECK_SLUG)
            ->first();
        $this->assertNotNull($deck);
        $this->assertSame('private', $deck->visibility);

        $this->assertSame(0, Payment::count(), 'guest signup must not write a payment');
    }

    public function test_flag_on_persists_signup_source_and_birth_year(): void
    {
        $this->enableOn();

        $this->post('/register', [
            'email' => 'src@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'signup_source' => 'telegram',
            'birth_year' => 1990,
        ])->assertRedirect(route('student.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'src@example.com',
            'signup_source' => 'telegram',
            'birth_year' => 1990,
        ]);
    }

    public function test_flag_on_invalid_birth_year_is_non_blocking(): void
    {
        $this->enableOn();

        $this->post('/register', [
            'email' => 'year@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'signup_source' => 'friend',
            'birth_year' => 1800,
        ])->assertRedirect(route('student.dashboard'));

        $user = User::where('email', 'year@example.com')->firstOrFail();
        $this->assertSame('friend', $user->signup_source);
        $this->assertNull($user->birth_year);
    }

    public function test_flag_on_copies_utm_from_session(): void
    {
        $this->enableOn();

        $this->withSession([
            CaptureAttribution::SESSION_KEY => ['utm_source' => 'newsletter'],
        ])->post('/register', [
            'email' => 'utm@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertRedirect(route('student.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'utm@example.com',
            'utm_source' => 'newsletter',
        ]);
    }

    public function test_flag_on_does_not_grant_club_recordings(): void
    {
        $this->enableOn();
        $shelf = Course::factory()->create(['club_included' => true]);

        $this->post('/register', [
            'email' => 'free@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertRedirect(route('student.dashboard'));

        $user = User::where('email', 'free@example.com')->firstOrFail();
        $entitlement = app(ClubEntitlement::class);

        $this->assertSame(MembershipTier::Free, $entitlement->activeTierFor($user));
        $this->assertFalse(
            $entitlement->coversCourse($user, $shelf),
            'Free-tier must not open club recordings'
        );
        $this->assertSame([], $entitlement->extraTariffKeys($user, $shelf));
    }

    public function test_duplicate_email_does_not_create_a_second_user_or_payment(): void
    {
        $this->enableOn();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->from('/register')->post('/register', [
            'email' => 'taken@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, ClubMembership::count());
    }
}
