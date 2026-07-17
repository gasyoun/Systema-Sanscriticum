<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Jobs\ResolveVisitorGeoJob;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportLeadCaptureService;
use App\Support\GuestChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function store(Request $request, SupportConversationManager $conversations, SupportLeadCaptureService $leadCapture): JsonResponse
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

        return response()->json([
            'ok' => true,
            'conversation_id' => $thread->id,
            'message' => $this->present($message),
        ]);
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
