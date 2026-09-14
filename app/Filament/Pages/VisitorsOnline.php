<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\SupportVisitorPresence;
use App\Services\Support\SupportConversationManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «Посетители онлайн» (H1197, Jivo-паритет Pillar 2) — операторская страница
 * рядом с {@see Helpdesk}. Куратор видит живой список посетителей витрины прямо
 * сейчас (город из S1, текущая страница, время на сайте) — даже тех, кто ещё
 * ничего не написал, — и может НАПИСАТЬ ПЕРВЫМ (инициатива человека, не авто-бот).
 * Проактивное сообщение создаёт/переоткрывает тред посетителя (реюз H536) и
 * бродкастится в его виджет, который раскрывается.
 *
 * Приватность: за флагом features.support_visitor_presence (OFF по умолчанию),
 * страница скрыта и роут закрыт при выключенном флаге. IP не показываем — только
 * город. Гейт роли — как у Helpdesk: преподаватель не видит поддержку.
 */
class VisitorsOnline extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Посетители онлайн';

    protected static ?string $title = 'Посетители на samskrte.ru сейчас';

    protected static ?string $slug = 'visitors-online';

    protected static string $view = 'filament.pages.visitors-online';

    /** Презенс, к которому открыт compose-бокс проактивного сообщения (null = закрыт). */
    public ?int $composeFor = null;

    /** Текст проактивного сообщения куратора. */
    public string $proactiveMessage = '';

    public static function canAccess(): bool
    {
        return (bool) config('features.support_visitor_presence')
            && auth()->user()?->isTeacher() !== true;
    }

    /**
     * Живой список онлайн-посетителей (computed): плоские массивы, а не Eloquent,
     * чтобы Livewire не сериализовал модель в свойство (тот же приём, что
     * guestThreads в Helpdesk). Перечитывается на каждый wire:poll-рендер.
     *
     * @return Collection<int, array{id:int, name:string, location:?string, page_label:string, current_url:?string, time_on_site:string, is_student:bool}>
     */
    public function getVisitorsProperty(): Collection
    {
        if (! config('features.support_visitor_presence')) {
            return collect();
        }

        return SupportVisitorPresence::query()
            ->online()
            ->with('user:id,name')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (SupportVisitorPresence $p): array => [
                'id' => $p->id,
                'name' => $p->displayName(),
                'location' => $p->locationLabel(),
                'page_label' => $this->pageLabel($p->current_url),
                'current_url' => $p->current_url,
                'time_on_site' => $p->timeOnSiteLabel(),
                'is_student' => $p->user_id !== null,
            ]);
    }

    /** Короткая метка страницы (path без домена) для панели куратора. */
    private function pageLabel(?string $url): string
    {
        if (! $url) {
            return '—';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $label = is_string($path) && $path !== '' ? $path : '/';

        return mb_strimwidth($label, 0, 48, '…');
    }

    /** Открыть compose-бокс у строки посетителя (P3, проактив). */
    public function startCompose(int $presenceId): void
    {
        $this->composeFor = $presenceId;
        $this->proactiveMessage = '';
    }

    public function cancelCompose(): void
    {
        $this->composeFor = null;
        $this->proactiveMessage = '';
    }

    /**
     * Куратор пишет первым (P3): открыть/переоткрыть тред посетителя и записать
     * curator-сообщение, бродкастнув его в виджет (реюз паттерна replyToGuest /
     * sendMessageToStudent). Гость — по guest_token, студент — по user_id.
     */
    public function sendProactive(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate(['proactiveMessage' => 'required|string|max:2000']);

        $presence = SupportVisitorPresence::find($this->composeFor);
        if (! $presence) {
            Notification::make()->title('Посетитель уже ушёл с сайта')->warning()->send();
            $this->cancelCompose();

            return;
        }

        $curator = auth()->user();
        $conversations = app(SupportConversationManager::class);

        $thread = $presence->user_id
            ? $conversations->openFor($presence->user_id)
            : $conversations->openForGuest($presence->guest_token);

        $message = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => $presence->user_id,
            'role' => 'curator',
            'answered_by' => $curator?->id,
            'text' => $this->proactiveMessage,
            'is_read' => true,
        ]);

        $thread->forceFill(['last_message_at' => $message->created_at])->save();

        // Виджет посетителя (Phase 4/P3) слушает support.conversation.{id} и раскрывается.
        event(new ChatMessageSent($message));

        $this->cancelCompose();

        Notification::make()
            ->title('Сообщение отправлено — посетитель увидит его в чате')
            ->success()
            ->send();
    }
}
