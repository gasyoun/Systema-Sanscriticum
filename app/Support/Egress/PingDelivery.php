<?php

declare(strict_types=1);

namespace App\Support\Egress;

/**
 * Итог доставки одного пульса (H7565): какая нога отвечала, дошёл ли приёмник
 * и что сказать человеку в консоли/логе. Легковесный — ноги EgressPinger
 * создают по одному экземпляру на попытку доставки.
 */
final class PingDelivery
{
    /**
     * @param  string  $via  'direct' | 'proxy' | 'none' — какая нога дала итог
     * @param  bool  $delivered  приёмник ответил 2xx
     * @param  int|null  $status  HTTP-код, если приёмник ответил (любой код)
     * @param  string  $detail  человекочитаемая причина недоставки (обе ноги)
     */
    private function __construct(
        public readonly string $via,
        public readonly bool $delivered,
        public readonly ?int $status,
        public readonly string $detail,
    ) {}

    public static function direct(int $status): self
    {
        return new self('direct', $status >= 200 && $status < 300, $status, '');
    }

    public static function proxy(int $status): self
    {
        return new self('proxy', $status >= 200 && $status < 300, $status, '');
    }

    public static function undelivered(string $detail): self
    {
        return new self('none', false, null, $detail);
    }
}
