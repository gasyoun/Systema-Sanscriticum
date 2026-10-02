<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportQuestionClassification;
use App\Models\SupportResponderMapping;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Collection;

/**
 * Резолв популяции ОДНОГО входящего сообщения (H5709).
 *
 * Правила спеки:
 *  - подтверждённый студент — через contact.linked_user_id + существующий
 *    студенческий авторитет (активная группа ИЛИ проведённый платёж);
 *  - отправителей в группах резолвим ТОЛЬКО через контакт, групповой
 *    chat.linked_user_id здесь не читается никогда (он «последний
 *    отправитель ЛС»-семантики и в мульти-пользовательских чатах лжёт);
 *  - внутренняя штабная координация (tech-группы, штатные роли, responder-
 *    mappings) — отдельная популяция, с прямыми студенческими вопросами
 *    не смешивается никогда;
 *  - неопознанный отправитель остаётся unknown, а не угадывается;
 *  - боты и пустые/service-сообщения исключаются с явной причиной.
 */
class QuestionPopulationResolver
{
    /** Определение «подтверждённого студента» — попадает в снапшот как definition. */
    public const STUDENT_DEFINITION = 'active_group_membership_or_paid_payment';

    /**
     * @param  Collection<int, TelegramSupportContact>  $contactsById
     * @param  Collection<int, User>  $usersById
     * @param  Collection<int, TelegramSupportChat>  $chatsById
     * @param  array<int, bool>  $studentById  карта user_id => подтверждённый студент
     * @param  Collection<int, SupportResponderMapping>  $responderMappings
     */
    public function __construct(
        private readonly Collection $contactsById,
        private readonly Collection $usersById,
        private readonly Collection $chatsById,
        private readonly array $studentById,
        private readonly Collection $responderMappings,
        private readonly array $techChatIds,
        private readonly array $botUsernames,
    ) {}

    /**
     * Строит резолвер над окном сообщений: грузит только справочники,
     * нужные этим сообщениям (одним пачечным запросом на тип).
     *
     * @param  Collection<int, TelegramSupportMessage>  $messages
     */
    public static function forMessages(
        Collection $messages,
        QuestionStudentAuthority $authority,
    ): self {
        $contactIds = $messages->map(fn (TelegramSupportMessage $m) => $m->telegram_support_contact_id)
            ->filter()->unique()->values();
        $chatIds = $messages->map(fn (TelegramSupportMessage $m) => $m->telegram_support_chat_id)
            ->unique()->values();

        $contacts = TelegramSupportContact::query()->whereIn('id', $contactIds)->get()->keyBy('id');
        $chats = TelegramSupportChat::query()->whereIn('id', $chatIds)->get()->keyBy('id');

        $userIds = $contacts->map(fn (TelegramSupportContact $c) => $c->linked_user_id)
            ->filter()->unique()->values();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $studentById = $authority->confirmedStudentMap($userIds);

        $responders = SupportResponderMapping::query()->where('is_active', true)->get();

        $techChatIds = self::techPeerChatIds($chats);
        $botUsernames = array_map(
            static fn (string $u): string => mb_strtolower(trim($u)),
            array_filter((array) config('services.telegram_support.bot_usernames', []), 'is_string'),
        );

        return new self($contacts, $users, $chats, $studentById, $responders, $techChatIds, $botUsernames);
    }

    /**
     * @return array{population: string, exclusion_reason: string|null, note: string|null}
     */
    public function resolve(TelegramSupportMessage $message): array
    {
        $text = trim((string) ($message->text ?? ''));
        if ($text === '') {
            return ['population' => SupportQuestionClassification::POPULATION_UNKNOWN, 'exclusion_reason' => SupportQuestionClassification::EXCLUSION_SERVICE_MESSAGE, 'note' => 'no text'];
        }

        $contact = $message->telegram_support_contact_id
            ? ($this->contactsById[$message->telegram_support_contact_id] ?? null)
            : null;

        if ($contact && $contact->username && in_array(mb_strtolower($contact->username), $this->botUsernames, true)) {
            return ['population' => SupportQuestionClassification::POPULATION_UNKNOWN, 'exclusion_reason' => SupportQuestionClassification::EXCLUSION_BOT, 'note' => 'sender is configured bot'];
        }

        $chat = $this->chatsById[$message->telegram_support_chat_id] ?? null;
        $isGroup = $chat !== null && in_array($chat->type, ['group', 'supergroup'], true);

        // Внутренняя tech-группа: вся её переписка — штабная координация
        // (пересказы студенческих вопросов кураторами в т.ч.), отдельная
        // популяция независимо от того, удалось ли резолвить отправителя.
        if ($chat !== null && in_array((int) $chat->id, $this->techChatIds, true)) {
            return ['population' => SupportQuestionClassification::POPULATION_STAFF_INTERNAL, 'exclusion_reason' => null, 'note' => 'internal tech group'];
        }

        $user = $contact?->linked_user_id
            ? ($this->usersById[$contact->linked_user_id] ?? null)
            : null;

        if ($user !== null && $this->isStaff($user)) {
            return ['population' => SupportQuestionClassification::POPULATION_STAFF_INTERNAL, 'exclusion_reason' => null, 'note' => 'staff sender'];
        }

        if ($isGroup) {
            // Учебная группа: отправитель без контакта/линка — честный unknown.
            if ($user === null) {
                return ['population' => SupportQuestionClassification::POPULATION_UNKNOWN, 'exclusion_reason' => null, 'note' => 'unresolved group sender'];
            }

            return $this->studentOrEnquiry($user);
        }

        // ЛС: незалинкованный контакт — входящий enquiry.
        if ($user === null) {
            return ['population' => SupportQuestionClassification::POPULATION_ENQUIRY, 'exclusion_reason' => null, 'note' => $contact ? 'unlinked private sender' : 'no contact row'];
        }

        return $this->studentOrEnquiry($user);
    }

    /**
     * @return array{population: string, exclusion_reason: null, note: string|null}
     */
    private function studentOrEnquiry(User $user): array
    {
        if ($this->studentById[$user->id] ?? false) {
            return ['population' => SupportQuestionClassification::POPULATION_STUDENT, 'exclusion_reason' => null, 'note' => null];
        }

        return ['population' => SupportQuestionClassification::POPULATION_ENQUIRY, 'exclusion_reason' => null, 'note' => 'linked but not confirmed student'];
    }

    private function isStaff(User $user): bool
    {
        if (in_array($user->role, Roles::adminLike(), true)
            || $user->role === Roles::TEACHER
            || $user->role === Roles::MANAGER
            || $user->role === Roles::ACCOUNTANT) {
            return true;
        }

        return $this->responderMappings->contains(
            fn (SupportResponderMapping $m) => (int) $m->user_id === (int) $user->id
        );
    }

    /**
     * id локальных строк telegram_support_chats, чей telegram_chat_id совпал
     * с allowlist'ом tech-пиров (по модулю знака — peer id бывают -100…).
     *
     * @param  Collection<int, TelegramSupportChat>  $chats
     * @return list<int>
     */
    private static function techPeerChatIds(Collection $chats): array
    {
        $peers = array_map(
            static fn ($p): int => abs((int) ltrim(trim((string) $p), '-')),
            array_filter((array) config('services.telegram_support.tech_group_peers', [])),
        );

        return $chats
            ->filter(fn (TelegramSupportChat $chat): bool => in_array(
                abs((int) $chat->telegram_chat_id),
                $peers,
                true,
            ))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
