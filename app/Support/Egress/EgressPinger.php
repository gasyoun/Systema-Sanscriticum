<?php

declare(strict_types=1);

namespace App\Support\Egress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Доставка исходящих пульсов мониторинга (heartbeat:ping, cabinet:probe
 * healthchecks-нога, money:sli heartbeat) — общая для трёх команд, чтобы
 * обводка апстримного SNI-фильтра (H7556, issue #3632) жила в одном месте.
 *
 * Контракт повторяет системд-ноги с HEARTBEAT_PROXY (PR #3633):
 *
 *   1) прямая нога первая — фильтр не вечен, и когда хостер его поднимет,
 *      прямая снова выигрывает сама, без правок;
 *   2) при транспортном отказе (ConnectionException — cURL 7/28, DNS,
 *      refused) — нога через egress.ping_proxy, с ОДНОЙ перепробой: редкие
 *      sub-5s DNS-таймауты userspace-tailscaled при чейндже путей тейлнета
 *      словили системд-ноги первым же плановым тиком 31-10;
 *   3) HTTP-ответ любого кода = путь жив, прокси не подключаем — 4xx/5xx
 *      лечит сторона приёмника, и переприём мог бы задвоить пинг.
 *
 * Решение о доставке — у вызывающей стороны (у каждой команды свой
 * fail-open контракт); здесь только транспорт и выбор ноги.
 */
final class EgressPinger
{
    public function send(string $target, string $body, int $timeoutSeconds): PingDelivery
    {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->withBody($body, 'text/plain')
                ->post($target);

            return PingDelivery::direct($response->status());
        } catch (ConnectionException $direct) {
            return $this->viaProxy($target, $body, $timeoutSeconds, $direct);
        }
    }

    private function viaProxy(string $target, string $body, int $timeoutSeconds, ConnectionException $direct): PingDelivery
    {
        $proxy = trim((string) config('egress.ping_proxy', ''));
        if ($proxy === '') {
            return PingDelivery::undelivered($direct->getMessage());
        }

        $lastError = $direct;

        // Прямая попытка + одна перепроба на transient-таймауты tailscaled.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout($timeoutSeconds)
                    ->withOptions(['proxy' => $proxy])
                    ->withBody($body, 'text/plain')
                    ->post($target);

                return PingDelivery::proxy($response->status());
            } catch (ConnectionException $e) {
                $lastError = $e;
            }
        }

        return PingDelivery::undelivered(
            'прямая нога: '.$direct->getMessage().'; через прокси: '.$lastError->getMessage(),
        );
    }
}
