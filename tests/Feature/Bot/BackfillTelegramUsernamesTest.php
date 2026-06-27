<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillTelegramUsernamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.student_bot_token' => 'TEST_BOT_TOKEN']);
    }

    public function test_dry_run_does_not_call_api_or_write(): void
    {
        Http::fake();
        $user = User::factory()->create(['telegram_id' => 5001, 'telegram_username' => null]);

        $this->artisan('telegram:backfill-usernames')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($user->fresh()->telegram_username);
    }

    public function test_apply_saves_username_from_get_chat(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['id' => 5002, 'username' => 'CaughtLater'],
            ], 200),
        ]);
        $user = User::factory()->create(['telegram_id' => 5002, 'telegram_username' => null]);

        $this->artisan('telegram:backfill-usernames --apply --sleep=0')->assertSuccessful();

        $this->assertSame('CaughtLater', $user->fresh()->telegram_username);
    }

    public function test_apply_skips_user_without_username_in_result(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['id' => 5003], // username нет — поле необязательное
            ], 200),
        ]);
        $user = User::factory()->create(['telegram_id' => 5003, 'telegram_username' => null]);

        $this->artisan('telegram:backfill-usernames --apply --sleep=0')->assertSuccessful();

        $this->assertNull($user->fresh()->telegram_username);
    }

    public function test_apply_keeps_username_when_api_unreachable(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);
        $user = User::factory()->create(['telegram_id' => 5004, 'telegram_username' => null]);

        $this->artisan('telegram:backfill-usernames --apply --sleep=0')->assertSuccessful();

        $this->assertNull($user->fresh()->telegram_username);
    }

    public function test_without_all_flag_skips_users_who_already_have_username(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['id' => 5005, 'username' => 'fresh'],
            ], 200),
        ]);
        // У пользователя уже есть username — без --all его не трогаем (API не зовём).
        User::factory()->create(['telegram_id' => 5005, 'telegram_username' => 'existing']);

        $this->artisan('telegram:backfill-usernames --apply --sleep=0')->assertSuccessful();

        Http::assertNothingSent();
    }
}
