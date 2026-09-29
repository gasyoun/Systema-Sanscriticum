<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\SupportConversation;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportReplyService;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 24-09-2026: TG-личка человека без привязки к кабинету видна в Helpdesk
 * («Без привязки») с полем ответа, ищется по номеру чата; висящие чаты
 * догружает support:open-unlinked-dm-threads; бейдж канала не врёт.
 */
class HelpdeskUnlinkedDmThreadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 12:00:00');
        config([
            'support_tech.assignee_user_id' => null,
            'support_tech.keywords' => ['кабинет', 'zoom', 'доступ', 'пароль'],
            'services.telegram_support.enabled' => false,
            'features.support_unified_reply' => false,
            'features.support_unlinked_dm_threads' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $extra */
    private function incoming(int $chatId, int $messageId, string $text, array $extra = []): void
    {
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([array_merge([
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'telegram_user_id' => $chatId,
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now()->toDateTimeString(),
            'chat_type' => 'private',
        ], $extra)]);
    }

    public function test_unlinked_question_in_dm_opens_general_thread_when_flag_on(): void
    {
        $this->incoming(378182251, 1, 'Я бы хотела попасть завтра к Уше на пробное занятие', ['contact_name' => 'Анна']);

        $thread = SupportConversation::query()->whereNull('user_id')->firstOrFail();
        $this->assertSame(378182251, (int) $thread->source_telegram_chat_id);
        $this->assertSame(SupportConversation::QUEUE_GENERAL, $thread->queue);
        $this->assertSame('Анна', $thread->guest_name);
    }

    public function test_flag_off_keeps_old_behaviour(): void
    {
        config(['features.support_unlinked_dm_threads' => false]);

        $this->incoming(378182251, 1, 'Я бы хотела попасть завтра к Уше на пробное занятие');

        $this->assertSame(0, SupportConversation::query()->count());
    }

    public function test_pure_small_talk_and_telegram_service_chat_do_not_open_threads(): void
    {
        $this->incoming(1001, 1, 'Здравствуйте!');
        $this->incoming(1002, 2, 'Спасибо большое 🙏');
        $this->incoming(777000, 3, 'Незавершенная попытка входа в аккаунт');

        $this->assertSame(0, SupportConversation::query()->count());
    }

    public function test_curator_reply_goes_to_the_same_telegram_chat(): void
    {
        Queue::fake();
        $this->incoming(852831804, 7, 'Оплату куда внести??');

        $thread = SupportConversation::query()->whereNull('user_id')->firstOrFail();
        $curator = User::factory()->create(['role' => Roles::ADMIN]);

        $sent = app(SupportReplyService::class)->replyToUnlinkedThread($thread, 'Реквизиты пришлю сейчас', $curator);

        $this->assertNotNull($sent);
        $this->assertSame(852831804, (int) $sent->telegram_chat_id);
        $this->assertSame('outgoing', $sent->direction);
    }

    public function test_helpdesk_lists_thread_with_chat_number_and_finds_it_by_number(): void
    {
        $this->incoming(852831804, 7, 'Оплату куда внести??', ['contact_name' => 'Ольга', 'contact_username' => 'olga_s']);
        TelegramSupportChat::query()->where('telegram_chat_id', 852831804)->update(['username' => 'olga_s']);
        $this->incoming(5111371334, 8, 'оплата за Бюлера блок 10', ['contact_name' => 'Пётр']);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(Helpdesk::class)
            ->assertSee('Без привязки')
            ->assertSee('@olga_s · chat 852831804')
            ->assertSee('chat 5111371334')
            ->set('search', '852831804')
            ->assertSee('Ольга')
            ->assertDontSee('Пётр')
            ->set('search', '@olga')
            ->assertSee('Ольга')
            ->assertDontSee('Пётр')
            ->set('search', 'нет-такого')
            ->assertSee('Ничего не нашлось');
    }

    public function test_search_finds_linked_student_by_name_across_tabs(): void
    {
        $student = User::factory()->create(['name' => 'Кравченко Мария', 'telegram_id' => '5550001']);
        $student->chatMessages()->create(['role' => 'user', 'text' => 'вопрос', 'is_read' => false]);
        $other = User::factory()->create(['name' => 'Иванов Пётр']);
        $other->chatMessages()->create(['role' => 'user', 'text' => 'вопрос', 'is_read' => false]);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $component = Livewire::test(Helpdesk::class)->set('search', 'Кравченко');
        $names = collect($component->get('usersWithChats'))->pluck('name')->all();
        $this->assertSame(['Кравченко Мария'], $names);

        $component->set('search', '5550001');
        $this->assertSame(['Кравченко Мария'], collect($component->get('usersWithChats'))->pluck('name')->all());
    }

    public function test_reply_badge_says_cabinet_when_telegram_conversation_but_unified_reply_off(): void
    {
        $student = User::factory()->create(['telegram_id' => '5002']);
        $this->incoming(5002, 12, 'Когда следующее занятие?');

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('в Telegram не уйдёт')
            ->assertDontSee('🔹 Telegram-support');

        config(['features.support_unified_reply' => true]);

        Livewire::test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->assertSee('Telegram-support');
    }

    public function test_backfill_command_is_dry_by_default_then_opens_threads_idempotently(): void
    {
        // Висящие чаты, пришедшие ДО флага: сообщения есть, тредов нет.
        config(['features.support_unlinked_dm_threads' => false]);
        $this->incoming(852831804, 7, 'Оплату куда внести??');
        $this->incoming(67965555, 9, 'что от меня нужно, чтобы на пробное занятие прийти?');
        // Отвеченный чат (последнее — исходящее) не трогаем.
        $this->incoming(1595934495, 10, 'какие ещё есть группы санскрита?');
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([[
            'telegram_chat_id' => 1595934495,
            'telegram_message_id' => 11,
            'telegram_user_id' => 1595934495,
            'direction' => 'outgoing',
            'text' => 'Есть группа по средам',
            'sent_at' => now()->addMinute()->toDateTimeString(),
            'chat_type' => 'private',
        ]]);
        $this->assertSame(0, SupportConversation::query()->count());

        // Флаг OFF — команда отказывается.
        $this->artisan('support:open-unlinked-dm-threads')->assertFailed();

        config(['features.support_unlinked_dm_threads' => true]);

        $this->artisan('support:open-unlinked-dm-threads')->assertSuccessful();
        $this->assertSame(0, SupportConversation::query()->count());

        $this->artisan('support:open-unlinked-dm-threads', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing(
            [852831804, 67965555],
            SupportConversation::query()->pluck('source_telegram_chat_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertSame(0, TelegramSupportMessage::query()
            ->whereIn('telegram_chat_id', [852831804, 67965555])
            ->where('direction', 'incoming')
            ->whereNull('support_conversation_id')
            ->count());

        $this->artisan('support:open-unlinked-dm-threads', ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, SupportConversation::query()->count());
    }
}
