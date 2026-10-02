<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\Group;
use App\Models\SupportResponderMapping;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\SupportQuestions\QuestionPopulationResolver;
use App\Services\SupportQuestions\QuestionStudentAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5709 — резолв популяций: группы через контакт (не chat.linked_user_id),
 * штатные роли → staff_internal, незалинкованные ЛС → enquiry, тех-группы →
 * штаб, неопознанные → unknown, боты/пустые → исключения.
 */
class QuestionPopulationResolverTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = TelegramSupportAccount::create(['name' => 'support', 'is_enabled' => true]);
    }

    private function makeMessage(
        TelegramSupportChat $chat,
        ?TelegramSupportContact $contact,
        string $text,
    ): TelegramSupportMessage {
        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact?->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => random_int(100000, 999999),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ])->refresh()->loadMissing('chat');
    }

    /** Группа с linked_user_id ≠ автор: резолв идёт через контакт, не через чат. */
    public function test_group_sender_resolved_via_contact_not_chat_link(): void
    {
        $chatOwner = User::factory()->create();
        $actualSender = User::factory()->create();
        // Студент-отправитель: членство в группе делает его подтверждённым студентом.
        Group::create(['name' => 'Курс', 'slug' => 'kurs-1']);
        DB::table('group_user')->insert([
            'group_id' => 1,
            'user_id' => $actualSender->id,
            'left_at' => null,
        ]);

        $group = TelegramSupportChat::create([
            'telegram_chat_id' => -100111,
            'type' => 'supergroup',
            'linked_user_id' => $chatOwner->id, // «последний отправитель» — ловушка
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 42,
            'telegram_support_chat_id' => $group->id,
            'linked_user_id' => $actualSender->id,
        ]);

        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($group, $contact, 'когда будет запись?')]),
            app(QuestionStudentAuthority::class),
        );

        $message = TelegramSupportMessage::latest('id')->first();
        $verdict = $resolver->resolve($message->loadMissing('chat'));

        $this->assertSame('student', $verdict['population']);
        $this->assertNull($verdict['exclusion_reason']);
    }

    public function test_private_linked_confirmed_student_is_student(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G', 'slug' => 'g-1']);
        DB::table('group_user')->insert([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'left_at' => null,
        ]);

        [$chat, $contact] = $this->privateChat($user->id);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, 'подскажите по материалам')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('student', $verdict['population']);
    }

    public function test_private_linked_but_not_confirmed_is_enquiry(): void
    {
        $user = User::factory()->create(); // ни группы, ни платежа

        [$chat, $contact] = $this->privateChat($user->id);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, 'сколько стоит курс?')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('enquiry', $verdict['population']);
    }

    public function test_private_unlinked_is_enquiry(): void
    {
        [$chat, $contact] = $this->privateChat(null);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, 'как попасть на занятие?')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('enquiry', $verdict['population']);
    }

    public function test_staff_user_dm_is_staff_internal(): void
    {
        $curator = User::factory()->create(['role' => 'teacher']);

        [$chat, $contact] = $this->privateChat($curator->id);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, 'что ответить студенту?')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('staff_internal', $verdict['population']);
    }

    /** Тех-группа из allowlist: вся переписка — штаб, независимо от автора. */
    public function test_tech_group_is_staff_internal_even_for_unlinked_sender(): void
    {
        config(['services.telegram_support.tech_group_peers' => ['-100999']]);

        $tech = TelegramSupportChat::create(['telegram_chat_id' => -100999, 'type' => 'supergroup']);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 77,
            'telegram_support_chat_id' => $tech->id,
            'linked_user_id' => null,
        ]);

        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($tech, $contact, 'спрашивает студент, где ссылка')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('staff_internal', $verdict['population']);
    }

    /** Учебная группа, отправитель без линка — честный unknown. */
    public function test_class_group_unlinked_sender_is_unknown(): void
    {
        $group = TelegramSupportChat::create(['telegram_chat_id' => -100222, 'type' => 'supergroup']);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 88,
            'telegram_support_chat_id' => $group->id,
            'linked_user_id' => null,
        ]);

        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($group, $contact, 'когда занятие?')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('unknown', $verdict['population']);
    }

    public function test_bot_sender_is_excluded(): void
    {
        config(['services.telegram_support.bot_usernames' => ['samskrte_bot']]);

        [$chat] = $this->privateChat(null);
        $bot = TelegramSupportContact::create([
            'telegram_user_id' => 999,
            'telegram_support_chat_id' => $chat->id,
            'username' => 'samskrte_bot',
        ]);

        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $bot, 'Автоотчёт недели')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('bot', $verdict['exclusion_reason']);
    }

    public function test_empty_text_is_service_message_exclusion(): void
    {
        [$chat, $contact] = $this->privateChat(null);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, '')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('service_message', $verdict['exclusion_reason']);
    }

    /** Responder-mapping тоже делает отправителя штатным. */
    public function test_responder_mapping_marks_staff(): void
    {
        SupportResponderMapping::create([
            'marker_label' => 'support-op',
            'user_id' => null,
            'responder_type' => 'human',
            'is_active' => true,
        ]);
        $operator = User::factory()->create();
        SupportResponderMapping::create([
            'marker_label' => 'op-'.$operator->id,
            'user_id' => $operator->id,
            'responder_type' => 'human',
            'is_active' => true,
        ]);

        [$chat, $contact] = $this->privateChat($operator->id);
        $resolver = QuestionPopulationResolver::forMessages(
            collect([$this->makeMessage($chat, $contact, 'кто оплатил?')]),
            app(QuestionStudentAuthority::class),
        );
        $verdict = $resolver->resolve(TelegramSupportMessage::latest('id')->first()->loadMissing('chat'));

        $this->assertSame('staff_internal', $verdict['population']);
    }

    /**
     * @return array{0: TelegramSupportChat, 1: TelegramSupportContact}
     */
    private function privateChat(?int $linkedUserId): array
    {
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => random_int(1000, 9999),
            'type' => 'private',
            'linked_user_id' => $linkedUserId,
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => random_int(10000, 99999),
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $linkedUserId,
        ]);

        return [$chat, $contact];
    }
}
