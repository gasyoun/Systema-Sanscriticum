<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadStepMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * n8n зовёт этот эндпоинт, когда лид доходит до именованного шага сценария бота.
 * Лид ищем по telegram_chat_id (есть в каждом TG-апдейте, мы его сохраняем на
 * /start), при отсутствии — по magnet_token. Само письмо и идемпотентность —
 * в LeadStepMailer.
 */
final class LeadStepWebhookController extends Controller
{
    public function handle(Request $request, LeadStepMailer $mailer): JsonResponse
    {
        $data = $request->validate([
            'step' => 'required|string|max:64',
            'telegram_chat_id' => 'nullable',
            'token' => 'nullable|string',
        ]);

        $lead = null;
        if (! empty($data['telegram_chat_id'])) {
            $lead = Lead::where('telegram_chat_id', $data['telegram_chat_id'])
                ->latest('id')
                ->first();
        }
        if (! $lead && ! empty($data['token'])) {
            $lead = Lead::where('magnet_token', $data['token'])->first();
        }

        if (! $lead) {
            return response()->json(['ok' => false, 'status' => 'lead_not_found'], 404);
        }

        $status = $mailer->deliver($lead, $data['step']);

        // 200 на все разрешённые исходы — чтобы n8n не ретраил misconfig/повтор.
        return response()->json([
            'ok' => in_array($status, ['sent', 'already_sent'], true),
            'status' => $status,
        ]);
    }
}
