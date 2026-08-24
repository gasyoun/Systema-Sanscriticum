<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * Резолвер гео посетителя веб-чата (H1196, Jivo-паритет Pillar 1). Отдаёт
 * ['city','region','country'] по IP — чтобы куратор в Helpdesk видел, «из какого
 * города пишет посетитель», как в админке Jivo.
 *
 * Драйвер выбирается в config/support_geo.php (env SUPPORT_GEO_DRIVER):
 *   • null       — не резолвить (дефолт): вернуть null, город не запрашивать;
 *   • cloudflare — из заголовков CF-* (переданы контроллером как $hints, т.к.
 *                  джоба выполняется асинхронно и Request у неё уже нет);
 *   • ipapi      — внешний вызов ip-api.com (НЕкоммерческая лицензия — не для прода);
 *   • maxmind    — локальная база GeoLite2 (.mmdb, H3445): IP не покидает сервер,
 *                  лицензия чистая, RF-only-совместимо (решение MG 24-08-2026).
 *
 * Приватные/зарезервированные IP не резолвятся (локалка, петли прокси).
 */
class VisitorGeoResolver
{
    private ?Reader $maxMindReader = null;

    private bool $maxMindReaderTried = false;

    public function __construct(private readonly ?Reader $maxMindReaderOverride = null) {}

    /**
     * @param  array<string,string|null>  $hints  request-time заголовки CF-* из контроллера
     * @return array{city:?string, region:?string, country:?string}|null
     */
    public function resolve(string $ip, array $hints = []): ?array
    {
        $ip = trim($ip);

        if ($ip === '' || ! $this->isPublicIp($ip)) {
            return null;
        }

        return match ((string) config('support_geo.driver', 'null')) {
            'cloudflare' => $this->fromCloudflare($hints),
            'ipapi' => $this->fromIpApi($ip),
            'maxmind' => $this->fromMaxMind($ip),
            default => null,
        };
    }

    /** @param array<string,string|null> $hints */
    private function fromCloudflare(array $hints): ?array
    {
        $city = $this->clean($hints['city'] ?? null);
        $region = $this->clean($hints['region'] ?? null);
        $country = $this->clean($hints['country'] ?? null);

        if ($city === null && $region === null && $country === null) {
            return null;
        }

        return ['city' => $city, 'region' => $region, 'country' => $country];
    }

    private function fromIpApi(string $ip): ?array
    {
        $endpoint = rtrim((string) config('support_geo.ipapi_endpoint', 'http://ip-api.com/json/'), '/');
        $timeout = (int) config('support_geo.timeout', 3);

        try {
            $response = Http::timeout($timeout)
                ->get("{$endpoint}/{$ip}", ['fields' => 'status,country,countryCode,regionName,city']);
        } catch (\Throwable $e) {
            Log::warning('VisitorGeoResolver ipapi failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->ok() || $response->json('status') !== 'success') {
            return null;
        }

        return [
            'city' => $this->clean($response->json('city')),
            'region' => $this->clean($response->json('regionName')),
            'country' => $this->clean($response->json('countryCode') ?: $response->json('country')),
        ];
    }

    /**
     * Локальная GeoLite2-база (H3445, решение MG 24-08-2026: MaxMind вместо
     * Cloudflare — «заграница» и RF-only). IP никуда не отправляется; база
     * читается с диска (env SUPPORT_GEO_MAXMIND_PATH). Любая ошибка чтения
     * (нет файла, битая база, IP не найден) — тихий null + warning в лог:
     * гео — вспомогательный слой, не повод ронять сохранение треда.
     */
    private function fromMaxMind(string $ip): ?array
    {
        $reader = $this->maxMindReader();
        if ($reader === null) {
            return null;
        }

        try {
            /** @var array<string,mixed>|null $record */
            $record = $reader->get($ip);
        } catch (\Throwable $e) {
            Log::warning('VisitorGeoResolver maxmind failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $city = is_string($record['city']['names']['en'] ?? null) ? $record['city']['names']['en'] : null;
        $region = is_string($record['subdivisions'][0]['names']['en'] ?? null) ? $record['subdivisions'][0]['names']['en'] : null;
        $country = is_string($record['country']['iso_code'] ?? null) ? $record['country']['iso_code'] : null;

        if ($city === null && $region === null && $country === null) {
            return null;
        }

        return ['city' => $this->clean($city), 'region' => $this->clean($region), 'country' => $this->clean($country)];
    }

    private function maxMindReader(): ?Reader
    {
        if ($this->maxMindReaderOverride !== null) {
            return $this->maxMindReaderOverride;
        }

        if ($this->maxMindReaderTried) {
            return $this->maxMindReader;
        }

        $this->maxMindReaderTried = true;

        $path = (string) config('support_geo.maxmind_path', '');

        if ($path === '' || ! is_file($path)) {
            Log::warning('VisitorGeoResolver maxmind database missing', ['path' => $path]);

            return null;
        }

        try {
            $this->maxMindReader = new Reader($path);
        } catch (\Throwable $e) {
            Log::warning('VisitorGeoResolver maxmind reader init failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return $this->maxMindReader;
    }

    private function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 120);
    }

    /** Публичный (не приватный/зарезервированный) IP — иначе гео бессмысленно. */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
