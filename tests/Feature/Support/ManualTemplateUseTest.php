<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3395 — ручная отправка куратором, начатая с шаблона библиотеки (canreply),
 * пишет одно usage-событие `template_used`. Denominator для ревью библиотеки
 * H2339: раньше ручные канреплаи были невидимы (S9 gap #3) — аналитика видела
 * только автосенды `dm_auto_sent kind=template`.
 *
 * Идемпотентность: маркер pendingTemplateId обнуляется первой отправкой —
 * повторный сенд того же текста (ретрай) событий не создаёт. Автоответы
 * (SupportDmAutoReply) в эту поверхность не пишут — они уже считают
 * `dm_auto_sent kind=template`.
 */
class ManualTemplateUseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['features.crm_cockpit' => true]);
        config(['features.support_unified_reply' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function curator(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    private function studentWithChat(): User
    {
        $student = User::factory()->create(['role' => null, 'name' => 'Мару']);
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'вопрос',
            'is_read' => false,
        ]);

        return $student;
    }

    /** @test */
    public function manual_send_started_from_template_records_exactly_one_event(): void
    {
        $curator = $this->curator();
        $this->actingAs($curator);
        $student = $this->studentWithChat();
        $tpl = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create([
            'title' => 'Сроки дедлайна',
            'body' => 'Намасте, {name}! Разбираемся, ответ по срокам уже готовим.',
        ]);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('insertCannedReply', $tpl->id)
            ->call('sendMessageToStudent')
            ->assertSet('pendingTemplateId', null);

        $this->assertSame(1, SupportAiReplyEvent::query()->count());

        $event = SupportAiReplyEvent::query()->sole();
        $this->assertSame(SupportAiReplyEvent::EVENT_TEMPLATE_USED, $event->event_type);
        $this->assertSame($tpl->id, $event->meta['template_id']);
        $this->assertSame('Сроки дедлайна', $event->meta['title']);
        $this->assertSame($student->id, $event->meta['student_user_id']);
        $this->assertSame($curator->id, $event->meta['curator_id']);
        $this->assertSame('helpdesk', $event->meta['channel']);

        // Сообщение реально ушло студенту (обычный кабинетный путь).
        $this->assertSame(1, ChatMessage::query()->where('role', 'curator')->count());
    }

    /** @test */
    public function retry_send_of_the_same_text_does_not_double_count(): void
    {
        $this->actingAs($this->curator());
        $student = $this->studentWithChat();
        $tpl = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create([
            'body' => 'Намасте, {name}!',
        ]);

        // Второй вызов того же сенда (Livewire-ретрай/двойной клик): маркер уже
        // сброшен, поле очищено — ни второго события, ни второго сообщения.
        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('insertCannedReply', $tpl->id)
            ->call('sendMessageToStudent')
            ->call('sendMessageToStudent');

        $this->assertSame(1, SupportAiReplyEvent::query()->count());
        $this->assertSame(1, ChatMessage::query()->where('role', 'curator')->count());
    }

    /** @test */
    public function manual_send_without_template_records_nothing(): void
    {
        $this->actingAs($this->curator());
        $student = $this->studentWithChat();

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('newMessage', 'Ответ без шаблона')
            ->call('sendMessageToStudent');

        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    /** @test */
    public function reinserting_another_template_keeps_single_marker(): void
    {
        $this->actingAs($this->curator());
        $student = $this->studentWithChat();
        $first = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create(['body' => 'Первый']);
        $second = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create(['body' => 'Второй']);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('insertCannedReply', $first->id)
            ->call('insertCannedReply', $second->id)
            ->call('sendMessageToStudent');

        $this->assertSame(1, SupportAiReplyEvent::query()->count());
        $this->assertSame($second->id, SupportAiReplyEvent::query()->sole()->meta['template_id']);
    }
}
