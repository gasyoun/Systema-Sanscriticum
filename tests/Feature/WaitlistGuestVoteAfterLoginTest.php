<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Гость жмёт «Намерен участвовать» на /online/zhdun → голос ждёт в сессии →
 * вход / регистрация засчитывает его (CastPendingWaitlistVote), возвращает на
 * витрину и показывает «Спасибо, ваш голос учтён!».
 */
class WaitlistGuestVoteAfterLoginTest extends TestCase
{
    use RefreshDatabase;

    private const THANKS = 'Спасибо, ваш голос учтён!';

    private CourseWaitlistItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.waitlist_voting' => true]);
        $this->item = CourseWaitlistItem::create([
            'slug' => 'zhdun-guest',
            'course_title' => 'Начальный санскрит',
            'teacher_name' => 'Трефилова Елена',
            'min_payers' => 10,
            'kind' => 'other',
            'earliest_start_at' => '2027-10-01',
        ]);
    }

    private function guestVote(string $slug = 'zhdun-guest', ?string $pref = 'evening'): void
    {
        $before = WaitlistVote::count();
        $this->postJson(route('shop.waitlist.vote'), ['slug' => $slug, 'slot_preference' => $pref])
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'auth_required']);
        // Гость в БД ничего не пишет — голос ждёт входа в сессии.
        $this->assertSame($before, WaitlistVote::count());
    }

    public function test_guest_vote_is_cast_on_password_login_and_thanks_shown(): void
    {
        $user = User::factory()->create(['email' => 'voter@example.com', 'password' => Hash::make('secret123')]);

        $this->guestVote();
        $this->get(route('login'))->assertOk()->assertSee('data-pending-waitlist-vote', false);

        $this->post(route('login.post'), ['email' => 'voter@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('shop.waitlist'));

        $this->assertDatabaseHas('waitlist_votes', [
            'course_waitlist_item_id' => $this->item->getKey(),
            'user_id' => $user->id,
            'slot_preference' => 'evening',
        ]);

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertSee(self::THANKS)
            ->assertSee('data-waitlist-unvote="zhdun-guest"', false);

        // Флеш одноразовый, отложенный голос израсходован.
        $this->get(route('shop.waitlist'))->assertDontSee(self::THANKS);
        $this->assertFalse(session()->has('waitlist.pending_vote'));
    }

    public function test_guest_vote_is_cast_on_registration(): void
    {
        config([
            'features.guest_registration' => true,
            'features.club_membership' => true,
            'features.membership_tiered' => true,
            'features.membership_advanced_features' => true,
            'membership.club.course_slug' => 'club',
        ]);

        $this->guestVote(pref: null);
        $this->get(route('register'))->assertOk()->assertSee('data-pending-waitlist-vote', false);

        $this->post(route('register.post'), [
            'email' => 'new-voter@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertRedirect(route('shop.waitlist'));

        $user = User::where('email', 'new-voter@example.com')->firstOrFail();
        $this->assertDatabaseHas('waitlist_votes', [
            'course_waitlist_item_id' => $this->item->getKey(),
            'user_id' => $user->id,
            'slot_preference' => null,
        ]);
        $this->get(route('shop.waitlist'))->assertSee(self::THANKS);
    }

    public function test_existing_vote_is_not_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'voter@example.com', 'password' => Hash::make('secret123')]);
        $this->item->votes()->create(['user_id' => $user->id, 'slot_preference' => 'morning']);

        $this->guestVote();
        $this->post(route('login.post'), ['email' => 'voter@example.com', 'password' => 'secret123']);

        $this->assertSame(1, WaitlistVote::count());
        $this->assertSame('evening', WaitlistVote::first()->slot_preference);
    }

    public function test_unlisted_item_casts_nothing(): void
    {
        User::factory()->create(['email' => 'voter@example.com', 'password' => Hash::make('secret123')]);
        $this->guestVote();
        $this->item->update(['is_listed' => false]);

        $this->post(route('login.post'), ['email' => 'voter@example.com', 'password' => 'secret123']);

        $this->assertSame(0, WaitlistVote::count());
        $this->assertFalse(session()->has('waitlist_voted'));
    }

    public function test_plain_login_without_pending_vote_goes_to_dashboard(): void
    {
        User::factory()->create(['email' => 'voter@example.com', 'password' => Hash::make('secret123')]);

        $this->get(route('login'))->assertDontSee('data-pending-waitlist-vote', false);
        $this->post(route('login.post'), ['email' => 'voter@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('student.dashboard'));
        $this->assertSame(0, WaitlistVote::count());
    }

    public function test_logged_in_vote_shows_thanks_after_reload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('shop.waitlist.vote'), ['slug' => 'zhdun-guest'])
            ->assertOk()
            ->assertJson(['ok' => true, 'votes' => 1]);

        $this->actingAs($user)->get(route('shop.waitlist'))->assertSee(self::THANKS);
    }
}
