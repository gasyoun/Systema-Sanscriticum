<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\FollowUpTask;
use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\Faq\HybridRetriever;
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

    public const EVENT_STALE_SKIP = 'dm_stale_skip';

    /** H3765 A3: «бот отправил бы это сам» — только запись, ни одного исходящего. */
    public const EVENT_SHADOW_WOULD_SEND = 'dm_shadow_would_send';

    /**
     * H3999: то же самое, но для ФАКТОВ LMS, а не для попадания в FAQ.
     *
     * Отдельный тип события, а не флажок в meta: ключ firstOrCreate — это пара
     * (сообщение, тип события), и общий тип означал бы «одно теневое событие на
     * сообщение». Тогда неделя тени по статусу ДЗ молча съедала бы неделю тени
     * по FAQ, и обе выборки перестали бы быть выборками.
     */
    public const EVENT_FACT_SHADOW_WOULD_SEND = 'dm_shadow_would_send_facts';

    /** H3999: расхождение заявленной суммы с расчётной — задача финансисту. */
    public const EVENT_BALANCE_DISPUTE = 'dm_balance_dispute';

    /** H3765 A5: куратор нажал «Отправить как есть» под подсказкой. */
    public const EVENT_HINT_SEND_TAPPED = 'dm_hint_send_tapped';

    /** Префикс callback_data кнопки одного нажатия (см. TelegramWebhookController). */
    public const SEND_CALLBACK_PREFIX = 'sdm:';

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
        private readonly HybridRetriever $faq,
        private readonly SupportDmLinkInvite $linkInvite,
        private readonly SupportConversationManager $conversations,
        private readonly SupportFollowUpService $followUps,
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
        //
        // H3765 A2: один потолок стал двумя. Сообщение старше ПОДСКАЗОЧНОГО
        // потолка (24 ч) отбрасывается целиком, как и раньше. Сообщение между
        // двумя потолками (6–24 ч) студенту уже не отвечаем — просроченный
        // автоответ хуже молчания, — но куратору показываем: он решит сам.
        $ageDecision = $this->freshness($incoming);

        if ($ageDecision === 'stale') {
            // H3392: пропуск остаётся тихим для студента и куратора, но
            // помечается ОДНИМ маркером на сообщение (firstOrCreate — повторные
            // проходы синка дублей не плодят), иначе недельный отчёт пробы
            // support:auto-reply-weekly не видит объём бэклога.
            SupportAiReplyEvent::firstOrCreate(
                [
                    'telegram_support_message_id' => $incoming->id,
                    'event_type' => self::EVENT_STALE_SKIP,
                ],
                ['meta' => ['via' => self::VIA]],
            );

            return ['status' => 'stale_skip', 'category' => null];
        }

        $mayReachStudent = $ageDecision === 'fresh';

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
            // «намасте» и ответы студента на наш бот-ответ молчанием. Устаревшее
            // приветствие (H3765) не отвечаем и куратору не показываем: «Намасте»
            // суточной давности не требует ни ответа, ни разбора.
            if ($mayReachStudent
                && $user !== null
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

        // H3999 (волна 1 leverage-плана): резолверов стало восемь, и решение
        // «отправить» больше не выводится из одной категории. Категория говорит,
        // ЧТО спросили; отправлять или нет — решают ДВЕ вещи: политика самого
        // ответа (send_policy) и список ЖИВЫХ типов фактов.
        //
        // По умолчанию живыми остаются РОВНО те три типа, что уходили студенту
        // до H3999 (zoom/schedule/recording) — поведение канала байт-в-байт
        // прежнее. Пять новых резолверов копят теневые события, пока человек не
        // откроет их после недели тени (рулинг V1). Деньги, доступ и сертификат
        // не откроются никогда: они вычёркиваются в КОДЕ (рулинг A1).
        $resolvedFacts = null;
        $escalation = null;

        if ($mayReachStudent && $user !== null && $category !== null) {
            $resolvedFacts = $this->facts->resolve($category, $user, $text);

            if ($resolvedFacts !== null && trim((string) $resolvedFacts['draft']) !== '') {
                if ($this->factMayAutoSend($resolvedFacts)) {
                    return $this->sendAuto(
                        $incoming,
                        $user,
                        $category,
                        (string) $resolvedFacts['draft'],
                        'facts',
                        ['fact_type' => (string) ($resolvedFacts['facts']['type'] ?? '')],
                    );
                }

                $this->recordFactShadowWouldSend($incoming, $user, $category, $resolvedFacts);

                // Рулинг A1: расчётная сумма разошлась с заявленной студентом —
                // студенту не уходит ничего, куратор получает подсказку без
                // кнопки, финансовому ведущему заводится задача.
                if (($resolvedFacts['send_policy'] ?? null) === SupportAnswerFactResolver::POLICY_ESCALATE) {
                    $escalation = $this->escalateBalanceDispute($incoming, $user, $resolvedFacts);
                    $resolvedFacts = null;
                }
            }
        }

        // H3768 (рулинг MG 31-08-2026 «только F»): живой автоответ из FAQ.
        //
        // Стоит ПЕРЕД шаблонами, и это измеренное решение, а не вкусовое.
        // Шаблон привязан к КАТЕГОРИИ целиком, а F — это и ДЗ, и материалы, и
        // сертификаты; на проде у F ровно один шаблон, про сдачу ДЗ. Значит
        // вопрос про сертификат сегодня получает автоответом инструкцию по
        // домашним заданиям. Порог разводит эти случаи сам (замер 31-08-2026
        // на живом faq.md): «куда загружать ДЗ» даёт 7.7–12.4 — НИЖЕ порога,
        // и выверенный шаблон по-прежнему отвечает; «будет ли сертификат» даёт
        // 19.3 и попадает в раздел «Сертификат» — выше порога, и отвечает FAQ.
        // То есть шаблон не проигрывает там, где он прав, и перестаёт отвечать
        // там, где он не про то.
        //
        // Категории берутся из конфига, но D (деньги) и E (доступы)
        // вычёркиваются в КОДЕ: рулинг R3 запрещает их безусловно, и правка
        // конфига не должна уметь это снять.
        if ($mayReachStudent
            && $user !== null
            && $category !== null
            && in_array($category, $this->liveFaqCategories(), true)
            && $this->accountAllowsAutoReply($incoming)
        ) {
            $hits = $this->faq->retrieve($text, 3);
            $score = (float) ($hits[0]['score'] ?? 0.0);

            if ($hits !== [] && $score >= $this->scoreFloor($category)) {
                $draft = $this->faqDraft($hits);

                if ($draft !== null) {
                    return $this->sendAuto($incoming, $user, $category, $draft, 'faq_rag', [
                        'chunk_id' => (string) ($hits[0]['chunk_id'] ?? ''),
                        'score' => round($score, 4),
                        'floor' => $this->scoreFloor($category),
                    ]);
                }
            }
        }

        // H3380: шаблонный автоответ D/E/F по привязке S9 — только на аккаунтах
        // с auto_reply_enabled, поведение основного support-аккаунта не меняется.
        if ($mayReachStudent
            && $user !== null
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
        if ($mayReachStudent && $user !== null && $this->ackEnabledFor($incoming)) {
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

        // H3542: всё выше требовало linked-пользователя. Если сообщение свежее,
        // распознанной категории и автоответ блокирован ТОЛЬКО отсутствием связи —
        // один раз за cooldown-окно отправляем приглашение связать Telegram с
        // кабинетом (флаг support_dm_link_invite, пер-аккаунтный гейт внутри).
        if ($mayReachStudent && $user === null && $category !== null && $this->linkInvite->offerForIncoming($incoming)) {
            return ['status' => 'invite_sent', 'category' => $category];
        }

        return $this->hintComplex($incoming, $user, $category, $text, ! $mayReachStudent, $resolvedFacts, $escalation);
    }

    /**
     * H3765 A2: два потолка свежести вместо одного.
     *
     * 'fresh'     — моложе auto_reply_max_age_hours: разрешено всё, включая
     *               исходящее студенту.
     * 'hint_only' — между потолками: студенту молчим, куратору показываем.
     * 'stale'     — старше hint_max_age_hours (или подсказочный потолок ниже
     *               автоответного — тогда это прежнее одноступенчатое поведение):
     *               тихий пропуск с маркером.
     *
     * Сообщение без sent_at (возраст неизвестен) считаем свежим — как и до
     * H3765: домысливать возраст и молча гасить живой вопрос хуже.
     */
    private function freshness(TelegramSupportMessage $incoming): string
    {
        if ($incoming->sent_at === null) {
            return 'fresh';
        }

        $sendHours = (int) config('services.telegram_support.auto_reply_max_age_hours', 6);
        $hintHours = max($sendHours, (int) config('services.telegram_support.hint_max_age_hours', 24));

        if ($incoming->sent_at->lt(now()->subHours($hintHours))) {
            return 'stale';
        }

        return $incoming->sent_at->lt(now()->subHours($sendHours)) ? 'hint_only' : 'fresh';
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
    /**
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}|null  $resolvedFacts
     */
    private function hintComplex(
        TelegramSupportMessage $incoming,
        ?User $user,
        ?string $category,
        string $text,
        bool $aged = false,
        ?array $resolvedFacts = null,
        ?string $escalation = null,
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

        if ($aged) {
            // H3765 A2: сообщение старше автоответного потолка. Куратор должен
            // видеть, что вопрос уже полежал: ответ «как ни в чём не бывало»
            // на позавчерашнее письмо читается хуже, чем ответ с оговоркой.
            $lines[] = sprintf(
                '⏳ Вопрос пролежал дольше %d ч — бот поэтому и промолчал.',
                (int) config('services.telegram_support.auto_reply_max_age_hours', 6),
            );
        }

        // H3765 A5: черновик под одну кнопку. Заводим его ДО отправки подсказки —
        // клавиатура несёт его id, и без записи кнопке нечего было бы отправить.
        $suggestion = $this->oneTapSuggestion($incoming, $user, $category, $text, $hits, $resolvedFacts);
        $sendable = $suggestion !== null && ! $suggestion->isDraftOnly();

        if ($sendable) {
            $lines[] = '';
            $lines[] = 'Кнопка ниже отправит студенту черновик как есть.';
        } elseif ($suggestion !== null) {
            // H3999, рулинг A1: деньги, доступ и сертификат — только черновик.
            // Кнопки под ними нет вовсе; отправить его можно лишь из очереди
            // черновиков в админке, где куратор видит текст целиком.
            $lines[] = '';
            $lines[] = 'Черновик требует проверки — кнопки под ним нет. Он ждёт в очереди черновиков.';
        }

        if ($escalation !== null) {
            $lines[] = '';
            $lines[] = $escalation;
        }

        // H3393: подсказка уходит тому, кто реально отвечает на этом аккаунте
        // (hint_recipients), иначе — админам, как раньше.
        $recipients = $this->accountHintRecipients($incoming);
        $keyboard = ! $sendable ? null : [[[
            'text' => '📨 Отправить как есть',
            'callback_data' => self::SEND_CALLBACK_PREFIX.$suggestion->id,
        ]]];

        if ($recipients !== []) {
            $this->admins->notifyRecipients($recipients, implode("\n", $lines), $keyboard);
        } else {
            $this->admins->notifyAdmins(implode("\n", $lines), $keyboard);
        }

        $this->recordShadowWouldSend($incoming, $user, $category, $text, $hits);

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => self::EVENT_HINTED,
            'meta' => [
                'via' => self::VIA,
                'category' => $category,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                'aged' => $aged,
                'suggestion_id' => $suggestion?->id,
                'faq_chunk_ids' => array_values(array_map(
                    static fn (array $hit): string => (string) ($hit['chunk_id'] ?? ''),
                    $hits,
                )),
            ],
        ]);

        return ['status' => 'hinted', 'category' => $category];
    }

    /**
     * H3765 A5: запись-черновик, за которую цепляется inline-кнопка подсказки.
     *
     * H3999 (шаг I1a): раньше черновик заводился ТОЛЬКО из попадания в FAQ, и
     * потому подсказки по фактам и по шаблонам приходили куратору без кнопки —
     * то есть ровно те, которых в трафике большинство (FINDINGS §635). Теперь
     * источников три, в порядке убывания точности: попадание FAQ → факт LMS →
     * выверенный шаблон категории. Уникальный ключ (source_type, source_id)
     * держит инвариант «один черновик на сообщение»: повторный проход синка не
     * плодит вторую кнопку.
     *
     * Политика ответа переезжает в `facts.send_policy` черновика: отсюда её
     * читают и клавиатура подсказки, и кнопка {@see SupportHintSendButton}, и
     * очередь черновиков в админке. Резолвер только МЕТИТ, отправитель решает.
     *
     * @param  list<array<string, mixed>>  $hits
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}|null  $resolvedFacts
     */
    private function oneTapSuggestion(
        TelegramSupportMessage $incoming,
        ?User $user,
        ?string $category,
        string $text,
        array $hits,
        ?array $resolvedFacts = null,
    ): ?SupportAnswerSuggestion {
        if ($user === null || $category === null) {
            return null;
        }

        $draft = $hits === [] ? null : $this->faqDraft($hits);
        $kind = 'faq';
        $confidence = (float) ($hits[0]['score'] ?? 0.0);
        $policy = SupportAnswerFactResolver::POLICY_AUTO;
        $extraFacts = $draft === null ? [] : [
            'faq_chunk_id' => (string) ($hits[0]['chunk_id'] ?? ''),
            'faq_title' => (string) ($hits[0]['title'] ?? ''),
        ];

        if ($draft === null && $resolvedFacts !== null && trim((string) $resolvedFacts['draft']) !== '') {
            $draft = (string) $resolvedFacts['draft'];
            $kind = 'facts';
            $confidence = (float) ($resolvedFacts['confidence'] ?? 0.0);
            $policy = (string) ($resolvedFacts['send_policy'] ?? SupportAnswerFactResolver::POLICY_DRAFT_ONLY);
            $extraFacts = ['fact_type' => (string) ($resolvedFacts['facts']['type'] ?? '')];
        }

        if ($draft === null) {
            $template = $this->categoryTemplate($category, $user);

            if ($template !== null) {
                $draft = $template['draft'];
                $kind = 'template';
                $confidence = 0.0;
                $extraFacts = [
                    'template_id' => $template['template_id'],
                    'template_title' => $template['template_title'],
                ];
            }
        }

        if ($draft === null) {
            return null;
        }

        return SupportAnswerSuggestion::firstOrCreate(
            [
                'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
                'source_id' => $incoming->id,
            ],
            [
                'user_id' => $user->id,
                'category' => $category,
                'detected_text' => mb_substr($text, 0, 2000),
                'draft_text' => $draft,
                'facts' => [
                    'via' => self::VIA,
                    'kind' => $kind,
                    'send_policy' => $policy,
                    'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                    ...$extraFacts,
                ],
                'confidence' => $confidence,
                'status' => SupportAnswerSuggestion::STATUS_PENDING,
            ],
        );
    }

    /**
     * Выверенный канреплай категории (S9/H1838) как черновик под кнопку.
     *
     * Отличие от автоответа шаблоном выше: там нужен пер-аккаунтный гейт и флаг
     * автоотправки, потому что текст уходит студенту сам. Здесь текст никуда не
     * уходит — его читает куратор, — поэтому гейт не нужен, а связь категории с
     * шаблоном одна и та же.
     *
     * @return array{draft: string, template_id: int, template_title: string}|null
     */
    private function categoryTemplate(string $category, User $user): ?array
    {
        $template = MessageTemplate::query()
            ->boundToSuggesterCategory($category)
            ->orderByDesc('updated_at')
            ->first();

        if ($template === null) {
            return null;
        }

        $draft = $template->render($user);

        if (trim($draft) === '') {
            return null;
        }

        return [
            'draft' => $draft,
            'template_id' => (int) $template->id,
            'template_title' => (string) $template->title,
        ];
    }

    /**
     * H3999, рулинг A1: может ли этот факт уйти студенту САМ.
     *
     * Два независимых условия, и оба обязательны. Политика ответа — что сказал
     * резолвер; список живых типов — что открыл человек после недели тени.
     *
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}  $resolved
     */
    private function factMayAutoSend(array $resolved): bool
    {
        if (($resolved['send_policy'] ?? null) !== SupportAnswerFactResolver::POLICY_AUTO) {
            return false;
        }

        $type = (string) ($resolved['facts']['type'] ?? '');

        return $type !== '' && in_array($type, $this->liveFactTypes(), true);
    }

    /**
     * Типы фактов, которые бот отправляет студенту сам.
     *
     * Дефолт конфига — ровно те три, что уходили до H3999. Деньги, доступ и
     * сертификат вычёркиваются ЗДЕСЬ, в коде: правка конфига не должна уметь
     * снять запрет рулинга A1 — ровно так же, как D/E вычеркнуты из живых
     * категорий FAQ в {@see self::liveFaqCategories()}.
     *
     * @return list<string>
     */
    private function liveFactTypes(): array
    {
        $configured = config('support.facts.live_types', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $configured),
            static fn (string $type): bool => ! in_array($type, SupportAnswerFactResolver::NEVER_AUTO_TYPES, true),
        ));
    }

    /**
     * H3999, теневой сбор по фактам: «отправил бы, но тип ещё не живой».
     *
     * Пишем только то, что действительно ушло бы при открытом типе: черновики
     * навсегда (деньги/доступ/сертификат) в выборку недели не попадают —
     * иначе точность считалась бы по строкам, которые никто не собирался
     * отправлять.
     *
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}  $resolved
     */
    private function recordFactShadowWouldSend(
        TelegramSupportMessage $incoming,
        User $user,
        string $category,
        array $resolved,
    ): void {
        if (! (bool) config('features.support_dm_auto_reply_shadow', false)) {
            return;
        }

        if (($resolved['send_policy'] ?? null) !== SupportAnswerFactResolver::POLICY_AUTO) {
            return;
        }

        SupportAiReplyEvent::firstOrCreate(
            [
                'telegram_support_message_id' => $incoming->id,
                'event_type' => self::EVENT_FACT_SHADOW_WOULD_SEND,
            ],
            [
                'meta' => [
                    'via' => self::VIA,
                    'category' => $category,
                    'fact_type' => (string) ($resolved['facts']['type'] ?? ''),
                    'confidence' => (float) ($resolved['confidence'] ?? 0.0),
                    'draft' => (string) $resolved['draft'],
                    'user_id' => $user->id,
                    'telegram_chat_id' => (int) $incoming->telegram_chat_id,
                ],
            ],
        );
    }

    /**
     * H3999, рулинг A1: расчётный остаток разошёлся с суммой, названной
     * студентом. Студенту не уходит ничего; куратор видит подсказку без кнопки;
     * финансовому ведущему заводится follow-up.
     *
     * Проверенная оговорка: {@see SupportFollowUpService::create()} БРОСАЕТ при
     * выключенном флаге `features.support_follow_up_tasks`, поэтому флаг
     * проверяется здесь, а не ловится исключением. Флаг выключен — остаётся
     * обычная подсказка куратору, и это не деградация: человек в контуре был и
     * остаётся, задача лишь не заводится автоматически.
     *
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}  $resolved
     */
    private function escalateBalanceDispute(
        TelegramSupportMessage $incoming,
        User $user,
        array $resolved,
    ): string {
        $claimed = $resolved['facts']['claimed_amount'] ?? null;
        $note = 'Расхождение по оплате: студент называет сумму '
            .($claimed === null ? '—' : number_format((float) $claimed, 0, ',', ' ').' ₽')
            .', расчёт по кабинету другой. Сверьте по кассе и ответьте студенту сами.';

        SupportAiReplyEvent::firstOrCreate(
            [
                'telegram_support_message_id' => $incoming->id,
                'event_type' => self::EVENT_BALANCE_DISPUTE,
            ],
            [
                'meta' => [
                    'via' => self::VIA,
                    'user_id' => $user->id,
                    'claimed_amount' => $claimed === null ? null : (float) $claimed,
                    'courses' => $resolved['facts']['courses'] ?? [],
                ],
            ],
        );

        if (! (bool) config('features.support_follow_up_tasks', false)) {
            return '💸 Расхождение по оплате — студенту ничего не ушло. Задача не заведена: features.support_follow_up_tasks выключен.';
        }

        $thread = $this->conversations->currentFor($user);

        if ($thread === null) {
            return '💸 Расхождение по оплате — студенту ничего не ушло. Тред поддержки не найден, задача не заведена.';
        }

        $this->followUps->create(
            $thread,
            now()->addDay(),
            $this->financeLead(),
            FollowUpTask::TYPE_MESSAGE,
            $note,
        );

        return '💸 Расхождение по оплате — студенту ничего не ушло, задача финансовому ведущему заведена.';
    }

    /** Финансовый ведущий из конфига; null — задача остаётся неназначенной. */
    private function financeLead(): ?User
    {
        $id = config('support.escalation.finance_lead_user_id');

        return $id === null ? null : User::query()->find((int) $id);
    }

    /**
     * Черновик студенту из лучшего попадания FAQ. Цитата обязательна (рулинг
     * R3): студент должен видеть, откуда взят ответ, а куратор — что именно
     * уйдёт по кнопке.
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
     * H3765 A3, теневой режим. Пишет «отправил бы» и НИЧЕГО не отправляет.
     *
     * Условия ровно те, при которых живая автоотправка была бы допущена
     * (рулинг R3): включён флаг тени, студент привязан, категория безопасная
     * (D-деньги и E-доступы исключены), лучший BM25-скор не ниже порога.
     * Событие пишется firstOrCreate — повторные заходы синка одного и того же
     * сообщения не раздувают недельную выборку.
     *
     * @param  list<array<string, mixed>>  $hits
     */
    private function recordShadowWouldSend(
        TelegramSupportMessage $incoming,
        ?User $user,
        ?string $category,
        string $text,
        array $hits,
    ): void {
        if (! (bool) config('features.support_dm_auto_reply_shadow', false)) {
            return;
        }

        if ($user === null || $category === null || $hits === []) {
            return;
        }

        $allowed = config('support.faq_rag.shadow_categories', ['A', 'B', 'C', 'F']);
        if (! is_array($allowed) || ! in_array($category, $allowed, true)) {
            return;
        }

        $score = (float) ($hits[0]['score'] ?? 0.0);
        if ($score < $this->scoreFloor($category)) {
            return;
        }

        $draft = $this->faqDraft($hits);
        if ($draft === null) {
            return;
        }

        SupportAiReplyEvent::firstOrCreate(
            [
                'telegram_support_message_id' => $incoming->id,
                'event_type' => self::EVENT_SHADOW_WOULD_SEND,
            ],
            [
                'meta' => [
                    'via' => self::VIA,
                    'category' => $category,
                    'score' => round($score, 4),
                    'chunk_id' => (string) ($hits[0]['chunk_id'] ?? ''),
                    'draft' => $draft,
                    // Сам вопрос в событие не кладём: недельный отчёт читает
                    // человек, а тексты студентов уже лежат в своей таблице.
                    // Хэш нужен только чтобы склеить повторы одного вопроса.
                    'question_hash' => hash('sha256', mb_strtolower(trim($text))),
                    'user_id' => $user->id,
                    'telegram_chat_id' => (int) $incoming->telegram_chat_id,
                ],
            ],
        );
    }

    /**
     * H3766 B5 — порог скора, выведенный ПОКАТЕГОРИЙНО на 100-вопросном наборе
     * (`php artisan faq:score-floor`). Категорийный порог всегда строже общего:
     * берём максимум, чтобы правка одного числа не могла ослабить другое.
     *
     * ОДНО определение на тень и на живую отправку — в этом весь смысл теневой
     * калибровки. Разойдись они, и «мы это неделю измеряли в тени» перестало бы
     * что-либо значить про то, что уходит студенту.
     */
    /**
     * H3768 — категории, в которых живой автоответ из FAQ вообще допускается.
     *
     * Рулинг MG 31-08-2026 «только F»: из четырёх теневых категорий живой
     * становится одна — материалы/ДЗ/сертификаты. Это единственная категория,
     * где выведенные B5 пороги дают обещанные R3 95 % точности при разумном
     * покрытии (41 %); у A/B/C 95 % достигаются только на 2–3 вопросах.
     *
     * D (деньги) и E (доступы) вычёркиваются здесь же и безусловно: рулинг R3
     * запрещает их независимо от скора, и запрет не должен зависеть от того,
     * что кто-то однажды впишет в конфиг.
     *
     * @return list<string>
     */
    private function liveFaqCategories(): array
    {
        if (! (bool) config('features.support_dm_auto_reply_live_faq', false)) {
            return [];
        }

        $configured = config('support.faq_rag.live_categories', []);
        if (! is_array($configured)) {
            return [];
        }

        $banned = [
            SupportAnswerSuggestion::CATEGORY_PAYMENT,
            SupportAnswerSuggestion::CATEGORY_ACCESS,
        ];

        return array_values(array_filter(
            array_map('strval', $configured),
            static fn (string $c): bool => ! in_array($c, $banned, true),
        ));
    }

    private function scoreFloor(string $category): float
    {
        $global = (float) config('support.faq_rag.shadow_min_score', 8.0);

        $perCategory = config('support.faq_rag.shadow_min_score_by_category', []);
        if (! is_array($perCategory) || ! isset($perCategory[$category])) {
            return $global;
        }

        return max($global, (float) $perCategory[$category]);
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
