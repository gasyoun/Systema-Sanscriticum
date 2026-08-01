<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ZapisiBot\ZapisiBotDashboard;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ZapisiBotKickFromRosterTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = storage_path('framework/testing/zapisi-kick-'.uniqid());
        File::ensureDirectoryExists($this->store.'/roster');
        config(['services.telegram_harvest.store_path' => $this->store]);

        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    public function test_kick_member_uses_roster_telegram_id(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $group = Group::create([
            'name' => 'Группа тест',
            'telegram_chat_id' => '-100999',
            'status' => 'active',
        ]);

        File::put($this->store.'/roster/-100999.json', json_encode([
            'count' => 2,
            'fetched_at' => now()->toIso8601String(),
            'members' => [
                ['id' => 111, 'username' => 'student_a', 'name' => 'Студент А', 'is_bot' => false],
                ['id' => 222, 'username' => 'botty', 'name' => 'Bot', 'is_bot' => true],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($url, 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'administrator', 'can_restrict_members' => true],
                ]);
            }
            if (str_contains($url, 'banChatMember') || str_contains($url, 'unbanChatMember')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => false], 400);
        });

        Livewire::actingAs($admin)
            ->test(ZapisiBotDashboard::class)
            ->set('data.group_id', $group->id)
            ->call('kickMember', 111)
            ->assertHasNoErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '111'
            && (string) ($r->data()['chat_id'] ?? '') === '-100999');

        $roster = json_decode(File::get($this->store.'/roster/-100999.json'), true);
        $this->assertCount(1, $roster['members']);
        $this->assertSame(222, $roster['members'][0]['id']);
    }

    public function test_does_not_kick_bots(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $group = Group::create([
            'name' => 'Группа',
            'telegram_chat_id' => '-100888',
            'status' => 'active',
        ]);

        File::put($this->store.'/roster/-100888.json', json_encode([
            'count' => 1,
            'fetched_at' => now()->toIso8601String(),
            'members' => [
                ['id' => 333, 'username' => 'b', 'name' => 'Bot', 'is_bot' => true],
            ],
        ]));

        Http::fake();

        Livewire::actingAs($admin)
            ->test(ZapisiBotDashboard::class)
            ->set('data.group_id', $group->id)
            ->call('kickMember', 333);

        Http::assertNothingSent();
    }
}
