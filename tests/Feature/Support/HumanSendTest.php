<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\HumanSend\HumanSendAuthorization;
use App\Services\Support\HumanSend\SupportHumanSendService;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportHintSendButton;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

use App\Filament\Pages\TelegramSupportDraftQueue;

/**
 * H6183 (пакет hermes-24-01a10bca): проверяемый human-send в TG и web.
 *
 * Инварианты миссии, каждый — отдельным именованным тестом:
 *  1) только аутентифицированное действие человека разрешает send —
 *     агентский вызов отказ без единой записи на ОБОИХ транспортах;
 *  2) повтор approval не отправляет второе сообщение;
 *  3) повтор webhook (callback_query ределивери) не отправляет второе;
 *  4) флаг OFF — web-дорожка выключена и очередь web-черновики не показывает.
 *
 * Доставка «mocked» по построению: сервис пишет только в стор (pending
 * TelegramSupportMessage / ChatMessage), ChatMessageSent глушится Event::fake,
 * Telegram API — Http::fake.
 */
class HumanSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.hermes_human_send' => true,
            'features.support_draft_queue' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    /**
     * @return array{0: SupportAnswerSuggestion, 1: User}
     */
    private function tgDraft(string $text = 'Кнопка подключения в кабинете, раздел «Мои курсы».'): array
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $student = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9801],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9801,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'не могу зайти на занятие',
            'sent_at' => now(),
        ]);

        $draft = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
            'source_id' => $incoming->id,
            'category' => SupportAnswerSuggestion::CATEGORY_ACCESS,
            'draft_text' => $text,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => ['kind' => 'facts', 'fact_type' => 'access'],
        ]);

        return [$draft, $student];
    }

    /**
     * @return array{0: SupportAnswerSuggestion, 1: User, 2: SupportConversation}
     */
    private function webDraft(string $text = 'Занятие идёт по расписанию — в 18:00 МСК.'): array
    {
        $student = User::factory()->create();
        $thread = SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'queue' => SupportConversation::QUEUE_GENERAL,
            'last_message_at' => now(),
        ]);

        $question = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'во сколько сегодня занятие?',
            'is_read' => false,
        ]);

        $draft = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => $question->id,
            'category' => SupportAnswerSuggestion::CATEGORY_SCHEDULE,
            'draft_text' => $text,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => ['kind' => 'facts', 'fact_type' => 'schedule'],
        ]);

        return [$draft, $student, $thread];
    }

    private function curator(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    private function service(): SupportHumanSendService
    {
        return app(SupportHumanSendService::class);
    }

    public function test_agent_call_is_refused_on_both_transports_without_a_single_write(): void
    {
        [$tgDraft] = $this->tgDraft();
        [$webDraft] = $this->webDraft();
        $agent = HumanSendAuthorization::agent();

        $tgResult = $this->service()->send($tgDraft, $agent);
        $webResult = $this->service()->send($webDraft, $agent);

        $this->assertSame('refused_agent', $tgResult['status']);
        $this->assertSame('refused_agent', $webResult['status']);

        // Ни единой записи: статусы не тронуты, исходящих нет, событий нет.
        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $tgDraft->fresh()->status);
        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $webDraft->fresh()->status);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, ChatMessage::query()->where('role', 'curator')->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    public function test_web_send_by_authenticated_curator_delivers_once(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$draft, $student, $thread] = $this->webDraft();
        $curator = $this->curator();

        $result = $this->service()->send($draft, HumanSendAuthorization::webUser($curator));

        $this->assertSame('sent', $result['status']);

        $outgoing = ChatMessage::query()->where('role', 'curator')->get();
        $this->assertCount(1, $outgoing);
        $this->assertSame($thread->id, (int) $outgoing->first()->support_conversation_id);
        $this->assertSame($curator->id, (int) $outgoing->first()->answered_by);
        $this->assertSame($student->id, (int) $outgoing->first()->user_id);
        $this->assertSame('Занятие идёт по расписанию — в 18:00 МСК.', (string) $outgoing->first()->text);

        Event::assertDispatchedTimes(ChatMessageSent::class, 1);

        $draft->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $draft->status);
        $this->assertSame($curator->id, (int) $draft->resolved_by);

        $event = SupportAiReplyEvent::query()
            ->where('event_type', SupportHumanSendService::EVENT_HUMAN_SEND_WEB)
            ->firstOrFail();
        $this->assertSame('web', (string) $event->meta['transport']);
        $this->assertSame('web_user', (string) $event->meta['human']['kind']);
    }

    public function test_repeated_approval_sends_no_second_web_message(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$draft] = $this->webDraft();
        $actor = HumanSendAuthorization::webUser($this->curator());

        $first = $this->service()->send($draft, $actor);
        $second = $this->service()->send($draft, $actor);

        $this->assertSame('sent', $first['status']);
        $this->assertSame('already', $second['status']);

        $this->assertSame(1, ChatMessage::query()->where('role', 'curator')->count());
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()
                ->where('event_type', SupportHumanSendService::EVENT_HUMAN_SEND_WEB)
                ->count(),
        );
        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
    }

    public function test_webhook_replay_sends_exactly_one_tg_message(): void
    {
        [$draft] = $this->tgDraft();

        $account = TelegramSupportAccount::firstOrFail();
        $account->forceFill(['hint_recipients' => ['777001']])->save();

        // Ределивери одного и того же callback_query дважды — второй проход
        // видит уже не-pending черновик и ничего не ставит (плюс дедуп
        // update_id в контроллере, плюс клейм (чат, текст) в deliver()).
        $callback = [
            'id' => 'cb-1',
            'data' => SupportDmAutoReply::SEND_CALLBACK_PREFIX.$draft->id,
            'from' => ['id' => '777001'],
            'message' => ['chat' => ['id' => 777001]],
        ];

        app(SupportHintSendButton::class)->handle($callback);
        app(SupportHintSendButton::class)->handle($callback);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $draft->fresh()->status);
    }

    public function test_tg_draft_via_service_delegates_to_the_single_send_point(): void
    {
        [$draft, $student] = $this->tgDraft();

        $result = $this->service()->send(
            $draft,
            HumanSendAuthorization::telegramTapper('777001', $this->curator()->id),
        );

        $this->assertSame('sent', $result['status']);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertSame($student->id, (int) $draft->fresh()->user_id);

        $event = SupportAiReplyEvent::query()
            ->where('event_type', SupportHumanSendService::EVENT_HUMAN_SEND_TG)
            ->firstOrFail();
        $this->assertSame('telegram', (string) $event->meta['transport']);
        $this->assertSame('telegram_tapper', (string) $event->meta['human']['kind']);

        // Повтор через сервис — уже уже: deliver сам перевёл статус.
        $again = $this->service()->send(
            $draft->fresh(),
            HumanSendAuthorization::telegramTapper('777001', null),
        );
        $this->assertSame('already', $again['status']);
        $this->assertCount(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->get());
    }

    public function test_flag_off_disables_web_send_and_the_queue_hides_web_drafts(): void
    {
        config(['features.hermes_human_send' => false]);

        [$draft] = $this->webDraft();

        $result = $this->service()->send($draft, HumanSendAuthorization::webUser($this->curator()));
        $this->assertSame('disabled', $result['status']);
        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $draft->fresh()->status);
        $this->assertSame(0, ChatMessage::query()->where('role', 'curator')->count());

        Livewire::actingAs($this->curator())
            ->test(TelegramSupportDraftQueue::class)
            ->assertDontSee('во сколько сегодня занятие?');

        config(['features.hermes_human_send' => true]);

        Livewire::actingAs($this->curator())
            ->test(TelegramSupportDraftQueue::class)
            ->assertSee('во сколько сегодня занятие?')
            ->assertSee('web-чат');
    }

    public function test_queue_send_button_routes_web_drafts_through_the_service(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$draft] = $this->webDraft();

        $page = Livewire::actingAs($this->curator())->test(TelegramSupportDraftQueue::class);
        $page->call('send', $draft->id);

        $this->assertSame(1, ChatMessage::query()->where('role', 'curator')->count());
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $draft->fresh()->status);

        // Повторное нажатие «Отправить» ничего не добавляет.
        $page->call('send', $draft->id);
        $this->assertSame(1, ChatMessage::query()->where('role', 'curator')->count());
    }

    public function test_web_send_with_missing_source_refuses_cleanly(): void
    {
        $draft = SupportAnswerSuggestion::create([
            'user_id' => User::factory()->create()->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 999999,
            'category' => SupportAnswerSuggestion::CATEGORY_SCHEDULE,
            'draft_text' => 'Ответ в никуда.',
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => [],
        ]);

        $result = $this->service()->send($draft, HumanSendAuthorization::webUser($this->curator()));

        $this->assertSame('no_source', $result['status']);
        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $draft->fresh()->status);
        $this->assertSame(0, ChatMessage::query()->where('role', 'curator')->count());
    }
}
