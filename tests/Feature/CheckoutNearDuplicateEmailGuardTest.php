<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CuratorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * H incident 18-08-2026 (Долгополова Анастасия): a checkout signup with a
 * typo'd domain (…@gmail.con vs the real …@gmail.com) sailed past the
 * exact-email dedup in PaymentController::resolveUser() and created a
 * second account, splitting her block_1/block_2 payments across two logins.
 * Default-OFF flag `checkout_near_duplicate_email_guard` — advisory only,
 * never blocks the checkout it fires on.
 */
class CheckoutNearDuplicateEmailGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tochka_tx_001'],
            ], 200),
        ]);
    }

    private function tariff(int $price = 5000): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Checkout group']);
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create(['price' => $price]);
    }

    private function guestPayload(Tariff $tariff, string $email): array
    {
        return [
            'tariff_id' => $tariff->id,
            'surname' => 'Долгополова', 'name' => 'Анастасия', 'city' => 'Barcelona',
            'email' => $email,
        ];
    }

    /** @test */
    public function flag_off_never_pings_curators_even_on_a_near_duplicate_email(): void
    {
        User::factory()->create(['email' => 'anastasiadolgopolova25@gmail.com']);
        $tariff = $this->tariff();

        $this->mock(CuratorNotifier::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('possibleDuplicateAccount');
        });

        $this->post(route('payment.create'), $this->guestPayload($tariff, 'anastasiadolgopolova25@gmail.con'))
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $this->assertDatabaseHas('users', ['email' => 'anastasiadolgopolova25@gmail.con']);
    }

    /** @test */
    public function flag_on_pings_curators_for_a_typo_domain_but_still_lets_checkout_through(): void
    {
        config(['features.checkout_near_duplicate_email_guard' => true]);
        $existing = User::factory()->create(['email' => 'anastasiadolgopolova25@gmail.com']);
        $tariff = $this->tariff();

        $this->mock(CuratorNotifier::class, function (MockInterface $mock) use ($existing) {
            $mock->shouldReceive('possibleDuplicateAccount')
                ->once()
                ->withArgs(fn (User $newUser, User $matched): bool => $newUser->email === 'anastasiadolgopolova25@gmail.con'
                    && $matched->is($existing));
        });

        $this->post(route('payment.create'), $this->guestPayload($tariff, 'anastasiadolgopolova25@gmail.con'))
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        // Advisory only — the near-duplicate signup still succeeds; checkout is never blocked.
        $this->assertDatabaseHas('users', ['email' => 'anastasiadolgopolova25@gmail.con']);
        $this->assertDatabaseCount('payments', 1);
    }

    /** @test */
    public function flag_on_stays_silent_for_an_unrelated_email(): void
    {
        config(['features.checkout_near_duplicate_email_guard' => true]);
        User::factory()->create(['email' => 'someone-else@example.test']);
        $tariff = $this->tariff();

        $this->mock(CuratorNotifier::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('possibleDuplicateAccount');
        });

        $this->post(route('payment.create'), $this->guestPayload($tariff, 'brand-new-student@example.test'))
            ->assertRedirect('https://pay.tochka.com/redirect/abc');
    }
}
