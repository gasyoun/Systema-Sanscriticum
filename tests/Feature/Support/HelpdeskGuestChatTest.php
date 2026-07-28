<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Events\ChatMessageSent;
use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\SupportAnswerSuggestion;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAnswerSuggester;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H536 Phase 5: гостевые треды веб-чата (user_id = NULL) в операторском Helpdesk.
 * Гость невидим для user-keyed списка — отдельная ветка его выводит, открывает,
 * даёт ответить, и ответ куратора бродкастится обратно в виджет посетителя.
 */
class HelpdeskGuestChatTest extends TestCase
{
    use RefreshDatabase;

    private function guestThreadWithMessage(string $text, ?string $name = null): SupportConversation
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-'.bin2hex(random_bytes(8)),
            'guest_name' => $name,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $message = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => null,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);

        $thread->forceFill(['last_message_at' => $message->created_at])->save();

        return $thread;
    }

    /** @test */
    public function guest_threads_appear_in_the_operator_inbox(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->guestThreadWithMessage('Здравствуйте, вопрос по курсу', 'Аня');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            // Заголовок списка — «Без привязки»: с 28.07.2026 туда же попадают
            // техвопросы из Telegram-чатов от непривязанных авторов.
            ->assertSee('Без привязки')
            ->assertSee('Аня');
    }

    /**
     * Открытие телеграм-треда роняло страницу пятисоткой: лента общая и зовёт на
     * элементах методы модели (htmlForWeb), а сообщения подставлялись голыми
     * объектами. Тест открывает тред целиком — именно этого шага не хватало.
     */
    public function test_opening_a_telegram_thread_renders_its_messages(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $thread = SupportConversation::create([
            'source_telegram_chat_id' => -100556,
            'source_telegram_user_id' => 777,
            'guest_name' => 'Игорь (Хинди гр.2)',
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_support_account_id' => $account->id,
            'telegram_chat_id' => -100556,
            'type' => 'supergroup',
            'title' => 'Хинди гр.2',
        ]);
        TelegramSupportMessage::create([
            'support_conversation_id' => $thread->id,
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => -100556,
            'telegram_message_id' => 9001,
            'direction' => 'incoming',
            'text' => 'Не могу войти в кабинет',
            'sent_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSet('activeGuestId', $thread->id)
            ->assertSee('Не могу войти в кабинет')
            ->assertSee('ответ уйдёт в Telegram-чат');
    }

    /** Техвопрос из Telegram-чата виден в том же списке, с пометкой источника. */
    public function test_unlinked_telegram_thread_appears_in_the_list(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        SupportConversation::create([
            'source_telegram_chat_id' => -100555,
            'source_telegram_user_id' => 4242,
            'guest_name' => 'Ольга (Хинди гр.1)',
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->assertSee('Ольга (Хинди гр.1)')
            ->assertSee('Техника');
    }

    /** @test */
    public function anonymous_guest_without_a_name_is_labelled_by_id(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $thread = $this->guestThreadWithMessage('Аноним пишет');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->assertSee('Гость #'.$thread->id);
    }

    /** @test */
    public function selecting_a_guest_opens_the_pane_and_marks_incoming_read(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $thread = $this->guestThreadWithMessage('Непрочитанное сообщение');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSet('activeGuestId', $thread->id)
            ->assertSet('activeUserId', null)
            ->assertSee('Непрочитанное сообщение')
            ->assertSee('Аноним · веб-чат сайта');

        $this->assertSame(0, ChatMessage::where('support_conversation_id', $thread->id)
            ->where('role', 'user')->where('is_read', false)->count());
    }

    /** @test */
    public function replying_to_a_guest_stores_a_userless_curator_message_and_broadcasts(): void
    {
        Event::fake([ChatMessageSent::class]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $thread = $this->guestThreadWithMessage('Вопрос гостя');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->set('newMessage', 'Здравствуйте! Отвечаю по курсу.')
            ->call('replyToGuest')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('chat_messages', [
            'support_conversation_id' => $thread->id,
            'user_id' => null,
            'role' => 'curator',
            'answered_by' => $admin->id,
            'text' => 'Здравствуйте! Отвечаю по курсу.',
        ]);

        Event::assertDispatched(
            ChatMessageSent::class,
            fn (ChatMessageSent $e) => $e->message->support_conversation_id === $thread->id
                && $e->message->role === 'curator',
        );
    }

    /** @test */
    public function selecting_a_user_clears_an_active_guest(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['name' => 'Студент']);
        ChatMessage::create(['user_id' => $student->id, 'role' => 'user', 'text' => 'Привет', 'is_read' => false]);
        $thread = $this->guestThreadWithMessage('Гость тут');

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSet('activeGuestId', $thread->id)
            ->call('selectUser', $student->id)
            ->assertSet('activeUserId', $student->id)
            ->assertSet('activeGuestId', null);
    }

    /** @test */
    public function replying_to_a_logged_in_student_also_broadcasts_to_the_widget(): void
    {
        Event::fake([ChatMessageSent::class]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['name' => 'Живой студент']);
        ChatMessage::create(['user_id' => $student->id, 'role' => 'user', 'text' => 'Здравствуйте', 'is_read' => false]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectUser', $student->id)
            ->set('newMessage', 'Ответ студенту')
            ->call('sendMessageToStudent');

        Event::assertDispatched(
            ChatMessageSent::class,
            fn (ChatMessageSent $e) => $e->message->role === 'curator'
                && $e->message->user_id === $student->id,
        );
    }

    /**
     * H1198 (Jivo-паритет S3): FAQ-суггестер даёт черновик и для гостя (только
     * категория D, публичные тарифы) — баннер должен показаться в открытом
     * гостевом треде тем же способом, что у студентов.
     *
     * @test
     */
    public function pending_answer_suggestion_for_a_guest_thread_shows_in_the_banner(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $course = Course::factory()->create();
        $course->tariffs()->create(['title' => 'Весь курс', 'type' => 'full', 'price' => 20000, 'is_active' => true]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $thread = $this->guestThreadWithMessage('Сколько стоит курс?');

        app(SupportAnswerSuggester::class)->run();
        $this->assertDatabaseHas('support_answer_suggestions', [
            'user_id' => null,
            'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT,
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('Черновик ответа')
            ->assertSee('20 000');
    }
}
