<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для внешнего Telegram-бота партнёрской программы (@Partner_..._bot).
 * Бот живёт отдельным сервисом; сюда он ходит по общему секрету
 * (middleware verify.partner.bot, заголовок X-Partner-Bot-Secret) — регистрирует
 * партнёра из диалога и запрашивает его статистику. Веб-регистрация
 * (PartnerController) и это API создают одну и ту же сущность Partner.
 */
class PartnerBotController extends Controller
{
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('partner.enabled', false), 404);
    }

    /**
     * Зарегистрировать/поднять партнёра по его Telegram. Идемпотентно по
     * telegram_username: повторный /start возвращает уже существующего партнёра
     * с его кодом, а не плодит дубли.
     */
    public function register(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'telegram_username' => ['required', 'string', 'max:100'],
            'telegram_id' => ['nullable', 'string', 'max:64'],
        ]);

        $tg = trim($data['telegram_username']);
        if (! str_starts_with($tg, '@')) {
            $tg = '@'.$tg;
        }

        $partner = Partner::firstOrCreate(
            ['telegram_username' => $tg],
            [
                'name' => $data['name'],
                'code' => Partner::generateCode(),
                'status' => Partner::STATUS_PENDING,
            ],
        );

        return response()->json([
            'ok' => true,
            'partner' => $this->present($partner),
            'created' => $partner->wasRecentlyCreated,
            // Куда отправить человека завершить регистрацию/увидеть условия.
            'web_url' => url('/partners?src=bot'),
        ]);
    }

    /** Статистика партнёра по коду или Telegram — бот показывает её в диалоге. */
    public function stats(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'telegram_username' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Partner::query();
        if (filled($data['code'] ?? null)) {
            $query->where('code', $data['code']);
        } elseif (filled($data['telegram_username'] ?? null)) {
            $tg = trim($data['telegram_username']);
            if (! str_starts_with($tg, '@')) {
                $tg = '@'.$tg;
            }
            $query->where('telegram_username', $tg);
        } else {
            return response()->json(['ok' => false, 'error' => 'code_or_telegram_required'], 422);
        }

        $partner = $query->first();
        if (! $partner) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json(['ok' => true, 'partner' => $this->present($partner)]);
    }

    /** @return array<string,mixed> */
    private function present(Partner $partner): array
    {
        return [
            'code' => $partner->code,
            'status' => $partner->status,
            'referral_link' => $partner->referralLink(),
            'reward_amount' => $partner->rewardAmount(),
            'clients_total' => (int) $partner->conversions()->count(),
            'amount_owed' => $partner->amountOwed(),
            'amount_paid_out' => $partner->amountPaidOut(),
            'conversions_pending' => (int) $partner->conversions()
                ->where('status', PartnerConversion::STATUS_ACCRUED)->count(),
        ];
    }
}
