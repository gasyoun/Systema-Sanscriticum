<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only Open Banking statement reader. Never calls a payment endpoint. */
final class TochkaStatementReader
{
    /** @return array{generated_at:string,statements:list<array<string,mixed>>} */
    public function read(string $from, string $to): array
    {
        $token = (string) config('services.tochka.token');
        if ($token === '') {
            throw new RuntimeException('Tochka ReadStatements token is missing');
        }
        $base = rtrim((string) config('services.tochka.open_banking_url'), '/');
        $http = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(30);
        $accounts = $this->request($http, 'get', $base.'/accounts')['Data']['Account'] ?? [];
        $out = ['generated_at' => now()->toIso8601String(), 'statements' => []];

        foreach ($accounts as $account) {
            if (! is_array($account) || ($account['status'] ?? null) !== 'Enabled' || ($account['currency'] ?? null) !== 'RUB') {
                continue;
            }
            $accountId = (string) ($account['accountId'] ?? '');
            if ($accountId === '') {
                continue;
            }
            [$statementId, $status] = $this->findReady($http, $base, $accountId, $from, $to);
            if ($status !== 'Ready') {
                $created = $this->request($http, 'post', $base.'/statements', ['Data' => ['Statement' => [
                    'accountId' => $accountId,
                    'startDateTime' => $from,
                    'endDateTime' => $to,
                ]]]);
                $statementId = (string) data_get($created, 'Data.Statement.statementId', data_get($created, 'Data.statementId', ''));
                $status = (string) data_get($created, 'Data.Statement.status', data_get($created, 'Data.status', ''));
            }
            if ($status !== 'Ready' || $statementId === '') {
                throw new RuntimeException("Tochka statement for {$accountId} is not ready; rerun after it becomes Ready");
            }
            $detail = $this->request($http, 'get', $base.'/accounts/'.rawurlencode($accountId).'/statements/'.rawurlencode($statementId));
            $digits = preg_replace('/\D+/', '', explode('/', $accountId, 2)[0]) ?? '';
            $out['statements'][] = [
                'tail' => $digits === '' ? null : substr($digits, -6),
                'statement_id' => $statementId,
                'status' => 'Ready',
                'start' => $from,
                'end' => $to,
                'data' => $detail,
            ];
        }

        return $out;
    }

    /** @return array{string,string} */
    private function findReady(PendingRequest $http, string $base, string $accountId, string $from, string $to): array
    {
        $first = $this->request($http, 'get', $base.'/statements');
        $rows = (array) data_get($first, 'Data.Statement', []);
        $pages = max(1, (int) data_get($first, 'Meta.totalPages', 1));
        for ($page = 2; $page <= $pages; $page++) {
            $next = $this->request($http, 'get', $base.'/statements?page='.$page);
            $rows = array_merge($rows, (array) data_get($next, 'Data.Statement', []));
        }
        $matches = collect($rows)
            ->filter(fn ($r): bool => is_array($r)
                && ($r['accountId'] ?? null) === $accountId
                && substr((string) ($r['startDateTime'] ?? ''), 0, 10) <= $from
                && substr((string) ($r['endDateTime'] ?? ''), 0, 10) >= $to
                && ($r['status'] ?? null) === 'Ready')
            ->sortByDesc('creationDateTime');
        $row = $matches->first();

        return is_array($row) ? [(string) ($row['statementId'] ?? ''), (string) ($row['status'] ?? '')] : ['', ''];
    }

    /** @return array<string,mixed> */
    private function request(PendingRequest $http, string $method, string $url, array $body = []): array
    {
        $response = $method === 'post' ? $http->post($url, $body) : $http->get($url);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Tochka ReadStatements request failed: HTTP '.$response->status());
        }

        return $response->json();
    }
}
