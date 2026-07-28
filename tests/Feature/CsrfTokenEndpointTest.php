<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H1774 — GET /csrf-token is the load-bearing dependency behind every anti-419
 * refresh (checkout, login, shop modal) and had no coverage of its own before
 * this handoff.
 */
class CsrfTokenEndpointTest extends TestCase
{
    /** @test */
    public function it_returns_a_fresh_token_for_a_guest_session(): void
    {
        $response = $this->getJson(route('csrf.token'));

        $response->assertOk();
        $response->assertJsonStructure(['token']);
        $this->assertSame(csrf_token(), $response->json('token'));
    }
}
