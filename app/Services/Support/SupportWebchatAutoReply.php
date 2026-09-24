<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Support\SupportSmallTalk;
use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\Faq\HybridRetriever;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H5450 — первые 45 секунд в вебчате samskrte.ru (полоса Support, веб-срез).
 *
 * Гость/студент отправил сообщение в виджет — и до этого момента тишина до
 * человека (замер 24-09-2026: после отправки ноль автоматики, порог T-Бизнеса
 * 45 с). Здесь два независимых поведения, оба за default-OFF флагами (флип —
 * решение человека, R9/R10):
 *
 *  1. ack (features.support_webchat_auto_ack) — мгновенное bot-сообщение
 *     «приняли, куратор ответит» + ссылка на FAQ. Один на серию: любое
 *     исходящее (куратор или бот) в треде внутри cooldown-окна снимает ack —
 *     паттерн cooldown-инварианта ack'а H3380 (recentOutgoingInChat).
 *  2. живой FAQ-ответ (features.support_webchat_live_faq) — категория F
 *     (материалы/ДЗ/сертификаты) выше живого порога отвечает цитатой раздела
 *     FAQ, как в TG-полосе H3768. Порог — тот же scoreFloor()-домен BM25.
 *
 * Приоритет: FAQ-ответ ЗАМЕНЯЕТ ack (уверенный ответ и есть «приняли»); два
 * bot-сообщения на одно входящее — спам. Ни одного исходящего при OFF.
 *
 * Границы (R3, паттерн H3799): деньги (D) и доступы (E) вычеркнуты В КОДЕ —
 * правка конфига не должна уметь их включить; живая категория жёстко
 * пересекается с {F} в коде. Гостевой тред (user_id NULL) не получает никаких
 * персональных фактов LMS — только публичный FAQ-корпус; факт-резолверы
 * (H3233-конвейер) сюда не перенесены вовсе.
 *
 * Приватность: тело сообщения никуда не уходит (MicShadowClassifier рядом
 * пишет только sha256); телеметрия — SupportAiReplyEvent с NULL
 * telegram_support_message_id и via=self::VIA — веб-срез недельного отчёта
 * H3392 (support:auto-reply-weekly). Никаких внешних LLM-вызовов: BM25/гибрид
 * локальный.
 *
 * TG-полоса ({@see SupportDmAutoReply}) не тронута: moneyIntent-паттерн и
 * small-talk-эвристика здесь ЗЕРКАЛЯТ её приватные копии — сведение в один
 * общий класс отдельным рефакторингом (правка TG-полосы вне скоупа H5450).
 */
final class SupportWebchatAutoReply
{
    public const VIA = 'support_webchat_auto_reply';

    public const EVENT_SENT = SupportDmAutoReply::EVENT_SENT; // dm_auto_sent

    public const KIND_ACK = 'ack';

    public const KIND_FAQ_RAG = 'faq_rag';

    /**
     * Зеркало MONEY_INTENT_PATTERN из SupportDmAutoReply (инцидент 19-09-2026):
     * денежное намерение в тексте означает, что автоответить нельзя при ЛЮБОЙ
     * категории — деньги решает человек (R3). Правка списка обязана идти в
     * обе копии, пока не сведено в один класс.
     */
    private const MONEY_INTENT_PATTERN = '/оплат|плат[еёжи]|денег|деньг|стоимост|сколько\s+стои|цен[аеуы]|\bтариф|рассрочк|доплат|предоплат|скидк|промокод|по\s+частям|сч[её]т|квитанц|возврат/iu';

    /**
     * Живая FAQ-категория веб-чата — жёсткий allowlist в коде (рулинг R3
     * «только F»): конфиг может только сузить, расширить на D/E он не в силах.
     *
     * @var list<string>
     */
    private const LIVE_FAQ_ALLOW = [
        SupportAnswerSuggestion::CATEGORY_MATERIALS, // F: материалы/ДЗ/сертификаты
    ];

    public function __construct(
        private readonly SupportAnswerSuggester $suggester,
        private readonly HybridRetriever $faq,
        private readonly SupportConversationManager $conversations,
    ) {}

    public function isAckEnabled(): bool
    {
        return (bool) config('features.support_webchat_auto_ack', false);
    }

    public function isLiveFaqEnabled(): bool
    {
        return (bool) config('features.support_webchat_live_faq', false);
    }

    /**
     * Ответить боту на входящее сообщение вебчата; null = бот молчит (OFF,
     * small talk, cooldown или уверенного хита нет). Никогда не бросает:
     * сбой ретривера не должен пятисотить публичный эндпоинт посетителя.
     */
    public function handle(SupportConversation $thread, ChatMessage $incoming, ?User $user): ?ChatMessage
    {
        try {
            return $this->doHandle($thread, $incoming, $user);
        } catch (Throwable $e) {
            Log::warning('SupportWebchatAutoReply: конвейер прерван, посетителю не уходит ничего', [
                'conversation_id' => $thread->id,
                'chat_message_id' => $incoming->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function doHandle(SupportConversation $thread, ChatMessage $incoming, ?User $user): ?ChatMessage
    {
        $text = trim((string) $incoming->text);

        if ($text === '') {
            return null;
        }

        // H3380-урок: болванка «получили, ответим» на «спасибо!» и на чистое
        // «Намасте» — глупость; благодарность не требует работы вовсе.
        if ($this->pureSmallTalkKind($text) !== null) {
            return null;
        }

        // 1) Живой FAQ-ответ (F выше порога). Уверенный ответ заменяет ack.
        $faqAnswer = $this->faqAnswer($thread, $incoming, $user, $text);

        if ($faqAnswer !== null) {
            return $faqAnswer;
        }

        // 2) Мгновенный ack — только когда автоответить нечем.
        return $this->ack($thread, $incoming, $user);
    }

    /**
     * Живая FAQ-ветка. Категория F из жёсткого allowlist И конфига (конфиг
     * может только сузить; D/E запрещены кодом безусловно — тест обязан это
     * держать), денежное намерение в тексте гасит ветку при любой категории.
     */
    private function faqAnswer(SupportConversation $thread, ChatMessage $incoming, ?User $user, string $text): ?ChatMessage
    {
        if (! $this->isLiveFaqEnabled()) {
            return null;
        }

        if ($this->moneyIntent($text)) {
            return null;
        }

        $category = $this->suggester->categorize($text);

        if ($category === null || ! in_array($category, $this->liveFaqCategories(), true)) {
            return null;
        }

        $hits = $this->faq->retrieve($text, 3);

        // H5065: порог читается в домене BM25 (HybridRetriever::bm25Score) —
        // прямое чтение ['score'] сравнивало бы RRF-скор с порогом категории.
        $score = HybridRetriever::bm25Score($hits[0] ?? []);

        if ($hits === [] || $score < $this->scoreFloor($category)) {
            return null;
        }

        $draft = $this->faqDraft($hits);

        if ($draft === null) {
            return null;
        }

        return $this->sendBotMessage($thread, $user, $draft, self::KIND_FAQ_RAG, $category, [
            'chunk_id' => (string) ($hits[0]['chunk_id'] ?? ''),
            'score' => round($score, 4),
            'floor' => $this->scoreFloor($category),
        ]);
    }

    /**
     * Мгновенный ack. Один на серию: любое исходящее (куратор или бот) в треде
     * внутри cooldown-окна снимает его — свежий ответ человека важнее шаблона.
     */
    private function ack(SupportConversation $thread, ChatMessage $incoming, ?User $user): ?ChatMessage
    {
        if (! $this->isAckEnabled()) {
            return null;
        }

        if ($this->recentOutgoingInThread($thread)) {
            return null;
        }

        $text = (string) config('support.webchat_auto_reply.ack_text', 'Намасте! Приняли — куратор ответит. Пока можете посмотреть наш FAQ: {faq_url}');

        return $this->sendBotMessage(
            $thread,
            $user,
            str_replace('{faq_url}', (string) config('support.webchat_auto_reply.faq_url', '/faq/dz'), $text),
            self::KIND_ACK,
            null,
        );
    }

    /**
     * Было ли исходящее в треде внутри cooldown-окна (любой автор: куратор или
     * бот) — зеркала recentOutgoingInChat из TG-полосы.
     */
    private function recentOutgoingInThread(SupportConversation $thread): bool
    {
        $hours = max(1, (int) config('support.webchat_auto_reply.ack_cooldown_hours', 6));

        return ChatMessage::query()
            ->where('support_conversation_id', $thread->id)
            ->whereIn('role', ['curator', 'bot'])
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /**
     * Живые категории вебчата: пересечение конфига с жёстким allowlist {F}.
     * D (деньги) и E (доступы) запрещены кодом безусловно (R3) — двойная
     * страховка: ни allowlist, ни конфиг не может их включить.
     *
     * @return list<string>
     */
    private function liveFaqCategories(): array
    {
        $configured = config('support.faq_rag.live_categories', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $configured),
            static fn (string $category): bool => in_array($category, self::LIVE_FAQ_ALLOW, true),
        ));
    }

    /**
     * Порог скора — тот же scoreFloor()-домен, что в TG-полосе (H3766 B5):
     * категорийный порог всегда строже общего, берём максимум.
     */
    private function scoreFloor(string $category): float
    {
        $global = (float) config('support.faq_rag.shadow_min_score', 8.0);

        $perCategory = config('support.faq_rag.shadow_min_score_by_category', []);

        if (! is_array($perCategory) || ! isset($perCategory[$category])) {
            return $global;
        }

        return max($global, (float) $perCategory[$category]);
    }

    /**
     * Черновик ответа с цитатой раздела — зеркало faqDraft() TG-полосы.
     *
     * @param  list<array<string, mixed>>  $hits
     */
    private function faqDraft(array $hits): ?string
    {
        $best = $hits[0] ?? null;
        $snippet = trim((string) ($best['snippet'] ?? ''));

        if ($snippet === '') {
            return null;
        }

        $title = trim((string) ($best['title'] ?? ''));
        $citation = $title === '' ? 'наш FAQ' : "наш FAQ, раздел «{$title}»";

        return "Намасте!\n\n{$snippet}\n\nИсточник — {$citation}. Если вопрос остался, напишите — ответит куратор.";
    }

    /**
     * Создать bot-сообщение в треде, бродкастить виджету и оператору, писать
     * телеметрию (dm_auto_sent-эквивалент веб-полосы). Одна точка исходящих.
     */
    private function sendBotMessage(
        SupportConversation $thread,
        ?User $user,
        string $text,
        string $kind,
        ?string $category,
        array $metaExtra = [],
    ): ChatMessage {
        $bot = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => $user?->id,
            'role' => 'bot',
            'text' => $text,
            'is_read' => false,
        ]);

        $this->conversations->attach($thread, $bot);

        // Тот же экранированный бродкаст, что у сообщений посетителя и
        // куратора: виджет дедуплицирует по id (оптимистичный рендер).
        event(new ChatMessageSent($bot));

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => self::EVENT_SENT,
            'meta' => [
                'via' => self::VIA,
                'kind' => $kind,
                'category' => $category,
                'conversation_id' => (int) $thread->id,
                'chat_message_id' => (int) $bot->id,
                'guest' => $user === null,
                ...$metaExtra,
            ],
        ]);

        return $bot;
    }

    /**
     * Зеркало pureSmallTalkKind() TG-полосы: чистый small talk без вопроса —
     * 'greeting' | 'thanks' | null. «Намасте, сколько стоит курс?» — НЕ small
     * talk и идёт обычным конвейером.
     */
    private function pureSmallTalkKind(string $text): ?string
    {
        return SupportSmallTalk::kind($text);
    }

    private function moneyIntent(string $text): bool
    {
        return preg_match(self::MONEY_INTENT_PATTERN, $text) === 1;
    }
}
