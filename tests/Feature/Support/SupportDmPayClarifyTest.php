<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Jobs\SendGroupChatInviteJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\SupportAiReplyEvent;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Как оплатить?» → бот уточняет, что и какой блок → ссылка на оплату (22-10-2026).
 */
class SupportDmPayClarifyTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 571472245;

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    private User $user;

    private Group $hindi;

    private Group $sanskrit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 12:00:00');
        Queue::fake();
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_pay_clarify' => true,
            'features.support_enroll_pay_link' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        $this->user = User::factory()->create();
        $this->hindi = $this->groupWithCourse('Хинди гр.70', 'Хинди — гр.70', 5000);
        $this->sanskrit = $this->groupWithCourse('Санскрит гр.71', 'Санскрит — гр.71', 8000);

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

    private function groupWithCourse(string $courseTitle, string $groupName, int $price): Group
    {
        $course = Course::factory()->create(['title' => $courseTitle, 'is_active' => true]);
        Tariff::factory()->for($course)->block(1)->create(['price' => $price]);
        Tariff::factory()->for($course)->block(2)->create(['price' => $price]);
        $group = Group::create(['name' => $groupName, 'status' => 'forming']);
        $course->groups()->attach($group->id);

        return $group;
    }

    private function joinWithFirstBlockPaid(Group $group): void
    {
        $this->user->activeGroups()->attach($group->id);
        Payment::create([
            'user_id' => $this->user->id,
            'course_id' => $group->courses()->first()->id,
            'amount' => 1,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);
    }

    private function incoming(string $text, ?int $contactId = null): TelegramSupportMessage
    {
        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_support_contact_id' => $contactId,
            'telegram_chat_id' => self::TG,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    private function ask(string $text, ?int $linkedUserId = -1, ?int $contactId = null): array
    {
        return app(SupportDmAutoReply::class)->handle(
            $this->incoming($text, $contactId),
            $linkedUserId === -1 ? $this->user->id : $linkedUserId,
            'private',
        );
    }

    private function lastBotText(): string
    {
        return (string) TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('responder_type', 'ai')
            ->orderByDesc('id')
            ->value('text');
    }

    private function botMessages(): int
    {
        return TelegramSupportMessage::query()->where('direction', 'outgoing')->where('responder_type', 'ai')->count();
    }

    private function sentFactTypes(): array
    {
        return SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->orderBy('id')->get()->map(fn ($e) => $e->meta['fact_type'] ?? null)->all();
    }

    public function test_question_lists_options_and_number_answer_gets_link(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->assertSame('sent', $this->ask('Здравствуйте, как оплатить?')['status']);
        $question = $this->lastBotText();
        $this->assertStringContainsString('1) «Хинди — гр.70» — блок 2, 5 000 ₽', $question);
        $this->assertStringContainsString('2) «Санскрит — гр.71» — блок 2, 8 000 ₽', $question);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertSame('sent', $this->ask('2')['status']);

        $link = $this->lastBotText();
        $tariff = Tariff::query()->where('course_id', $this->sanskrit->courses()->first()->id)->where('block_number', 2)->first();
        $this->assertStringContainsString('блока 2 группы «Санскрит — гр.71» — 8 000 ₽', $link);
        $this->assertStringContainsString(route('checkout.show', $tariff), $link);
        $this->assertStringContainsString('gsig=', $link);
        $this->assertSame(['pay_clarify', 'pay_choice_link'], $this->sentFactTypes());
    }

    public function test_single_option_still_asks_and_yes_gets_link(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Пришлите ссылку на оплату');
        $this->assertStringContainsString('1) «Санскрит — гр.71» — блок 2', $this->lastBotText());
        $this->assertSame(1, $this->botMessages());

        Carbon::setTestNow(now()->addMinutes(3));
        $this->ask('да');

        $this->assertStringContainsString('Ссылка на оплату блока 2', $this->lastBotText());
    }

    public function test_answer_with_course_and_block_words(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как можно оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('гр.70, первый блок');

        $this->assertStringContainsString('блока 1 группы «Хинди — гр.70» — 5 000 ₽', $this->lastBotText());
    }

    public function test_student_named_group_and_block_gets_link_without_question(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Как оплатить 2 блок гр.71?');

        $this->assertSame(['pay_choice_link'], $this->sentFactTypes());
        $this->assertStringContainsString('блока 2 группы «Санскрит — гр.71»', $this->lastBotText());
    }

    public function test_unlinked_contact_is_asked_and_gets_signed_link(): void
    {
        $this->chat->forceFill(['linked_user_id' => null])->save();
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => self::TG,
            'telegram_support_chat_id' => $this->chat->id,
            'name' => 'Новая студентка',
        ]);

        $this->ask('Не могу понять, как оплатить курс', null, $contact->id);
        $this->assertStringContainsString('номер группы или день и время', $this->lastBotText());

        Carbon::setTestNow(now()->addMinutes(4));
        $this->ask('гр.71, первый блок', null, $contact->id);

        $link = $this->lastBotText();
        $this->assertStringContainsString('блока 1 группы «Санскрит — гр.71» — 8 000 ₽', $link);
        $this->assertStringContainsString('tgc='.$contact->id, $link);
    }

    public function test_unparsed_money_answer_goes_to_curator_without_second_question(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('тот, что дешевле по цене');

        $this->assertSame(['pay_clarify'], $this->sentFactTypes());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'ответ не разобрал'));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('как оплатить?');
        $this->assertSame(1, $this->botMessages());
    }

    public function test_unrelated_reply_leaves_question_open(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('а занятие во сколько будет?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('1');

        $this->assertStringContainsString('Ссылка на оплату блока 2', $this->lastBotText());
    }

    public function test_payment_problem_alerts_curator_and_link_has_note(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Не получается оплатить через личный кабинет');
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Проблема с оплатой'));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('1');
        $this->assertStringContainsString('снимок экрана', $this->lastBotText());
    }

    public function test_reports_refunds_installments_and_flag_off_stay_with_humans(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Я оплатила, как теперь попасть на урок?');
        $this->ask('Можно ли оплатить в рассрочку?');
        $this->ask('Как оплатить из-за рубежа?');

        config(['features.support_pay_clarify' => false, 'features.support_enroll_pay_link' => false]);
        $this->ask('как оплатить?');

        $this->assertSame([], $this->sentFactTypes());
    }

    public function test_pilot_list_excludes_other_students(): void
    {
        config(['features.enroll_pilot_telegram_ids' => ['908572509']]);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');

        $this->assertSame([], $this->sentFactTypes());
    }

    public function test_human_already_answering_gets_hint_not_question(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => self::TG,
            'telegram_message_id' => 42,
            'direction' => 'outgoing',
            'responder_type' => 'human',
            'text' => 'Сейчас пришлю',
            'sent_at' => now()->subMinutes(5),
        ]);

        $this->ask('как оплатить?');

        $this->assertSame(0, $this->botMessages());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Студент спрашивает, как оплатить'));
    }

    public function test_continuing_student_gets_no_second_chat_invite(): void
    {
        config(['features.paid_chat_join_request' => true]);
        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        $this->sanskrit->forceFill(['telegram_chat_id' => '-1001234567890'])->save();
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $payment = Payment::create([
            'user_id' => $this->user->id,
            'course_id' => $this->sanskrit->courses()->first()->id,
            'group_id' => $this->sanskrit->id,
            'amount' => 8000,
            'tariff' => 'block_2',
            'status' => 'paid',
        ]);

        app()->call([new SendGroupChatInviteJob($payment->id), 'handle']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createChatInviteLink'));
        $this->assertNull($payment->fresh()->chat_invite_sent_at);
    }
}
