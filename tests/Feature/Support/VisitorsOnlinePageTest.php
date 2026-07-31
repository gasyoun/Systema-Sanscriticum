<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Events\ChatMessageSent;
use App\Filament\Pages\VisitorsOnline;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportVisitorPresence;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H1197 — Jivo-паритет Pillar 2: операторская страница «Посетители онлайн».
 * Гейт (флаг + роль), живой список онлайн-посетителей, и проактив «написать
 * первым» — тред посетителя открывается/переоткрывается (реюз H536) и curator-
 * сообщение бродкастится в виджет.
 */
class VisitorsOnlinePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.support_visitor_presence', true);
    }

    private function onlinePresence(array $attrs = []): SupportVisitorPresence
    {
        return SupportVisitorPresence::create(array_merge([
            'guest_token' => 'tok-'.bin2hex(random_bytes(6)),
            'visitor_city' => 'Москва',
            'visitor_country' => 'RU',
            'current_url' => 'https://samskrte.ru/k/sanskrit',
            'first_seen_at' => now()->subMinutes(3),
            'last_seen_at' => now()->subSeconds(10),
        ], $attrs));
    }

    public function test_page_hidden_when_flag_off(): void
    {
        config()->set('features.support_visitor_presence', false);
        $this->assertFalse(VisitorsOnline::canAccess());
    }

    public function test_teacher_cannot_access_but_admin_can(): void
    {
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->assertFalse(VisitorsOnline::canAccess());

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);
        $this->assertTrue(VisitorsOnline::canAccess());
    }

    public function test_online_visitor_is_listed_with_city_and_page(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->onlinePresence();

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->assertSee('Гость')
            ->assertSee('Москва')
            ->assertSee('/k/sanskrit');
    }

    public function test_offline_visitor_is_not_listed(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->onlinePresence([
            'guest_token' => 'tok-gone',
            'current_url' => 'https://samskrte.ru/ushel',
            'last_seen_at' => now()->subMinutes(5),
        ]);

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->assertDontSee('/ushel');
    }

    public function test_start_compose_opens_box_for_that_visitor(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $presence = $this->onlinePresence();

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->call('startCompose', $presence->id)
            ->assertSet('composeFor', $presence->id)
            ->call('cancelCompose')
            ->assertSet('composeFor', null);
    }

    public function test_proactive_message_to_guest_creates_thread_and_broadcasts(): void
    {
        Event::fake([ChatMessageSent::class]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $presence = $this->onlinePresence(['guest_token' => 'tok-proactive']);

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->call('startCompose', $presence->id)
            ->set('proactiveMessage', 'Здравствуйте! Подсказать по курсам санскрита?')
            ->call('sendProactive')
            ->assertSet('composeFor', null);

        $thread = SupportConversation::where('guest_token', 'tok-proactive')->firstOrFail();
        $this->assertDatabaseHas('chat_messages', [
            'support_conversation_id' => $thread->id,
            'user_id' => null,
            'role' => 'curator',
            'answered_by' => $admin->id,
            'text' => 'Здравствуйте! Подсказать по курсам санскрита?',
        ]);

        Event::assertDispatched(
            ChatMessageSent::class,
            fn (ChatMessageSent $e) => $e->message->support_conversation_id === $thread->id
                && $e->message->role === 'curator',
        );
    }

    public function test_proactive_message_to_logged_in_student_carries_user_id(): void
    {
        Event::fake([ChatMessageSent::class]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create(['name' => 'Живой студент']);
        $presence = $this->onlinePresence(['guest_token' => 'tok-stud', 'user_id' => $student->id]);

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->call('startCompose', $presence->id)
            ->set('proactiveMessage', 'Вижу, вы на странице курса — помочь с выбором?')
            ->call('sendProactive');

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'curator',
            'answered_by' => $admin->id,
        ]);

        Event::assertDispatched(
            ChatMessageSent::class,
            fn (ChatMessageSent $e) => $e->message->role === 'curator' && $e->message->user_id === $student->id,
        );
    }

    public function test_proactive_requires_non_empty_message(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $presence = $this->onlinePresence();

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->call('startCompose', $presence->id)
            ->set('proactiveMessage', '')
            ->call('sendProactive')
            ->assertHasErrors(['proactiveMessage' => 'required']);
    }

    public function test_proactive_to_departed_visitor_is_graceful(): void
    {
        Event::fake([ChatMessageSent::class]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Livewire::actingAs($admin)
            ->test(VisitorsOnline::class)
            ->set('composeFor', 999999)
            ->set('proactiveMessage', 'Сообщение в никуда')
            ->call('sendProactive')
            ->assertSet('composeFor', null);

        Event::assertNotDispatched(ChatMessageSent::class);
        $this->assertSame(0, ChatMessage::count());
    }
}
