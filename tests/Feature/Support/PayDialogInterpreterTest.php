<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\SupportAiReplyEvent;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Разговор об оплате понимает локальная модель (22-10-2026): она выбирает
 * вариант из каталога, а сумму и ссылку собирает код. Сбой/выдумка модели —
 * прежний путь на регулярках.
 */
class PayDialogInterpreterTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 571472245;

    private const OLLAMA = 'http://ollama.test';

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    private User $user;

    private Group $monday;

    private Group $saturday;

    /** @var list<array<string, mixed>|string|\Closure> */
    private array $llmReplies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 12:00:00');
        Queue::fake();
        Mail::fake();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_pay_clarify' => true,
            'features.support_pay_dialog_llm' => 'live',
            'knowledge.generation_base_url' => self::OLLAMA,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::OLLAMA)) {
                $reply = array_shift($this->llmReplies) ?? ['intent' => 'other', 'confidence' => 0.9];
                if ($reply instanceof \Closure) {
                    return $reply();
                }

                return Http::response(['choices' => [['message' => ['content' => is_string($reply) ? $reply : json_encode($reply)]]]]);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });

        $this->user = User::factory()->create();
        $this->monday = $this->group('Философия санкхьи', 'Санкхья — пн 18:00');
        $this->saturday = $this->group('Санскрит с нуля', 'Санскрит — сб 11:00');

        $this->account = TelegramSupportAccount::create(['name' => 'telegram-business', 'auto_reply_enabled' => true]);
        $this->chat = TelegramSupportChat::create([
            'telegram_chat_id' => self::TG,
            'type' => 'private',
            'linked_user_id' => $this->user->id,
            'last_message_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function group(string $courseTitle, string $name): Group
    {
        $course = Course::factory()->create(['title' => $courseTitle, 'is_active' => true]);
        Tariff::factory()->for($course)->block(1)->create(['price' => 6000]);
        Tariff::factory()->for($course)->block(2)->create(['price' => 6000]);
        $group = Group::create(['name' => $name, 'status' => 'forming']);
        $course->groups()->attach($group->id);

        return $group;
    }

    private function ask(string $text): array
    {
        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => self::TG,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');
        Carbon::setTestNow(now()->addMinutes(2));

        return $result;
    }

    private function lastBotText(): string
    {
        return (string) TelegramSupportMessage::query()->where('direction', 'outgoing')
            ->where('responder_type', 'ai')->orderByDesc('id')->value('text');
    }

    private function botMessages(): int
    {
        return TelegramSupportMessage::query()->where('direction', 'outgoing')->where('responder_type', 'ai')->count();
    }

    private function ollamaCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::OLLAMA))->count();
    }

    public function test_context_answer_picks_monday_group_from_catalog(): void
    {
        $this->llmReplies = [
            ['intent' => 'pay', 'option' => null, 'block' => null, 'missing' => 'what', 'confidence' => 0.9],
            ['intent' => 'enroll', 'option' => 'g'.$this->monday->id, 'block' => 1, 'missing' => null, 'confidence' => 0.92],
        ];

        $this->ask('как оплатить?');
        $this->assertStringContainsString('Подскажите, пожалуйста, что хотите оплатить', $this->lastBotText());

        $this->ask('давайте тот, что по понедельникам');

        $link = $this->lastBotText();
        $this->assertStringContainsString('блока 1 группы «Санкхья — пн 18:00» — 6 000 ₽', $link);
        $this->assertStringContainsString('gsig=', $link);

        // Модель видела и каталог, и реплику бота в переписке.
        $prompt = (string) Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::OLLAMA))->last()[0]['messages'][1]['content'];
        $this->assertStringContainsString('g'.$this->monday->id.': группа «Санкхья — пн 18:00»', $prompt);
        $this->assertStringContainsString('бот] Намасте!', $prompt);
        $this->assertStringContainsString('студент] давайте тот, что по понедельникам', $prompt);
    }

    public function test_fresh_question_asks_even_if_model_guesses_from_history(): void
    {
        // Пилот 23-10-2026: на «хочу оплатить» модель взяла вчерашнюю группу.
        $this->llmReplies = [['intent' => 'pay', 'option' => 'g'.$this->monday->id, 'block' => 1, 'confidence' => 0.9]];

        $this->ask('Хочу оплатить, подскажите как');

        $this->assertStringContainsString('Подскажите, пожалуйста, что хотите оплатить', $this->lastBotText());
        $this->assertSame(1, $this->botMessages());
    }

    public function test_fresh_message_naming_the_group_gets_link_from_model(): void
    {
        $this->llmReplies = [['intent' => 'pay', 'option' => 'g'.$this->monday->id, 'block' => 2, 'confidence' => 0.9]];

        $this->ask('хочу оплатить философию санкхьи, 2 блок');

        $this->assertStringContainsString('блока 2 группы «Санкхья — пн 18:00»', $this->lastBotText());
    }

    public function test_whole_course_from_context(): void
    {
        $course = $this->saturday->courses()->first();
        Tariff::factory()->for($course)->create(['price' => 20000]);
        $this->llmReplies = [['intent' => 'pay', 'option' => 'g'.$this->saturday->id, 'block' => 'full', 'confidence' => 0.85]];

        $this->ask('а можно оплатить сразу всё за субботнюю?');

        $this->assertStringContainsString('всего курса группы «Санскрит — сб 11:00» — 20 000 ₽', $this->lastBotText());
    }

    public function test_human_topic_goes_to_curator(): void
    {
        $this->llmReplies = [['intent' => 'human', 'confidence' => 0.9]];

        $this->ask('мне дорого, можно как-то оплатить поменьше?');

        $this->assertSame(0, $this->botMessages());
        Http::assertSent(fn (Request $r) => str_contains((string) ($r['text'] ?? ''), 'студенту нужен человек'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function untrustworthyReplies(): iterable
    {
        yield 'выдуманный вариант' => [['intent' => 'human', 'option' => 'g999', 'block' => 1, 'confidence' => 0.95]];
        yield 'низкая уверенность' => [['intent' => 'human', 'confidence' => 0.3]];
        yield 'не JSON' => ['Думаю, студент хочет оплатить.'];
        yield 'неизвестный intent' => [['intent' => 'refund', 'confidence' => 0.99]];
    }

    /** @dataProvider untrustworthyReplies */
    public function test_untrustworthy_model_reply_falls_back_to_rules(mixed $reply): void
    {
        $this->llmReplies = [$reply];

        $this->ask('как оплатить?');

        $this->assertSame(1, $this->ollamaCalls());
        $this->assertStringContainsString('Подскажите, пожалуйста, что хотите оплатить', $this->lastBotText());
    }

    public function test_paid_block_chosen_by_model_falls_back_to_rules(): void
    {
        $this->user->activeGroups()->attach($this->monday->id);
        Payment::create([
            'user_id' => $this->user->id,
            'course_id' => $this->monday->courses()->first()->id,
            'amount' => 6000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);
        $this->llmReplies = [['intent' => 'pay', 'option' => 'g'.$this->monday->id, 'block' => 1, 'confidence' => 0.9]];

        $this->ask('как оплатить?');

        $this->assertStringContainsString('«Санкхья — пн 18:00» — блок 2', $this->lastBotText());
    }

    public function test_node_down_falls_back_to_rules(): void
    {
        $this->llmReplies = [fn () => throw new ConnectionException('tunnel down')];

        $this->ask('как оплатить?');

        $this->assertStringContainsString('Подскажите, пожалуйста, что хотите оплатить', $this->lastBotText());
    }

    public function test_shadow_answers_by_rules_and_records_both_decisions(): void
    {
        config(['features.support_pay_dialog_llm' => 'shadow']);
        $this->llmReplies = [['intent' => 'human', 'confidence' => 0.9]];

        $this->ask('как оплатить?');

        $this->assertStringContainsString('Подскажите, пожалуйста, что хотите оплатить', $this->lastBotText());
        $event = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_PAY_LLM_SHADOW)->firstOrFail();
        $this->assertSame('human', $event->meta['llm']['intent']);
        $this->assertSame('sent', $event->meta['rules_status']);
    }

    public function test_reports_and_refunds_never_reach_the_model(): void
    {
        $this->ask('Я оплатила вчера, всё ок?');
        $this->ask('Верните деньги за второй блок');
        $this->ask('Спасибо!');

        $this->assertSame(0, $this->ollamaCalls());
    }

    public function test_flag_off_never_calls_model(): void
    {
        config(['features.support_pay_dialog_llm' => 'off']);

        $this->ask('как оплатить?');

        $this->assertSame(0, $this->ollamaCalls());
        $this->assertSame(1, $this->botMessages());
    }
}
