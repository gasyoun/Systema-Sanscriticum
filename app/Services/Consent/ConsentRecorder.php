<?php

declare(strict_types=1);

namespace App\Services\Consent;

use App\Models\Consent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Единственная точка записи журнала согласий (152-ФЗ, ст. 9 ч. 3).
 *
 * Запись согласия никогда не должна ронять основную операцию (заявку, оплату,
 * подписку): ошибка журнала логируется и проглатывается.
 */
class ConsentRecorder
{
    public function given(string $type, string $source, ?Request $request = null, ?User $user = null, ?string $email = null, ?int $leadId = null): ?Consent
    {
        return $this->write(Consent::ACTION_GIVEN, $type, $source, $request, $user, $email, $leadId);
    }

    public function withdrawn(string $type, string $source, ?Request $request = null, ?User $user = null, ?string $email = null, ?int $leadId = null): ?Consent
    {
        return $this->write(Consent::ACTION_WITHDRAWN, $type, $source, $request, $user, $email, $leadId);
    }

    /**
     * Типовой случай формы: пишет согласие на ПДн, если галочка pd_consent
     * пришла, и согласие/отказ на рекламу по галочке $promoField (если
     * $promoField передан — отсутствие галочки фиксируется только при
     * $recordPromoRefusal, чтобы не плодить «отзывы» у тех, кто ничего не давал).
     */
    public function fromForm(Request $request, string $source, ?User $user = null, ?string $email = null, ?int $leadId = null, ?string $promoField = null, bool $recordPromoRefusal = false): void
    {
        if ($request->boolean('pd_consent')) {
            $this->given(Consent::TYPE_PD, $source, $request, $user, $email, $leadId);
        }

        if ($promoField === null) {
            return;
        }

        if ($request->boolean($promoField) || $request->input($promoField) === 'on') {
            $this->given(Consent::TYPE_PROMO, $source, $request, $user, $email, $leadId);
        } elseif ($recordPromoRefusal) {
            $this->withdrawn(Consent::TYPE_PROMO, $source, $request, $user, $email, $leadId);
        }
    }

    public static function docVersion(string $type): ?string
    {
        return config("consent.documents.{$type}.version");
    }

    private function write(string $action, string $type, string $source, ?Request $request, ?User $user, ?string $email, ?int $leadId): ?Consent
    {
        try {
            $email = $email ?? $user?->email;

            return Consent::query()->create([
                'user_id' => $user?->getKey(),
                'lead_id' => $leadId,
                'email' => $email !== null && $email !== '' ? mb_strtolower(trim($email)) : null,
                'type' => $type,
                'action' => $action,
                'doc_version' => self::docVersion($type),
                'source' => Str::limit($source, 64, ''),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 512, '') : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ConsentRecorder: не удалось записать согласие', [
                'type' => $type,
                'action' => $action,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
