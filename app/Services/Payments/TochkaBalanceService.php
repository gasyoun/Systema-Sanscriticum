<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only Open Banking balances (H3280). Never posts payments or payouts.
 * Spendable cash is ClosingAvailable. Same JWT as acquiring; needs ReadBalances.
 */
final class TochkaBalanceService
{
    public const CACHE_KEY = 'tochka.open_banking.balances';

    /**
     * @return array{
     *     ok: bool,
     *     as_of: ?string,
     *     closing_total: float,
     *     opening_total: float,
     *     expected_total: float,
     *     accounts: list<array{account_id: string, tail: string, closing: float, opening: float, expected: float, currency: string}>,
     *     error: ?string
     * }
     */
    public function snapshot(): array
    {
        $ttl = (int) config('services.tochka.balance_cache_seconds', 60);
        if ($ttl > 0) {
            return Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->fetch());
        }

        return $this->fetch();
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $empty = [
            'ok' => false,
            'as_of' => null,
            'closing_total' => 0.0,
            'opening_total' => 0.0,
            'expected_total' => 0.0,
            'accounts' => [],
            'error' => null,
        ];

        $token = (string) config('services.tochka.token');
        if ($token === '') {
            $empty['error'] = 'no_token';

            return $empty;
        }

        $base = rtrim((string) (config('services.tochka.open_banking_url') ?: 'https://enter.tochka.com/uapi/open-banking/v1.0'), '/');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get($base.'/balances');
        } catch (\Throwable $e) {
            Log::warning('Tochka balances: network', ['e' => $e->getMessage()]);
            $empty['error'] = 'network';

            return $empty;
        }

        if (! $response->successful()) {
            Log::warning('Tochka balances: http', ['status' => $response->status()]);
            $empty['error'] = 'http_'.$response->status();

            return $empty;
        }

        $rows = $response->json('Data.Balance') ?? [];
        if (! is_array($rows)) {
            $empty['error'] = 'malformed';

            return $empty;
        }

        $byAccount = [];
        $asOf = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['accountId'] ?? '');
            if ($id === '') {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $amount = (float) data_get($row, 'Amount.amount', 0);
            $currency = (string) data_get($row, 'Amount.currency', 'RUB');
            $asOf = $row['dateTime'] ?? $asOf;
            $byAccount[$id] ??= [
                'account_id' => $id,
                'tail' => $this->tail($id),
                'closing' => 0.0,
                'opening' => 0.0,
                'expected' => 0.0,
                'currency' => $currency,
            ];
            if ($type === 'ClosingAvailable') {
                $byAccount[$id]['closing'] = $amount;
            } elseif ($type === 'OpeningAvailable') {
                $byAccount[$id]['opening'] = $amount;
            } elseif ($type === 'Expected') {
                $byAccount[$id]['expected'] = $amount;
            }
        }

        $accounts = array_values($byAccount);
        $closing = 0.0;
        $opening = 0.0;
        $expected = 0.0;
        foreach ($accounts as $a) {
            $closing += $a['closing'];
            $opening += $a['opening'];
            $expected += $a['expected'];
        }

        return [
            'ok' => true,
            'as_of' => is_string($asOf) ? $asOf : null,
            'closing_total' => round($closing, 2),
            'opening_total' => round($opening, 2),
            'expected_total' => round($expected, 2),
            'accounts' => $accounts,
            'error' => null,
        ];
    }

    private function tail(string $accountId): string
    {
        $digits = preg_replace('/\D+/', '', $accountId) ?? '';

        return $digits === '' ? '????' : substr($digits, -6);
    }
}
