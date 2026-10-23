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
 * 23-10-2026: вопрос открытый, без списка; ответ ищется среди групп; бот не молчит.
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

    public function test_open_question_without_list_and_group_number_gets_link(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->assertSame('sent', $this->ask('Здравствуйте, как оплатить?')['status']);
        $question = $this->lastBotText();
        $this->assertStringContainsString('что хотите оплатить: название курса или группы', $question);
        // 23-10-2026: без списка «на выбор» и без цен.
        $this->assertStringNotContainsString('₽', $question);
        $this->assertStringNotContainsString('1)', $question);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertSame('sent', $this->ask('гр.71')['status']);

        $link = $this->lastBotText();
        $tariff = Tariff::query()->where('course_id', $this->sanskrit->courses()->first()->id)->where('block_number', 2)->first();
        $this->assertStringContainsString('блока 2 группы «Санскрит — гр.71» — 8 000 ₽', $link);
        $this->assertStringContainsString(route('checkout.show', $tariff), $link);
        $this->assertStringContainsString('gsig=', $link);
        $this->assertSame(['pay_clarify', 'pay_choice_link'], $this->sentFactTypes());
    }

    /** @return iterable<string, array{0: string}> */
    public static function payHelpPhrasings(): iterable
    {
        // Пилот 23-10-2026: первая фраза осталась без ответа.
        yield 'оплатить, подскажите как' => ['Хочу оплатить, подскажите как'];
        yield 'хочу оплатить' => ['Здравствуйте! Хочу оплатить следующий блок'];
        yield 'нужно заплатить' => ['Мне нужно заплатить, куда?'];
        yield 'как оплатить' => ['Здравствуйте, как оплатить?'];
    }

    /** @dataProvider payHelpPhrasings */
    public function test_pay_help_phrasings_get_question(string $text): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->assertSame('sent', $this->ask($text)['status']);
        $this->assertStringContainsString('что хотите оплатить', $this->lastBotText());
    }

    public function test_question_closed_before_outcome_marks_does_not_block_new_one(): void
    {
        // Пилот 23-10-2026: вчерашний вопрос закрыт кодом до #3439 (без outcome).
        $this->joinWithFirstBlockPaid($this->sanskrit);
        $this->ask('Здравствуйте, как оплатить?');
        Carbon::setTestNow(now()->addMinutes(3));
        $this->ask('гр.71');

        $event = SupportAiReplyEvent::query()->get()->first(fn ($e) => ($e->meta['fact_type'] ?? null) === 'pay_clarify');
        $meta = $event->meta;
        unset($meta['outcome']);
        $event->forceFill(['meta' => $meta])->save();

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame('sent', $this->ask('Здравствуйте, как оплатить?')['status']);
        $this->assertStringContainsString('что хотите оплатить', $this->lastBotText());
    }

    public function test_day_matching_several_groups_asks_which_then_link(): void
    {
        $sankhya = $this->groupWithCourse('Философия санкхьи', 'Санкхья — пн 18:00', 6000);
        $this->groupWithCourse('Традиции толкования упанишад', 'Упанишады — пн 20:00', 7000);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('давайте тот, что по понедельникам');

        $which = $this->lastBotText();
        $this->assertStringContainsString('Нашёл несколько подходящих групп', $which);
        $this->assertStringContainsString('«Санкхья — пн 18:00»', $which);
        $this->assertStringContainsString('«Упанишады — пн 20:00»', $which);
        $this->assertStringNotContainsString('Хинди', $which);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('санкхья');

        $this->assertStringContainsString('блока 1 группы «Санкхья — пн 18:00» — 6 000 ₽', $this->lastBotText());
        $this->assertSame($sankhya->id, SupportAiReplyEvent::query()->get()->last(fn ($e) => ($e->meta['fact_type'] ?? null) === 'pay_choice_link')->meta['group_id']);
    }

    public function test_pilot_replay_monday_lists_open_groups_skips_paid_and_understands_other(): void
    {
        // Скриншот 23-10-2026: «группа по понедельникам» → бот взял свою
        // оплаченную санкхью; «нет, другая по грамматике» — повторил вопрос.
        $sankhya = $this->groupWithCourse('Философия санкхьи', 'Философия санкхьи — пн 18:00 (2026)', 6000);
        $hindi = $this->groupWithCourse('Грамматика хинди гр. 5', 'Грамматика хинди гр. 5, пн', 5000);
        $this->user->activeGroups()->attach([$sankhya->id, $hindi->id]);
        Payment::create(['user_id' => $this->user->id, 'course_id' => $sankhya->courses()->first()->id, 'amount' => 1, 'tariff' => 'full', 'status' => 'paid']);
        $this->groupWithCourse('Грамматика по Кочергиной гр.61', 'Грамматика по Кочергиной гр.61, пн 08:00', 8000);
        $this->groupWithCourse('Традиции толкования упанишад', 'Упанишады — пн 20:00', 7000);

        $this->ask('Хочу оплатить, подскажите как');
        Carbon::setTestNow(now()->addMinutes(1));
        $this->ask('группа по понедельникам');

        $which = $this->lastBotText();
        $this->assertStringContainsString('гр.61', $which);
        $this->assertStringContainsString('Упанишады', $which);
        $this->assertStringNotContainsString('санкхьи', $which);
        $this->assertStringNotContainsString('хинди', $which);

        Carbon::setTestNow(now()->addMinutes(1));
        $this->ask('нет, другая по грамматике');

        $this->assertStringContainsString('блока 1 группы «Грамматика по Кочергиной гр.61, пн 08:00» — 8 000 ₽', $this->lastBotText());
    }

    public function test_fully_paid_course_named_says_so_instead_of_asking_block(): void
    {
        $sankhya = $this->groupWithCourse('Философия санкхьи', 'Философия санкхьи — пн 18:00 (2026)', 6000);
        $this->user->activeGroups()->attach($sankhya->id);
        Payment::create(['user_id' => $this->user->id, 'course_id' => $sankhya->courses()->first()->id, 'amount' => 1, 'tariff' => 'full', 'status' => 'paid']);

        $this->ask('как оплатить санкхью?');

        $this->assertStringContainsString('у вас уже оплачен полностью', $this->lastBotText());
        $this->assertStringNotContainsString('Какой блок', $this->lastBotText());
    }

    public function test_my_group_phrase_searches_only_own_groups(): void
    {
        $own = $this->groupWithCourse('Грамматика по Кочергиной гр.62', 'Грамматика по Кочергиной гр.62, ср 20:00', 6000);
        $this->groupWithCourse('Грамматика хинди гр. 1', 'Грамматика хинди гр. 1, ср 08:00', 5000);
        $this->groupWithCourse('Продленка санскрита', 'Продленка санскрита ср 19:00', 4000);
        $this->user->activeGroups()->attach($own->id);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(1));
        $this->ask('ну ту, где я по средам занимаюсь');

        $this->assertStringContainsString('блока 1 группы «Грамматика по Кочергиной гр.62, ср 20:00»', $this->lastBotText());
    }

    public function test_ordinal_answer_to_which_question(): void
    {
        $this->groupWithCourse('Философия санкхьи', 'Санкхья — пн 18:00', 6000);
        $this->groupWithCourse('Традиции толкования упанишад', 'Упанишады — пн 20:00', 7000);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('в понедельник');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('вторая');

        $this->assertStringContainsString('группы «Упанишады — пн 20:00» — 7 000 ₽', $this->lastBotText());
    }

    public function test_whole_course_without_full_tariff_asks_block_then_number_gets_link(): void
    {
        $this->groupWithCourse('Философия санкхьи', 'Санкхья — пн 18:00', 6000);

        $this->ask('не могу оплатить, философия санкхья весь курс');

        $question = $this->lastBotText();
        $this->assertStringContainsString('Весь курс сразу здесь оплатить нельзя', $question);
        $this->assertStringContainsString('Какой блок «Санкхья — пн 18:00» оплатить?', $question);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('2');

        $this->assertStringContainsString('блока 2 группы «Санкхья — пн 18:00» — 6 000 ₽', $this->lastBotText());
    }

    public function test_paid_block_named_gets_block_question_with_note(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить 1 блок гр.71?');

        $this->assertStringContainsString('Блок 1 у вас уже оплачен.', $this->lastBotText());
        $this->assertSame(['pay_clarify'], $this->sentFactTypes());
    }

    public function test_answer_with_course_and_block_words(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как можно оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('гр.70, второй блок');

        $this->assertStringContainsString('блока 2 группы «Хинди — гр.70» — 5 000 ₽', $this->lastBotText());
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

    public function test_bot_never_goes_silent_and_hands_over_after_three_questions(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        foreach (range(1, 3) as $i) {
            Carbon::setTestNow(now()->addMinutes(2));
            $this->ask('тот, что дешевле по цене');
        }

        $this->assertSame(['pay_clarify', 'pay_clarify', 'pay_clarify', 'pay_handoff'], $this->sentFactTypes());
        $this->assertStringContainsString('Передаю ваш вопрос куратору', $this->lastBotText());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'несколько раз уточнял'));
    }

    public function test_after_link_follow_up_without_pay_word_and_new_question_work(): void
    {
        $this->joinWithFirstBlockPaid($this->hindi);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(1));
        $this->ask('гр.71 блок 2');
        $this->assertStringContainsString('блока 2 группы «Санскрит — гр.71»', $this->lastBotText());

        // Прод 22-10-2026: «философия санкхья 2 блок» вслед за разговором об оплате.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('хинди гр.70 2 блок');
        $this->assertStringContainsString('блока 2 группы «Хинди — гр.70»', $this->lastBotText());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('а как оплатить ещё раз?');
        $this->assertStringContainsString('что хотите оплатить', $this->lastBotText());
    }

    public function test_group_mentioned_after_link_without_pay_word_gets_no_link(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Как оплатить 2 блок гр.71?');
        Carbon::setTestNow(now()->addMinutes(5));
        $this->ask('а в гр.71 когда следующее занятие?');

        $this->assertSame(['pay_choice_link'], $this->sentFactTypes());
    }

    public function test_whole_course_gets_full_tariff_link(): void
    {
        $course = $this->sanskrit->courses()->first();
        $full = Tariff::factory()->for($course)->create(['price' => 30000]);

        $this->ask('Как оплатить весь курс гр.71?');

        $this->assertStringContainsString('всего курса группы «Санскрит — гр.71» — 30 000 ₽', $this->lastBotText());
        $this->assertStringContainsString(route('checkout.show', $full), $this->lastBotText());
    }

    public function test_unrelated_reply_leaves_question_open(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('а занятие во сколько будет?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('гр.71');

        $this->assertStringContainsString('Ссылка на оплату блока 2', $this->lastBotText());
    }

    public function test_payment_problem_alerts_curator_and_link_has_note(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('Не получается оплатить через личный кабинет');
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Проблема с оплатой'));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('гр.71');
        $this->assertStringContainsString('снимок экрана', $this->lastBotText());
    }

    public function test_installment_in_pay_talk_is_handed_over_not_silent(): void
    {
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('дороговато, можно в рассрочку?');

        $this->assertStringContainsString('Передаю ваш вопрос куратору', $this->lastBotText());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'студенту нужен человек'));
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

    public function test_pilot_test_chat_is_not_stopped_by_auto_send_ceiling(): void
    {
        config([
            'features.enroll_pilot_telegram_ids' => [(string) self::TG],
            'services.telegram_support.sufler_max_steps_per_ticket' => 1,
        ]);
        $this->joinWithFirstBlockPaid($this->sanskrit);

        $this->ask('как оплатить?');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ask('гр.71');

        $this->assertSame(['pay_clarify', 'pay_choice_link'], $this->sentFactTypes());
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
