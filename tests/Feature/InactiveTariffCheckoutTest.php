<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InactiveTariffCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/active',
                    'paymentLinkId' => 'active_tariff_tx',
                ],
            ]),
        ]);
    }

    public function test_guard_is_dark_by_default(): void
    {
        $this->assertFalse(config('features.checkout_inactive_tariff_guard'));
    }

    public function test_authenticated_direct_post_to_inactive_tariff_creates_nothing(): void
    {
        config()->set('features.checkout_inactive_tariff_guard', true);

        $user = User::factory()->create();
        $tariff = $this->tariff(false);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_guest_direct_post_to_inactive_tariff_does_not_create_user_or_payment(): void
    {
        config()->set('features.checkout_inactive_tariff_guard', true);

        $tariff = $this->tariff(false);

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Иван',
            'surname' => 'Иванов',
            'city' => 'Москва',
            'email' => 'inactive-tariff@example.test',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'inactive-tariff@example.test']);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_active_tariff_checkout_is_unchanged_when_guard_is_enabled(): void
    {
        config()->set('features.checkout_inactive_tariff_guard', true);

        $user = User::factory()->create();
        $tariff = $this->tariff(true);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/active');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'tariff' => $tariff->accessKey(),
            'status' => 'pending',
        ]);
        Http::assertSentCount(1);
    }

    private function tariff(bool $active): Tariff
    {
        return Tariff::factory()
            ->for(Course::factory())
            ->create([
                'price' => 5000,
                'is_active' => $active,
            ]);
    }
}
