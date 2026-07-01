<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SocialAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsolidateSocialIdentitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_writes_nothing(): void
    {
        User::factory()->create(['telegram_id' => '111']);

        $this->artisan('identity:backfill-social-accounts')->assertSuccessful();

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_apply_materializes_all_three_sources(): void
    {
        $tgUser = User::factory()->create(['telegram_id' => '111']);
        $vkUser = User::factory()->create(['vk_id' => '222']);
        $maxUser = User::factory()->create(['max_user_id' => '333']);

        $contactUser = User::factory()->create();
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 7001,
            'linked_user_id' => $contactUser->id,
        ]);
        TelegramSupportContact::create([
            'telegram_user_id' => 444,
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $contactUser->id,
        ]);

        $this->artisan('identity:backfill-social-accounts --apply')->assertSuccessful();

        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => '111',
            'user_id' => $tgUser->id,
        ]);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_VK,
            'provider_id' => '222',
            'user_id' => $vkUser->id,
        ]);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_MAX,
            'provider_id' => '333',
            'user_id' => $maxUser->id,
        ]);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => '444',
            'user_id' => $contactUser->id,
        ]);
        $this->assertDatabaseCount('social_accounts', 4);
    }

    public function test_apply_is_idempotent(): void
    {
        User::factory()->create(['telegram_id' => '111']);

        $this->artisan('identity:backfill-social-accounts --apply')->assertSuccessful();
        $this->artisan('identity:backfill-social-accounts --apply')->assertSuccessful();

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_normalized_provider_id_reconciles_with_existing_row(): void
    {
        // users.telegram_id с пробелами/ведущими нулями; каноничный provider_id — '111'.
        $user = User::factory()->create(['telegram_id' => ' 00111 ']);
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => '111',
        ]);

        $this->artisan('identity:backfill-social-accounts --apply')->assertSuccessful();

        // Без нормализации получили бы вторую строку ' 00111 ' — проверяем, что её нет.
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertDatabaseMissing('social_accounts', [
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => ' 00111 ',
        ]);
    }

    public function test_existing_row_for_different_user_is_not_overwritten(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['telegram_id' => '111']);

        // Ручная/OAuth-привязка того же telegram id к ДРУГОМУ пользователю.
        SocialAccount::create([
            'user_id' => $owner->id,
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => '111',
        ]);

        $this->artisan('identity:backfill-social-accounts --apply')->assertSuccessful();

        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => SocialAccount::PROVIDER_TELEGRAM,
            'provider_id' => '111',
            'user_id' => $owner->id,
        ]);
    }
}
