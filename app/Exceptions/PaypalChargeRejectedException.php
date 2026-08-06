<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PayPal-вебхук отклонил денежное событие (H2304 spec 3).
 *
 * Бросается ИЗНУТРИ транзакции обработки; контроллер ловит её СНАРУЖИ и пишет
 * журнальную строку PaymentWebhookEvent после отката — иначе строка-отказ
 * откатывалась вместе с транзакцией и именно интересные доставки не оставляли
 * следа (аудит-находка §3 #7).
 */
final class PaypalChargeRejectedException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $decision,
        public readonly array $context = [],
    ) {
        parent::__construct("PayPal charge rejected: {$reason}");
    }
}
