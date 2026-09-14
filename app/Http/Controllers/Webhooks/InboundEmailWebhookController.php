<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Support\InboundEmailIngester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * H3462: приём входящего email канала поддержки. Ящик zabota@samskrte.ru
 * пересылает почту в проводник (n8n на .91 — без нового платного вендора),
 * проводник POSTит разобранный payload сюда; письмо раскладывается в общую
 * chat_messages-модель поддержки (source='email') через InboundEmailIngester.
 *
 * Gated by config('features.support_inbound_email'); OFF по умолчанию —
 * маршрут отвечает 404, как LectureClipCallbackWebhookController.
 * Секрет пути проверяет middleware verify.inbound.email (fail-closed).
 */
final class InboundEmailWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        abort_if(! config('features.support_inbound_email', false), 404);

        $data = $request->validate([
            'message_id' => 'required|string|max:998',
            'from_email' => 'required|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:998',
            'text' => 'required|string|max:100000',
            // Дата письма по часам отправителя может отставать от момента форварда.
            'received_at' => 'nullable|date',
        ]);

        $result = app(InboundEmailIngester::class)->ingest($data);

        return response()->json([
            'ok' => true,
            'status' => $result['status'],
        ]);
    }
}
