<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5087 regression · checkout-promo-remove-unthrottled-post.
 *
 * Same surfaces as the NV-05 proof, assertions flipped to the fixed
 * invariant: POST /checkout/{tariff}/promo/remove carries throttle:10,1
 * (route-level, parity with applyPromo), and POST /livewire/update is
 * throttled by the web-group ThrottleLivewireUpdates wrapper.
 */
class H5087PromoAndLivewireThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function promo_remove_route_is_throttled_like_its_sibling(): void
    {
        $removeMiddleware = \Route::getRoutes()->getByName('checkout.promo.remove')->gatherMiddleware();
        $applyMiddleware = \Route::getRoutes()->getByName('checkout.promo')->gatherMiddleware();

        $this->assertContains('throttle:10,1', $removeMiddleware, 'promo/remove now throttled');
        $this->assertContains('throttle:10,1', $applyMiddleware, 'sibling apply still throttled');
    }

    /** @test */
    public function promo_remove_returns_429_after_ten_hits_from_one_ip(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(1)->create(['price' => 4800]);

        $statuses = [];
        foreach (range(1, 11) as $i) {
            $statuses[] = $this->post('/checkout/'.$tariff->id.'/promo/remove', [])->status();
        }

        $this->assertContains(429, $statuses, 'eleventh rapid hit is throttled: '.implode(',', $statuses));
        $this->assertCount(10, array_diff($statuses, [429]), 'first ten hits are not throttled');
    }

    /** @test */
    public function livewire_update_is_throttled_for_anonymous_replays(): void
    {
        $statuses = [];
        foreach (range(1, 31) as $i) {
            $statuses[] = $this->post('/livewire/update', [])->status();
        }

        $this->assertContains(429, $statuses, '31st rapid anonymous replay is throttled: '.implode(',', $statuses));
        $this->assertCount(30, array_diff($statuses, [429]), 'first thirty hits are not throttled');
    }
}
