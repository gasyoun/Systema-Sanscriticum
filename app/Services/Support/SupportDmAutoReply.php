<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\Faq\Bm25FaqRetriever;
use Illuminate\Support\Facades\Log;

/**
 * B: простые вопросы в личке саппорт-аккаунта отвечает бот, сложные — подсказка
 * кураторам в Telegram. Откат на A: SUPPORT_DM_AUTO_REPLY=false (флаг default OFF).
 *
 * Простое = категории A/B/C с живыми фактами LMS (Zoom / запись / расписание).
 *
 * H3380 (рулинг MG 23-08-2026, проба 2 недели на аккаунте rusamskrtam):
 *  - шаблонные ответы D/E/F: если у категории есть привязанный MessageTemplate
 *    (S9/H1838) и аккаунт включил auto_reply_enabled, бот отправляет шаблон сам
 *    (SUPPORT_AUTO_REPLY_TEMPLATES). Прежний запрет «Деньги (D) не автоотвечаем»
 *    снят ЯВНО владельцем для этого испытания; тексты — выверенные канреплаи
 *    ревизии H2339, не свободная генерация.
 *  - ack (SUPPORT_AUTO_ACK): когда автоответить нечем, короткое «приняли,
 *    ответим в течение рабочего дня», не чаще одного раза в cooldown-окно чата.
 *  - всё остальное — прежняя подсказка кураторам (hint), студенту молчание.
 *
 * Доставка исходящего — pending + ближайший telegram-support:sync
 * ({@see PendingSupportReplyDrainer}, дрен по аккаунту захода). Отсюда
 * MadelineProto не открываем.
 */
final class SupportDmAutoReply
{
    public const VIA = 'support_dm_auto_reply';

    public const EVENT_SENT = 'dm_auto_sent';

    public const EVENT_HINTED = 'dm_hinted';

    /** @var list<string> */
    private const SIMPLE_CATEGORIES = [
        SupportAnswerSuggestion::CATEGORY_ZOOM,
        SupportAnswerSuggestion::CATEGORY_RECORDING,
        SupportAnswerSuggestion::CATEGORY_SCHEDULE,
    ];

    /**
     * Категории с привязанными канреплаями (D/E/F). Автоответ шлёт только
     * текст выверенного шаблона, а не LLM — потому и разрешён на деньгах.
     *
     * @var list<string>
     */
    private const TEMPLATE_CATEGORIES = SupportAnswerSuggestion::LLM_CATEGORIES;

    public function __construct(
        private readonly SupportAnswerSuggester $suggester,
        private readonly SupportAnswerFactResolver $facts,
        private readonly SupportReplyService $replies,
        private readonly TelegramAdminNotifier $admins,
        private readonly Bm25FaqRetriever $faq,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_dm_auto_reply', false);
    }

    /**
     * @return array{status: string, category: ?string}
     */
    public function handle(TelegramSupportMessage $incoming, ?int $linkedUserId, string $chatType): array
    {
        if (! $this->isEnabled()) {
            return ['status' => 'off', 'category' => null];
        }

        if ($incoming->direction !== 'incoming' || $chatType !== 'private') {
            return ['status' => 'skip', 'category' => null];
        }

        $text = trim((string) $incoming->text);
        if ($text === '') {
            return ['status' => 'skip', 'category' => null];
        }

        if ($this->alreadyHandled($incoming)) {
            return ['status' => 'duplicate', 'category' => null];
        }

        // H3380 v2.2 (урок первого бэклог-реплея): первичный history-забор
        // приносит МЕСЯЦЫ старых входящих; автоответ/подсказка на них —
        // спам студентам и админам. Реагируем только на свежие сообщения.
        if ($incoming->sent_at !== null
            && $incoming->sent_at->lt(now()->subHours((int) config('services.telegram_support.auto_reply_max_age_hours', 6)))
        ) {
            return ['status' => 'stale_skip', 'category' => null];
        }

        $category = $this->suggester->categorize($text);
        $user = $linkedUserId ? User::query()->find($linkedUserId) : null;

        // H3380 v2 (урок первого смоука): «Намо намах!» — это приветствие,
        // а не заявка; болванка «получили, ответим» на него — глупость.
        $smallTalk = $this->pureSmallTalkKind($text);

        if ($smallTalk === 'thanks') {
            // Благодарность не требует работы: молчим, куратору не дёргаем.
            return ['status' => 'skip', 'category' => null];
        }

        if ($smallTalk === 'greeting') {
            // Один тёплый ответ на приветствие за cooldown-окно чата; повторные
            // «намасте» и ответы студента на наш бот-ответ молчанием.
            if ($user !== null
                && $this->accountAllowsAutoReply($incoming)
                && ! $this->recentOutgoingInChat($incoming)
            ) {
                return $this->sendAuto(
                    $incoming,
                    $user,
                    null,
                    (string) config(
                        'services.telegram_support.auto_greeting_text',
                        "Намасте!\n\nРады вас видеть. Напишите, по какому курсу или расписанию вопрос — с радостью поможем.",
                    ),
                    'greeting',
                );
            }

            return ['status' => 'skip', 'category' => null];
        }

        if ($user !== null
            && $category !== null
            && in_array($category, self::SIMPLE_CATEGORIES, true)
        ) {
            $resolved = $this->facts->resolve($category, $user);
            if ($resolved !== null && trim((string) $resolved['draft']) !== '') {
                return $this->sendAuto($incoming, $user, $category, (string) $resolved['draft'], 'facts');
            }
        }

        // H3380: шаблонный автоответ D/E/F по привязке S9 — только на аккаунтах
        // с auto_reply_enabled, поведение основного support-аккаунта не меняется.
        if ($user !== null
            && $category !== null
            && in_array($category, self::TEMPLATE_CATEGORIES, true)
            && (bool) config('features.support_auto_reply_templates', false)
            && $this->accountAllowsAutoReply($incoming)
        ) {
            $template = MessageTemplate::query()
                ->boundToSuggesterCategory($category)
                ->orderByDesc('updated_at')
                ->first();

            if ($template !== null) {
                $draft = $template->render($user);

                if (trim($draft) !== '') {
                    return $this->sendAuto($incoming, $user, $category, $draft, 'template', [
                        'template_id' => $template->id,
                        'template_title' => $template->title,
                    ]);
                }
            }
        }

        // H3380: ack «приняли, ответим», когда автоответить нечем. Раз в
        // cooldown-окно на чат: серия сообщений студента не плодит серию болванок.
        // Без linked-пользователя очередь не построить — только hint.
        if ($user !== null && $this->ackEnabledFor($incoming)) {
            return $this->sendAuto(
                $incoming,
                $user,
                $category,
                (string) config(
                    'services.telegram_support.auto_ack_text',
                    "Намасте!\n\nПолучили ваше сообщение и уже разбираемся. Ответим в течение рабочего дня.",
                ),
                'ack',
            );
        }

        return $this->hintComplex($incoming, $user, $category, $text);
    }

    /**
     * Ack включён и уместен: флаг, аккаунт, привязанный студент и ни одного
     * исходящего в чате внутри cooldown-окна (свежий человеческий ответ
     * снимает необходимость ack'а).
     */
    private function ackEnabledFor(TelegramSupportMessage $incoming): bool
    {
        if (! (bool) config('features.support_auto_ack', false)) {
            return false;
        }

        if (! $this->accountAllowsAutoReply($incoming)) {
            return false;
        }

        return ! $this->recentOutgoingInChat($incoming);
    }

    /**
     * Разрешает ли этот конкретный аккаунт автоответы (колонка H3380).
     */
    private function accountAllowsAutoReply(TelegramSupportMessage $incoming): bool
    {
        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->find($incoming->telegram_support_account_id);

        return $account !== null && (bool) $account->auto_reply_enabled;
    }

    /**
     * Кому слать подсказки на этом аккаунте (H3393): hint_recipients строки,
     * пусто — прежнее поведение (админы).
     *
     * @return list<string>
     */
    private function accountHintRecipients(TelegramSupportMessage $incoming): array
    {
        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->find($incoming->telegram_support_account_id);

        $recipients = $account?->hint_recipients;

        return is_array($recipients) ? array_values(array_map('strval', $recipients)) : [];
    }

    /**
     * Чистый small talk без вопроса: 'greeting' | 'thanks' | null.
     *
     * «Чистый» = после вырезания приветственных/благодарных оборотов и
     * вежливых обстоятельств («большое», «вам») не остаётся содержательного
     * слова. «Намасте, сколько стоит курс?» — НЕ small talk, идёт обычным
     * конвейером.
     */
    private function pureSmallTalkKind(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        $stripped = preg_replace('~[^\p{L}\p{N}\s]~u', ' ', $normalized) ?? '';
        $stripped = (string) preg_replace('~\s+~u', ' ', trim($stripped));

        if ($stripped === '') {
            return null;
        }

        $thanksWords = ['спасибо', 'спс', 'благодарю', 'благодарочка', 'thanks', 'thank you'];
        // Всё на «нам…»: намасте/намо/намах и производные школы.
        $greetingWords = ['привет', 'здравствуйте', 'добрый день', 'добрый вечер',
            'доброе утро', 'hello', 'hi', 'добрый'];
        $courtesyWords = ['большое', 'огромное', 'вам', 'тебе', 'пожалуйста', 'всем'];

        $isGreeting = false;
        $isThanks = false;
        $hasContent = false;

        foreach (explode(' ', $stripped) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (in_array($token, $thanksWords, true)) {
                $isThanks = true;

                continue;
            }

            if (in_array($token, $greetingWords, true) || str_starts_with($token, 'нам')) {
                $isGreeting = true;

                continue;
            }

            if (in_array($token, $courtesyWords, true)) {
                continue;
            }

            $hasContent = true;
        }

        if ($hasContent) {
            return null;
        }
        if ($isGreeting) {
            return 'greeting';
        }
        if ($isThanks) {
            return 'thanks';
        }

        return null;
    }

    /**
     * Было ли исходящее в этом чате внутри cooldown-окна (любой автор):
     * свежий ответ куратора или бота снимает и ack, и повторное приветствие.
     */
    private function recentOutgoingInChat(TelegramSupportMessage $incoming): bool
    {
        $hours = max(1, (int) config('services.telegram_support.auto_ack_cooldown_hours', 6));

        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $incoming->telegram_chat_id)
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /**
     * Поставить pending-исходящее и записать событие. $kind различает
     * facts / template / ack в meta одного события dm_auto_sent.
     *
     * @param  array<string, mixed>  $metaExtra
     * @return array{status: string, category: ?string}
     */
    private function sendAuto(
        TelegramSupportMessage $incoming,
        User $user,
        ?string $category,
        string $draft,
        string $kind,
        array $metaExtra = [],
    ): array {
        $outgoing = $this->replies->queueAiReply(
            $user,
            $draft,
            (int) $incoming->telegram_message_id,
        );

        if ($outgoing === null) {
            Log::warning('SupportDmAutoReply: не удалось поставить pending исходящее', [
                'user_id' => $user->id,
                'incoming_id' => $incoming->id,
                'kind' => $kind,
            ]);

            return $this->hintComplex($incoming, $user, $category, (string) $incoming->text);
        }

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => self::EVENT_SENT,
            'meta' => [
                'via' => self::VIA,
                'kind' => $kind,
                'category' => $category,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                ...$metaExtra,
            ],
        ]);

        return ['status' => 'sent', 'category' => $category];
    }

    /**
     * @return array{status: string, category: ?string}
     */
    private function hintComplex(
        TelegramSupportMessage $incoming,
        ?User $user,
        ?string $category,
        string $text,
    ): array {
        $hits = $this->faq->retrieve($text, 3);
        $name = $user?->name ?? 'без привязки';
        $catLabel = $category ?? 'без категории';
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeQuestion = htmlspecialchars(mb_substr($text, 0, 500), ENT_QUOTES, 'UTF-8');

        $lines = [
            '💡 <b>Сложный вопрос — бот не ответил</b>',
            "Студент: {$safeName}",
            "Категория: {$catLabel}",
            '',
            "<i>{$safeQuestion}</i>",
        ];

        if ($hits !== []) {
            $lines[] = '';
            $lines[] = '<b>Из FAQ:</b>';
            foreach (array_slice($hits, 0, 3) as $i => $hit) {
                $title = htmlspecialchars((string) ($hit['title'] ?? $hit['chunk_id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $snippet = htmlspecialchars(mb_substr((string) ($hit['snippet'] ?? ''), 0, 220), ENT_QUOTES, 'UTF-8');
                $n = $i + 1;
                $lines[] = "{$n}. {$title}";
                if ($snippet !== '') {
                    $lines[] = $snippet;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Студенту ничего не ушло. Ответьте в этом же Telegram.';

        // H3393: подсказка уходит тому, кто реально отвечает на этом аккаунте
        // (hint_recipients), иначе — админам, как раньше.
        $recipients = $this->accountHintRecipients($incoming);
        if ($recipients !== []) {
            $this->admins->notifyRecipients($recipients, implode("\n", $lines));
        } else {
            $this->admins->notifyAdmins(implode("\n", $lines));
        }

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => self::EVENT_HINTED,
            'meta' => [
                'via' => self::VIA,
                'category' => $category,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                'faq_chunk_ids' => array_values(array_map(
                    static fn (array $hit): string => (string) ($hit['chunk_id'] ?? ''),
                    $hits,
                )),
            ],
        ]);

        return ['status' => 'hinted', 'category' => $category];
    }

    private function alreadyHandled(TelegramSupportMessage $incoming): bool
    {
        $msgId = (int) $incoming->telegram_message_id;

        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $incoming->telegram_chat_id)
            ->where('direction', 'outgoing')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->contains(function (TelegramSupportMessage $outgoing) use ($msgId): bool {
                $payload = $outgoing->raw_payload ?? [];

                return ($payload['via'] ?? null) === self::VIA
                    && (int) ($payload['reply_to_msg_id'] ?? 0) === $msgId;
            });
    }
}
