<?php

declare(strict_types=1);

namespace Tests\Feature\Avatars;

use App\Models\User;
use App\Services\Avatars\AvatarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.student_bot_token' => 'TEST_BOT_TOKEN',
            'services.vk.bot_token' => 'TEST_VK_TOKEN',
        ]);
        Storage::fake('public');
    }

    public function test_telegram_downloads_and_stores_avatar(): void
    {
        Http::fake([
            'api.telegram.org/botTEST_BOT_TOKEN/getChat*' => Http::response([
                'ok' => true,
                'result' => ['id' => 7001, 'photo' => ['big_file_id' => 'BIGFILE']],
            ]),
            'api.telegram.org/botTEST_BOT_TOKEN/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/file_1.jpg'],
            ]),
            'api.telegram.org/file/botTEST_BOT_TOKEN/photos/file_1.jpg' => Http::response('JPEGBYTES'),
        ]);

        $user = User::factory()->create(['telegram_id' => 7001]);

        $ok = app(AvatarSyncService::class)->syncTelegram($user);

        $this->assertTrue($ok);
        $user->refresh();
        $this->assertSame('avatars/'.$user->id.'.jpg', $user->avatar_path);
        $this->assertNotNull($user->avatar_synced_at);
        Storage::disk('public')->assertExists('avatars/'.$user->id.'.jpg');
        $this->assertStringContainsString('avatars/'.$user->id.'.jpg', (string) $user->avatarUrl());
    }

    public function test_telegram_no_photo_leaves_avatar_null_but_marks_synced(): void
    {
        Http::fake([
            'api.telegram.org/botTEST_BOT_TOKEN/getChat*' => Http::response([
                'ok' => true,
                'result' => ['id' => 7002], // photo нет (приватность/нет аватарки)
            ]),
        ]);

        $user = User::factory()->create(['telegram_id' => 7002]);

        $ok = app(AvatarSyncService::class)->syncTelegram($user);

        $this->assertFalse($ok);
        $user->refresh();
        $this->assertNull($user->avatar_path);
        $this->assertNotNull($user->avatar_synced_at); // попытку зафиксировали
        Storage::disk('public')->assertMissing('avatars/'.$user->id.'.jpg');
    }

    public function test_vk_downloads_and_stores_avatar(): void
    {
        Http::fake([
            'api.vk.com/method/users.get*' => Http::response([
                'response' => [[
                    'id' => 555,
                    'has_photo' => 1,
                    'photo_200' => 'https://vk-cdn.example/photo200.jpg',
                ]],
            ]),
            'vk-cdn.example/photo200.jpg' => Http::response('VKJPEG'),
        ]);

        $user = User::factory()->create(['vk_id' => '555']);

        $ok = app(AvatarSyncService::class)->syncVk($user);

        $this->assertTrue($ok);
        $user->refresh();
        $this->assertSame('avatars/'.$user->id.'.jpg', $user->avatar_path);
        Storage::disk('public')->assertExists('avatars/'.$user->id.'.jpg');
    }

    public function test_vk_without_photo_leaves_avatar_null(): void
    {
        Http::fake([
            'api.vk.com/method/users.get*' => Http::response([
                'response' => [[
                    'id' => 556,
                    'has_photo' => 0,
                    'photo_200' => 'https://vk-cdn.example/camera_200.png',
                ]],
            ]),
        ]);

        $user = User::factory()->create(['vk_id' => '556']);

        $ok = app(AvatarSyncService::class)->syncVk($user);

        $this->assertFalse($ok);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_command_dry_run_does_not_call_api(): void
    {
        Http::fake();
        User::factory()->create(['telegram_id' => 7777]);

        $this->artisan('avatars:sync')->assertSuccessful();

        Http::assertNothingSent();
    }
}
