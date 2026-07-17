<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Гео посетителя веб-чата (H1196, Jivo-паритет Pillar 1)
|--------------------------------------------------------------------------
| Как резолвить IP посетителя в город/регион/страну для панели куратора.
| Резолв в любом случае происходит только при включённом флаге
| features.support_visitor_geo (config/features.php).
*/

return [
    /*
     | Драйвер резолва:
     |   'null'       — не резолвить (ДЕФОЛТ): хранить только IP, город не запрашивать;
     |   'cloudflare' — читать заголовки CF-IPCity / CF-Region / CF-IPCountry, которые
     |                  ставит Cloudflare (ноль внешних вызовов; страна — всегда за CF,
     |                  город — только на планах, где включён CF-IPCity);
     |   'ipapi'      — внешний вызов ip-api.com (город бесплатно, НО НЕкоммерческая
     |                  лицензия + HTTP-only): включать осознанно.
     |
     | Выбор коммерческого провайдера города (MaxMind GeoLite2 локально / ipapi /
     | ipinfo / CF-Enterprise) — @DECIDE MG, см.
     | docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md § S1.
     */
    'driver' => env('SUPPORT_GEO_DRIVER', 'null'),

    // Таймаут внешнего вызова (сек) для драйвера ipapi.
    'timeout' => (int) env('SUPPORT_GEO_TIMEOUT', 3),

    // Базовый эндпоинт ipapi (завершающий слэш необязателен — нормализуется).
    'ipapi_endpoint' => env('SUPPORT_GEO_IPAPI_ENDPOINT', 'http://ip-api.com/json/'),
];
