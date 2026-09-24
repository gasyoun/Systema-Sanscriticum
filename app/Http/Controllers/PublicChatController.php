<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Jobs\ResolveVisitorGeoJob;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Services\Support\MicShadowClassifier;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportLeadCaptureService;
use App\Services\Support\SupportWebchatAutoReply;
use App\Support\GuestChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Публичный эндпоинт живого веб-чата поддержки (H536 Phase 3).
 *
 * Посетитель samskrte.ru — гость или залогиненный студент — открывает тред и
 * шлёт сообщение без перезагрузки страницы. Гость идентифицируется session-
 * токеном (GuestChat), его `user_id` = NULL; студент пишет как свой `User`.
 * Каждое входящее бродкастится {@see ChatMessageSent} (экранированный
 * `htmlForWeb()`) в приватный канал `support.conversation.{id}` — оператор
 * (Helpdesk, Phase 5) и виджет (Phase 4) слушают его в реальном времени.
 *
 * Безопасность: роут под `throttle` (AUDIT_REPORT #4); гость НИКОГДА не
 * резолвится в строку `users` (нет account-takeover-поверхности); тело запроса
 * не логируется (AUDIT_REPORT #5); вывод всегда экранирован (никогда не сырой
 * текст посетителя).
 */
class PublicChatController extends Controller
{
    /**
     * Origin'ы, которым разрешено встраивать /chat/embed в iframe (H5451) —
     * та же политика, что у эмбедов курса (CourseInterestController) и
     * расписания (PublicWidgetController): заголовок ставится ТОЛЬКО на этом
     * ответе, глобального CSP/X-Frame-Options в проекте нет.
     */
    private const FRAME_ANCESTORS = "frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru";

    /**
     * Standalone-страница чата для iframe-эмбеда на samskrtam.ru (H5451).
     *
     * Без лейаута кабинета: только support-chat-widget (+ его scoped CSS/JS).
     * Гостевая сессия/CSRF бутстрапятся web-группой как у витрины. Параметр
     * `?page=` (товарная/магазинная страница магазина) пробрасывается в виджет:
     * контекстное приветствие «Вопрос по этому товару…» и телеметрия entry_url
     * летят с URL магазина, а не с адреса iframe. Самогейтится флагом
     * features.support_chat_embed (OFF → 404); троттлинг как у chat/message.
     */
    public function embed(Request $request): Response
    {
        abort_unless((bool) config('features.support_chat_embed'), 404);

        return response()
            ->view('chat.embed', [
                'embedPage' => self::sanitizeEmbedPage($request->query('page')),
            ])
            ->header('Content-Security-Policy', self::FRAME_ANCESTORS);
    }

    /**
     * `?page=` с WP-стороны — читается только клиентским JS виджета, но
     * все равно чистим: схеме http(s), длина ≤2048 (как в payload.page),
     * управляющие символы — долой. Пусто/мусор → '' (виджет тогда живет
     * телеметрией адреса iframe, как и без параметра).
     */
    private static function sanitizeEmbedPage(?string $page): string
    {
        if ($page === null || $page === '') {
            return '';
        }

        $page = preg_replace('/[\x00-\x1F\x7F]+/', '', $page) ?? '';
        // Невалидный UTF-8 доехал бы до @json -> json_encode(false) -> голый
        // `= ;` и синтаксическая ошибка в boot-скрипте (review H5451 P2).
        if ($page === '' || ! mb_check_encoding($page, 'UTF-8') || mb_strlen($page) > 2048) {
            return '';
        }

        $parts = parse_url($page);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return '';
        }

        return $page;
    }

    public function store(Request $request, SupportConversationManager $conversations, SupportLeadCaptureService $leadCapture, SupportWebchatAutoReply $webchatAutoReply): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'string', 'max:2048'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $text = trim($validated['text']);
        if ($text === '') {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }

        $user = $request->user();

        $message = ChatMessage::create([
            'user_id' => $user?->id,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);

        if ($user) {
            $thread = $conversations->recordMessage($user, $message, $message->created_at);
        } else {
            $thread = $conversations->recordGuestMessage(
                GuestChat::token(),
                $message,
                $validated['name'] ?? null,
                $message->created_at,
            );
        }

        // H4608: MIC shadow classify входящего (log-only, flag default OFF,
        // никогда не бросает; текст не пишется — только sha256 в телеметрии).
        MicShadowClassifier::instance()?->record('web', (int) $thread->id, (int) $message->id, $text);

        // Контекст посетителя (город/страница входа) для куратора — H1196.
        $this->captureVisitorContext($thread, $request, $validated['page'] ?? null);

        // Необязательный телефон/почта → Lead-строка (H1199, S4). За флагом —
        // выключено, ничего не меняется.
        if (config('features.support_lead_capture')) {
            $leadCapture->capture(
                $thread,
                $validated['name'] ?? null,
                $validated['email'] ?? null,
                $validated['phone'] ?? null,
            );
        }

        // Бродкаст экранированного сообщения оператору и другим клиентам треда.
        // ShouldBroadcast-событие через event() авто-бродкастится; при
        // broadcasting.default=null (до деплоя Reverb) это тихий no-op.
        // Отправитель получит эхо своего же сообщения — виджет (Phase 4)
        // дедуплицирует по `id` (оптимистичный рендер из ответа ниже).
        event(new ChatMessageSent($message));

        // H5450: мгновенный ack и/или живой FAQ-ответ ботом — только за
        // default-OFF флагами; при OFF поведение (и ответ) байт-в-байт прежнее.
        // Никогда не бросает — сбой ретривера не пятисотит публичный эндпоинт.
        $autoReplies = [];
        $bot = $webchatAutoReply->handle($thread, $message, $user);

        if ($bot !== null) {
            $autoReplies[] = $this->present($bot);
        }

        $payload = [
            'ok' => true,
            'conversation_id' => $thread->id,
            'message' => $this->present($message),
        ];

        // Ключ добавляется только при отправленном bot-сообщении: OFF-ответ
        // остаётся прежним построчно (инвариант тестом).
        if ($autoReplies !== []) {
            $payload['auto_replies'] = $autoReplies;
        }

        return response()->json($payload);
    }

    public function history(Request $request, SupportConversationManager $conversations): JsonResponse
    {
        $user = $request->user();

        $thread = $user
            ? $conversations->currentFor($user)
            : (($token = GuestChat::currentToken()) ? $conversations->currentForGuest($token) : null);

        if (! $thread) {
            return response()->json(['ok' => true, 'conversation_id' => null, 'messages' => []]);
        }

        $messages = $thread->chatMessages()
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $m) => $this->present($m))
            ->all();

        return response()->json([
            'ok' => true,
            'conversation_id' => $thread->id,
            'messages' => $messages,
        ]);
    }

    /**
     * Зафиксировать контекст посетителя в треде при ПЕРВОМ сообщении (H1196,
     * Jivo-паритет Pillar 1): IP + страница входа + referrer — всегда (дёшево,
     * без внешних вызовов); гео (город) — асинхронной джобой и только за флагом
     * support_visitor_geo. Идемпотентно: если IP уже записан, тред не трогаем —
     * контекст фиксируется от начала треда, а не переписывается каждым сообщением.
     */
    private function captureVisitorContext(SupportConversation $thread, Request $request, ?string $page): void
    {
        if ($thread->visitor_ip !== null) {
            return;
        }

        $ip = (string) $request->ip();
        $referrer = $request->headers->get('referer');

        $thread->forceFill([
            'visitor_ip' => $ip !== '' ? $ip : null,
            'entry_url' => $page !== null ? mb_substr($page, 0, 2048) : null,
            'referrer' => $referrer !== null ? mb_substr($referrer, 0, 2048) : null,
        ])->save();

        if ($ip !== '' && config('features.support_visitor_geo')) {
            ResolveVisitorGeoJob::dispatch($thread->id, $ip, [
                'city' => $request->headers->get('CF-IPCity'),
                'region' => $request->headers->get('CF-Region'),
                'country' => $request->headers->get('CF-IPCountry'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'html' => $message->htmlForWeb(),
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
