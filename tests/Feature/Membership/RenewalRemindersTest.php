<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Jobs\SendMessengerAlerts;
use App\Models\ClubMembership;
use App\Models\MembershipRenewalReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5823 — последовательность напоминаний о продлении членства.
 *
 * Контракт команды: dry-run по умолчанию; отправка только с --send И
 * включённым флагом; дедуп одна стадия = одно сообщение; явное «не
 * продлевать», бесплатный грант и репетиция не напоминаются.
 */
final class RenewalRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();

        config()->set('features.membership_renewal_reminders', true);
        config()->set('membership.club.course_slug', 'club');
        config()->set('membership.club.grace_days', 3);

        $this->member = User::factory()->create([
            'telegram_id' => 424242,
            // Плейсхолдер-адрес: TG — единственный канал, email не считается.
            'email' => 'member@no-email.com',
        ]);
    }

    private function paidPeriod(User $user, array $overrides = []): ClubMembership
    {
        return ClubMembership::create(array_merge([
            'user_id' => $user->id,
            'payment_id' => null,
            'tier_code' => MembershipTier::Club,
            'term_months' => 1,
            'starts_at' => now()->subDays(28),
            'ends_at' => now()->addDays(2),
            'grace_until' => now()->addDays(5),
            'grace_days' => 3,
            'source' => 'payment',
        ], $overrides));
    }

    public function test_dry_run_by_default_sends_nothing_and_writes_no_log(): void
    {
        $this->paidPeriod($this->member, ['ends_at' => now()->addDays(5), 'grace_until' => now()->addDays(8)]);

        $this->artisan('membership:renewal-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        self::assertSame(0, MembershipRenewalReminder::query()->count());
    }

    public function test_flag_off_blocks_send_even_with_flag(): void
    {
        config()->set('features.membership_renewal_reminders', false);
        $this->paidPeriod($this->member, ['ends_at' => now()->addDays(2), 'grace_until' => now()->addDays(5)]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        self::assertSame(0, MembershipRenewalReminder::query()->count());
    }

    public function test_send_walks_due_stages_and_dedups(): void
    {
        // daysLeft = 2 → из-за этапов d7 и d3, d0 ещё нет.
        $membership = $this->paidPeriod($this->member, ['ends_at' => now()->addDays(2), 'grace_until' => now()->addDays(5)]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, 2);
        Mail::assertNothingSent(); // TG доступен — email не нужен
        self::assertSame(2, MembershipRenewalReminder::query()->count());
        self::assertSame(
            ['d3', 'd7'],
            MembershipRenewalReminder::query()->where('club_membership_id', $membership->id)->orderBy('stage')->pluck('stage')->all(),
        );

        // Второй проход: дедуп, ноль новых отправок.
        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, 2);
        self::assertSame(2, MembershipRenewalReminder::query()->count());
    }

    public function test_email_fallback_when_no_telegram(): void
    {
        $noTg = User::factory()->create(['telegram_id' => null, 'email' => 'mail@example.com']);
        $this->paidPeriod($noTg, ['ends_at' => now()->addDays(1), 'grace_until' => now()->addDays(4)]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        Mail::assertSentCount(2); // d7 + d3
    }

    public function test_renewal_cancelled_member_is_never_reminded(): void
    {
        $this->paidPeriod($this->member, [
            'ends_at' => now()->addDays(2),
            'grace_until' => now()->addDays(5),
            'renewal_cancelled_at' => now(),
        ]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        self::assertSame(0, MembershipRenewalReminder::query()->count());
    }

    public function test_free_tier_and_rehearsal_periods_are_skipped(): void
    {
        $this->paidPeriod($this->member, [
            'tier_code' => MembershipTier::Free,
            'ends_at' => now()->addDays(2),
            'grace_until' => now()->addDays(5),
        ]);
        $this->paidPeriod($this->member, [
            'source' => 'rehearsal',
            'ends_at' => now()->addDays(3),
            'grace_until' => now()->addDays(6),
        ]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        self::assertSame(0, MembershipRenewalReminder::query()->count());
    }

    public function test_grace_stage_fires_after_ends_at(): void
    {
        // Вчера закончился, грейс ещё жив (active() пропускает) → только grace1.
        $this->paidPeriod($this->member, ['ends_at' => now()->subDay(), 'grace_until' => now()->addDays(2)]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        self::assertSame(
            ['grace1'],
            MembershipRenewalReminder::query()->pluck('stage')->all(),
        );
    }

    public function test_only_user_filter_scopes_the_test_send(): void
    {
        $other = User::factory()->create(['telegram_id' => 111111, 'email' => 'other@example.com']);
        $this->paidPeriod($other, ['ends_at' => now()->addDays(2), 'grace_until' => now()->addDays(5)]);
        $this->paidPeriod($this->member, ['ends_at' => now()->addDays(2), 'grace_until' => now()->addDays(5)]);

        $this->artisan('membership:renewal-reminders', [
            '--send' => true,
            '--only-user' => (string) $this->member->id,
        ])->assertSuccessful();

        self::assertSame(
            [$this->member->id],
            MembershipRenewalReminder::query()->distinct()->pluck('user_id')->all(),
        );
    }

    public function test_member_without_any_channel_is_reported_and_not_logged(): void
    {
        // Плейсхолдер-адрес карточки каналом не считается (MessagePlaceholders).
        $noChannels = User::factory()->create([
            'telegram_id' => null,
            'vk_id' => null,
            'email' => 'none@no-email.com',
        ]);
        $this->paidPeriod($noChannels, ['ends_at' => now()->addDays(2), 'grace_until' => now()->addDays(5)]);

        $this->artisan('membership:renewal-reminders', ['--send' => true])->assertSuccessful();

        self::assertSame(0, MembershipRenewalReminder::query()->count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }
}
