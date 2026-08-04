<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBotWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'partner-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'partner.enabled' => true,
            'partner.bot_secret' => self::SECRET,
        ]);
    }

    /** @test */
    public function disabled_program_preserves_controller_404_without_secret(): void
    {
        config(['partner.enabled' => false, 'partner.bot_secret' => '']);

        $this->postJson('/api/partner-bot/register', $this->registration('disabled'))
            ->assertNotFound();
    }

    /** @test */
    public function enabled_program_with_missing_secret_returns_503(): void
    {
        config(['partner.bot_secret' => '   ']);

        $this->postJson('/api/partner-bot/register', $this->registration('missing'))
            ->assertStatus(503);
    }

    /** @test */
    public function invalid_secret_returns_403(): void
    {
        $this->withHeader('X-Partner-Bot-Secret', 'wrong-secret')
            ->postJson('/api/partner-bot/register', $this->registration('invalid'))
            ->assertForbidden();
    }

    /** @test */
    public function valid_secret_reaches_controller(): void
    {
        $this->withHeader('X-Partner-Bot-Secret', self::SECRET)
            ->postJson('/api/partner-bot/register', $this->registration('valid'))
            ->assertOk()
            ->assertJsonPath('created', true);
    }

    /** @test */
    public function rotated_secret_rejects_old_and_accepts_new(): void
    {
        config(['partner.bot_secret' => 'new-partner-secret']);

        $this->withHeader('X-Partner-Bot-Secret', self::SECRET)
            ->postJson('/api/partner-bot/register', $this->registration('old'))
            ->assertForbidden();

        $this->withHeader('X-Partner-Bot-Secret', 'new-partner-secret')
            ->postJson('/api/partner-bot/register', $this->registration('new'))
            ->assertOk();
    }

    /** @return array{name: string, telegram_username: string} */
    private function registration(string $suffix): array
    {
        return [
            'name' => 'Bot Partner '.$suffix,
            'telegram_username' => 'bot_'.$suffix,
        ];
    }
}
