<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Тонкий HTTP-клиент Digital Kassa API v2.1 (https://api.digitalkassa.ru/v2.1/doc).
 * Basic auth actor_id:actor_token. POST чека идемпотентен по receipt_id: повтор с тем
 * же id возвращает тот же результат, поэтому сетевой ретрай безопасен.
 *
 * @throws ConnectionException из каждого метода — обрабатывает вызывающий.
 */
final class DigitalKassaClient
{
    /** Параметры группы касс: type, taxation (битовая маска), billing_place_list. */
    public function cGroup(): Response
    {
        return $this->request()->get($this->groupPath());
    }

    /** @param  array<string, mixed>  $receipt */
    public function createReceipt(string $receiptId, array $receipt): Response
    {
        return $this->request()->post($this->groupPath().'/receipts/'.rawurlencode($receiptId), $receipt);
    }

    public function receiptStatus(string $receiptId): Response
    {
        return $this->request()->get($this->groupPath().'/receipts/'.rawurlencode($receiptId));
    }

    public static function configured(): bool
    {
        return filled(config('services.digitalkassa.actor_id'))
            && filled(config('services.digitalkassa.actor_token'))
            && filled(config('services.digitalkassa.c_group_id'));
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.digitalkassa.actor_id'),
            (string) config('services.digitalkassa.actor_token'),
        )
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function groupPath(): string
    {
        return rtrim((string) config('services.digitalkassa.url'), '/')
            .'/c_groups/'.rawurlencode((string) config('services.digitalkassa.c_group_id'));
    }
}
