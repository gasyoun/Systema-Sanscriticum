<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\ChatMessage;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportMessage;
use App\Services\Support\HumanSend\HumanSendAuthorization;
use App\Services\Support\HumanSend\SupportHumanSendService;
use App\Services\Support\SupportHintSendButton;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * H3999 (рулинг I1b): очередь черновиков рядом с «Аналитикой» — Отправить,
 * Изменить, Пропустить.
 *
 * Зачем страница, если есть кнопка под подсказкой в Telegram. Кнопка — быстрый
 * путь: отправляет текст КАК ЕСТЬ и не даёт его поправить. Очередь — путь для
 * тех черновиков, которые править надо: деньги, доступ и сертификат кнопки под
 * собой не имеют вовсе (рулинг A1), и отправить их можно только отсюда, где
 * куратор видит текст целиком.
 *
 * Отправка идёт тем же {@see SupportHintSendButton::deliver()}, что и кнопка:
 * два разных кода «отправить черновик» разошлись бы по клейму или по статусу в
 * первый же месяц. Правка НЕ меняет статус (иначе черновик перестал бы быть
 * pending и отправить его стало бы нельзя) — она ставит метку в `facts`.
 *
 * H6183 (пакет hermes-24-01a10bca): под флагом features.hermes_human_send
 * очередь принимает и web-черновики (chat_message) — их отправляет
 * {@see SupportHumanSendService} с обязательным аутентифицированным
 * человеком-актёром; флаг OFF — страница ведёт себя как раньше, только TG.
 */
class TelegramSupportDraftQueue extends Page
{
    /** Событие отправки из очереди — отдельно от нажатия кнопки в Telegram. */
    public const EVENT_QUEUE_SENT = 'dm_queue_sent';

    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Очередь черновиков';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Очередь черновиков поддержки';

    protected static ?string $slug = 'telegram-support-draft-queue';

    protected static string $view = 'filament.pages.telegram-support-draft-queue';

    /** id черновика, открытого на правку (null — правим ничего). */
    public ?int $editingId = null;

    public string $editingText = '';

    public static function canAccess(): bool
    {
        // Тот же гейт, что у «Аналитики» (преподаватель не видит поддержку),
        // плюс собственный флаг: пока он выключен, пункта меню нет.
        return (bool) config('features.support_draft_queue', false)
            && auth()->user()?->isTeacher() !== true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** @return Collection<int, SupportAnswerSuggestion> */
    public function getDraftsProperty(): Collection
    {
        $query = SupportAnswerSuggestion::query()
            ->pending()
            ->with('user')
            ->orderByDesc('id')
            ->limit(50);

        // H6183: под флагом human-send очередь принимает и web-черновики —
        // их единственный путь отправки как раз здесь, «одним нажатием
        // аутентифицированного куратора». Флаг OFF — вчерашний запрос как он
        // был: только черновики из Telegram.
        if (config('features.hermes_human_send', false)) {
            $query->whereIn('source_type', [
                SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
                SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            ]);
        } else {
            $query->where('source_type', SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE);
        }

        return $query->get();
    }

    public function startEdit(int $id): void
    {
        $draft = $this->findPending($id);

        if ($draft === null) {
            return;
        }

        $this->editingId = $id;
        $this->editingText = (string) $draft->draft_text;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingText = '';
    }

    /**
     * Сохранить правку. Статус остаётся pending — иначе черновик стало бы
     * нельзя отправить; факт правки живёт меткой в `facts`, её читает
     * {@see SupportHintSendButton::deliver()} и ставит итоговый статус.
     */
    public function saveEdit(): void
    {
        $draft = $this->editingId === null ? null : $this->findPending($this->editingId);

        if ($draft === null) {
            $this->cancelEdit();

            return;
        }

        $text = trim($this->editingText);

        if ($text === '') {
            Notification::make()->danger()->title('Пустой черновик не сохраняем.')->send();

            return;
        }

        $facts = is_array($draft->facts) ? $draft->facts : [];
        $facts['edited'] = true;
        $facts['edited_by'] = auth()->id();

        $draft->forceFill(['draft_text' => $text, 'facts' => $facts])->save();

        Notification::make()->success()->title('Черновик сохранён.')->send();

        $this->cancelEdit();
    }

    /** Отправить черновик студенту — той же дорогой, что и кнопка в Telegram. */
    public function send(int $id): void
    {
        $draft = $this->findPending($id);

        if ($draft === null) {
            Notification::make()->danger()->title('Черновик уже обработан.')->send();

            return;
        }

        // H6183: web-черновик уходит через human-send сервис — актёр обязан
        // быть аутентифицированным человеком, сервис сам держит идемпотент
        // (повтор approval уже/клейм) и флаг. TG-черновик идёт прежней
        // единой дорогой SupportHintSendButton::deliver().
        if ($draft->source_type === SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE) {
            $actor = auth()->user();

            if ($actor === null) {
                Notification::make()->danger()->title('Отправка разрешена только аутентифицированному куратору.')->send();

                return;
            }

            $result = app(SupportHumanSendService::class)->send(
                $draft,
                HumanSendAuthorization::webUser($actor),
            );
        } else {
            $result = app(SupportHintSendButton::class)->deliver(
                $draft,
                auth()->id(),
                self::EVENT_QUEUE_SENT,
                ['sent_from' => 'draft_queue'],
            );
        }

        $notification = Notification::make()->title($result['message']);

        $result['status'] === 'sent' ? $notification->success() : $notification->warning();

        $notification->send();

        $this->cancelEdit();
    }

    /** Пропустить: черновик закрывается как просроченный, студенту ничего. */
    public function skip(int $id): void
    {
        $draft = $this->findPending($id);

        if ($draft === null) {
            return;
        }

        $draft->forceFill([
            'status' => SupportAnswerSuggestion::STATUS_DISCARDED,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ])->save();

        Notification::make()->success()->title('Черновик пропущен.')->send();

        $this->cancelEdit();
    }

    /** Исходный вопрос студента — контекст, без которого черновик не оценить. */
    public function questionFor(SupportAnswerSuggestion $draft): ?string
    {
        if ($draft->source_type === SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE) {
            return ChatMessage::query()->find((int) $draft->source_id)?->text;
        }

        $incoming = TelegramSupportMessage::query()->find((int) $draft->source_id);

        return $incoming?->text;
    }

    private function findPending(int $id): ?SupportAnswerSuggestion
    {
        return SupportAnswerSuggestion::query()
            ->pending()
            ->whereKey($id)
            ->first();
    }
}
