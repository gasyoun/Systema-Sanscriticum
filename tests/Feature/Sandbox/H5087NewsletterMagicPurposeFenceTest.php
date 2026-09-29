<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5087 regression · newsletter-magic-route-accepts-cross-purpose-magic-tokens.
 *
 * Same tokens as the NV-12 proof, assertions flipped to the fixed invariant:
 * /magic/{token} consumes ONLY purpose=newsletter tokens — admin_unblock and
 * tg_login tokens are no longer consumed by the newsletter route (no
 * cross-purpose login, no flag-gate bypass).
 */
class H5087NewsletterMagicPurposeFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.newsletter_subscribe' => true,
            'features.telegram_cabinet_login' => false, // the gate /magic used to bypass
        ]);
    }

    /** @test */
    public function admin_unblock_token_is_rejected_by_magic_route_and_not_consumed(): void
    {
        $victim = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($victim, 'admin_unblock', 60);
        $row = MagicLinkToken::where('user_id', $victim->id)->where('purpose', 'admin_unblock')->first();

        $this->get('/magic/'.$plaintext)->assertNotFound();
        $this->assertGuest('web');
        $this->assertNull($row->fresh()->consumed_at, 'cross-purpose token NOT consumed by /magic');
    }

    /** @test */
    public function tg_login_token_is_rejected_by_magic_route_even_with_telegram_login_disabled(): void
    {
        $victim = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($victim, 'tg_login', 60);

        // Own route honours the flag (404) — and now /magic does NOT bypass it.
        $this->get('/tg-login/'.$plaintext)->assertNotFound();
        $this->assertGuest('web');

        $this->get('/magic/'.$plaintext)->assertNotFound();
        $this->assertGuest('web');

        $row = MagicLinkToken::where('user_id', $victim->id)->where('purpose', 'tg_login')->first();
        $this->assertNull($row->fresh()->consumed_at, 'tg_login token NOT consumed by the newsletter route');
    }

    /** @test */
    public function newsletter_token_still_logs_in_on_magic_route(): void
    {
        $user = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter', 60);

        $this->get('/magic/'.$plaintext)
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');

        // Replay of a consumed token stays 404.
        $this->postJson('/logout');
        $this->assertGuest('web');
        $this->get('/magic/'.$plaintext)->assertNotFound();
    }
}
