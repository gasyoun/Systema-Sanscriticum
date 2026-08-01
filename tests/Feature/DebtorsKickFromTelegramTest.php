<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H2130: исключение должника из учебного TG-чата с /admin/debtors
 * через @zapisi_ORSbot (ZapisiChatMemberService).
 */
class DebtorsKickFromTelegramTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'kick-course']);
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);

        $this->group = Group::create([
            'name' => 'Вторник 12:00',
            'telegram_chat_id' => '-100STUDENTS',
            'status' => 'active',
        ]);
        $this->course->groups()->attach($this->group->id);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    private function makeDebtor(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'telegram_id' => '424242',
        ], $attrs));
        $user->groups()->attach($this->group->id);

        return $user;
    }

    private function fakeBanOk(): void
    {
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
            if (str_contains($url, 'banChatMember')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => false], 400);
        });
    }

    public function test_row_action_kicks_debtor_from_course_chat(): void
    {
        $this->fakeBanOk();
        $debtor = $this->makeDebtor(['telegram_id' => '555001']);

        Livewire::actingAs($this->admin())
            ->test(Debtors::class)
            ->callTableAction('kick_from_telegram', $debtor, data: [
                'group_ids' => [$this->group->id],
            ])
            ->assertHasNoTableActionErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '555001'
            && (string) ($r->data()['chat_id'] ?? '') === '-100STUDENTS');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'unbanChatMember'));
    }

    public function test_mark_unreliable_can_also_ban_telegram(): void
    {
        $this->fakeBanOk();
        $debtor = $this->makeDebtor(['telegram_id' => '555002', 'is_unreliable' => false]);

        Livewire::actingAs($this->admin())
            ->test(Debtors::class)
            ->callTableAction('mark_unreliable', $debtor, data: [
                'reason' => '3 раза срывал обещания',
                'also_ban_telegram' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($debtor->fresh()->is_unreliable);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '555002');
    }

    public function test_bulk_kick_from_telegram(): void
    {
        $this->fakeBanOk();
        $a = $this->makeDebtor(['telegram_id' => '7001']);
        $b = $this->makeDebtor(['telegram_id' => '7002']);

        Livewire::actingAs($this->admin())
            ->test(Debtors::class)
            ->callTableBulkAction('kick_from_telegram', [$a->getKey(), $b->getKey()])
            ->assertHasNoTableActionErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '7001');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '7002');
    }
}
