<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\VisitorGeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaxMind\Db\Reader;
use Tests\TestCase;

/**
 * H3445 — драйвер 'maxmind' локальной GeoLite2-базы (решение MG 24-08-2026:
 * MaxMind вместо Cloudflare по RF-only; ipapi исключён лицензионно).
 * Реальную .mmdb не читаем — подставляем фейковый Reader и проверяем
 * маппинг полей и fail-тихо контракты резолвера.
 */
class VisitorGeoMaxmindTest extends TestCase
{
    use RefreshDatabase;

    private function fakeReader(?array $record, ?\Throwable $throw = null): Reader
    {
        return new class($record, $throw) extends Reader
        {
            public function __construct(
                private readonly ?array $record,
                private readonly ?\Throwable $throw,
            ) {}

            public function get(string $ipAddress): mixed
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->record;
            }
        };
    }

    private function resolverWith(Reader $reader): VisitorGeoResolver
    {
        config(['support_geo.driver' => 'maxmind']);

        return app()->makeWith(VisitorGeoResolver::class, ['maxMindReaderOverride' => $reader]);
    }

    public function test_maxmind_maps_city_region_country(): void
    {
        $resolver = $this->resolverWith($this->fakeReader([
            'city' => ['names' => ['en' => 'Moscow', 'ru' => 'Москва']],
            'subdivisions' => [['names' => ['en' => 'Moscow']]],
            'country' => ['iso_code' => 'RU'],
        ]));

        $this->assertSame(
            ['city' => 'Moscow', 'region' => 'Moscow', 'country' => 'RU'],
            $resolver->resolve('203.0.113.50'),
        );
    }

    public function test_unknown_ip_fails_quietly_to_null(): void
    {
        $resolver = $this->resolverWith($this->fakeReader(null, new \RuntimeException('address not found')));

        $this->assertNull($resolver->resolve('198.51.100.7'));
    }

    public function test_record_without_geo_fields_returns_null(): void
    {
        $resolver = $this->resolverWith($this->fakeReader(['registered_country' => ['iso_code' => 'DE']]));

        $this->assertNull($resolver->resolve('203.0.113.9'));
    }

    public function test_private_ip_is_not_resolved_even_with_driver_on(): void
    {
        $resolver = $this->resolverWith($this->fakeReader([
            'city' => ['names' => ['en' => 'Should Not Happen']],
        ]));

        $this->assertNull($resolver->resolve('192.168.1.10'));
    }

    public function test_missing_database_file_returns_null_and_does_not_throw(): void
    {
        config([
            'support_geo.driver' => 'maxmind',
            'support_geo.maxmind_path' => storage_path('app/geo/definitely-missing.mmdb'),
        ]);

        $this->assertNull(app(VisitorGeoResolver::class)->resolve('203.0.113.77'));
    }

    public function test_other_drivers_are_unaffected_by_maxmind_code(): void
    {
        config(['support_geo.driver' => 'null']);

        $this->assertNull(app(VisitorGeoResolver::class)->resolve('203.0.113.5'));
    }
}
