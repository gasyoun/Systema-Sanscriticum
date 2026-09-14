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
     |                  лицензия + HTTP-only): включать осознанно;
     |   'maxmind'    — локальная база GeoLite2 (.mmdb) на нашем диске (H3445,
     |                  решение MG 24-08-2026): IP никуда не уходит, лицензия чистая,
     |                  RF-only-совместимо. Базу кладёт `support:geo-update-maxmind`
     |                  (нужны MAXMIND_ACCOUNT_ID + MAXMIND_LICENSE_KEY).
     |
     | Рулинг @DECIDE MG 24-08-2026: MaxMind GeoLite2 локально; Cloudflare отклонён
     | как заграничный процессор (§1.5 RF-only), `ipapi` исключён лицензионно
     | (docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md).
     */
    'driver' => env('SUPPORT_GEO_DRIVER', 'null'),

    // Таймаут внешнего вызова (сек) для драйвера ipapi.
    'timeout' => (int) env('SUPPORT_GEO_TIMEOUT', 3),

    // Базовый эндпоинт ipapi (завершающий слэш необязателен — нормализуется).
    'ipapi_endpoint' => env('SUPPORT_GEO_IPAPI_ENDPOINT', 'http://ip-api.com/json/'),

    // Путь к GeoLite2-City.mmdb для драйвера maxmind. Файл в .gitignore
    // (storage/app/geo/); обновление — support:geo-update-maxmind по расписанию.
    'maxmind_path' => env('SUPPORT_GEO_MAXMIND_PATH', storage_path('app/geo/GeoLite2-City.mmdb')),

    // Учётные данные бесплатного GeoLite2 (account id + ключ с maxmind.com).
    'maxmind_account_id' => env('MAXMIND_ACCOUNT_ID', ''),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY', ''),
];
