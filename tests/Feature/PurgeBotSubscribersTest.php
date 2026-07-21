<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Lead;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * newsletter:purge-bots — чистка мусорных «Подписчиков», нагенерённых спам-ботами.
 * По умолчанию dry-run; удаление с --force; свежие подписки бережём через --keep-days.
 */
class PurgeBotSubscribersTest extends TestCase
{
    use RefreshDatabase;

    private function botSubscriber(string $email, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Подписчик',
            'email' => $email,
            'newsletter_subscribed_at' => now()->subDays(2),
            'login_count' => 0,
            'is_admin' => false,
        ], $overrides));
    }

    public function test_dry_run_lists_candidates_but_deletes_nothing(): void
    {
        $this->botSubscriber('bot@example.com');

        $this->artisan('newsletter:purge-bots')
            ->expectsOutputToContain('bot@example.com')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_force_deletes_bot_users_leads_and_cascades_tokens(): void
    {
        $bot = $this->botSubscriber('bot@example.com');
        Lead::create(['email' => 'bot@example.com', 'contact' => 'bot@example.com', 'name' => 'Подписчик']);
        MagicLinkToken::issueFor($bot, 'newsletter');

        $this->artisan('newsletter:purge-bots', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $bot->id]);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('magic_link_tokens', 0); // FK cascadeOnDelete
    }

    public function test_does_not_touch_entered_renamed_admin_or_grouped(): void
    {
        $entered = $this->botSubscriber('entered@example.com', ['login_count' => 1]);
        $renamed = $this->botSubscriber('renamed@example.com', ['name' => 'Иван Петров']);
        $admin = $this->botSubscriber('admin@example.com', ['is_admin' => true]);
        $grouped = $this->botSubscriber('grouped@example.com');
        Group::create(['name' => 'Группа 1'])->users()->attach($grouped->id);

        $this->artisan('newsletter:purge-bots', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $entered->id]);
        $this->assertDatabaseHas('users', ['id' => $renamed->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $grouped->id]);
    }

    public function test_keep_days_excludes_fresh_subscribers(): void
    {
        $fresh = $this->botSubscriber('fresh@example.com', ['newsletter_subscribed_at' => now()->subHours(6)]);
        $old = $this->botSubscriber('old@example.com', ['newsletter_subscribed_at' => now()->subDays(10)]);

        $this->artisan('newsletter:purge-bots', ['--force' => true, '--keep-days' => 3])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $fresh->id]);
        $this->assertDatabaseMissing('users', ['id' => $old->id]);
    }
}
