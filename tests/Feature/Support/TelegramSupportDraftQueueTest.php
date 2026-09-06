<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\TelegramSupportDraftQueue;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3999 (рулинг I1b): очередь черновиков — единственный путь отправки для
 * черновиков draft_only (деньги, доступ, сертификат), у которых кнопки в
 * Telegram нет вовсе.
 *
 * Отправка идёт тем же {@see \App\Services\Support\SupportHintSendButton::deliver()},
 * что и кнопка: тест это и проверяет — по одному исходящему на черновик,
 * повторное нажатие ничего не добавляет.
 */
class TelegramSupportDraftQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        config([
            'features.support_draft_queue' => true,
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: SupportAnswerSuggestion, 1: User} */
    private function pendingDraft(string $text = 'К оплате остаётся 12 000 ₽.'): array
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $student = User::factory()->create(['name' => 'Студент Тест']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9701],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9701,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'какой у меня остаток по оплате?',
            'sent_at' => now(),
        ]);

        $draft = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
            'source_id' => $incoming->id,
            'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT,
            'draft_text' => $text,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => [
                'kind' => 'facts',
                'fact_type' => 'balance',
                'send_policy' => 'draft_only',
            ],
        ]);

        return [$draft, $student];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    public function test_a_teacher_cannot_open_the_queue(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(TelegramSupportDraftQueue::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(TelegramSupportDraftQueue::canAccess());
    }

    public function test_the_flag_hides_the_page_entirely(): void
    {
        config(['features.support_draft_queue' => false]);

        $this->actingAs($this->admin());

        $this->assertFalse(TelegramSupportDraftQueue::canAccess());
        $this->assertFalse(TelegramSupportDraftQueue::shouldRegisterNavigation());
    }

    public function test_the_queue_shows_the_pending_draft_with_the_student_question(): void
    {
        [$draft] = $this->pendingDraft();

        Livewire::actingAs($this->admin())
            ->test(TelegramSupportDraftQueue::class)
            ->assertSee('К оплате остаётся')
            ->assertSee('какой у меня остаток по оплате?')
            ->assertSee('требует проверки');

        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $draft->fresh()->status);
    }

    public function test_send_delivers_once_and_a_second_press_adds_nothing(): void
    {
        [$draft, $student] = $this->pendingDraft();

        $page = Livewire::actingAs($this->admin())->test(TelegramSupportDraftQueue::class);
        $page->call('send', $draft->id);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertStringContainsString('К оплате остаётся', (string) $outgoing->first()->text);
        $this->assertSame($student->id, (int) $draft->fresh()->user_id);
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $draft->fresh()->status);

        $this->assertSame(
            1,
            SupportAiReplyEvent::query()
                ->where('event_type', TelegramSupportDraftQueue::EVENT_QUEUE_SENT)
                ->count(),
        );

        // Черновик уже не pending — повторное нажатие не находит его вовсе.
        $page->call('send', $draft->id);

        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_edit_keeps_the_draft_sendable_and_sends_the_edited_text(): void
    {
        [$draft] = $this->pendingDraft();

        $page = Livewire::actingAs($this->admin())->test(TelegramSupportDraftQueue::class);
        $page->call('startEdit', $draft->id)
            ->assertSet('editingText', 'К оплате остаётся 12 000 ₽.')
            ->set('editingText', 'Остаток по вашему курсу — 12 000 ₽, ссылка на оплату в кабинете.')
            ->call('saveEdit');

        $draft->refresh();
        $this->assertSame(
            SupportAnswerSuggestion::STATUS_PENDING,
            $draft->status,
            'Правка не закрывает черновик — иначе отправить его стало бы нельзя.',
        );
        $this->assertTrue($draft->facts['edited']);

        $page->call('send', $draft->id);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString('ссылка на оплату в кабинете', (string) $outgoing->text);

        // H3999: правленый черновик закрывается как «отредактирован», а не
        // «принят как есть» — иначе неделя точности считала бы правки успехами.
        $this->assertSame(SupportAnswerSuggestion::STATUS_EDITED, $draft->fresh()->status);
    }

    public function test_an_empty_edit_is_refused(): void
    {
        [$draft] = $this->pendingDraft();

        Livewire::actingAs($this->admin())
            ->test(TelegramSupportDraftQueue::class)
            ->call('startEdit', $draft->id)
            ->set('editingText', '   ')
            ->call('saveEdit');

        $this->assertSame('К оплате остаётся 12 000 ₽.', (string) $draft->fresh()->draft_text);
    }

    public function test_skip_closes_the_draft_and_sends_nothing(): void
    {
        [$draft] = $this->pendingDraft();

        Livewire::actingAs($this->admin())
            ->test(TelegramSupportDraftQueue::class)
            ->call('skip', $draft->id);

        $draft->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_DISCARDED, $draft->status);
        $this->assertNotNull($draft->resolved_at);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }
}
