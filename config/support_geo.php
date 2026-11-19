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
     |   'maxmind'    — локальная база DB-IP City Lite (.mmdb, формат MaxMind DB)
     |                  на нашем диске (H3445, H6602 — решение MG 13-10-2026):
     |                  IP никуда не уходит, лицензия чистая, RF-only-совместимо.
     |                  Базу кладёт `support:geo-update-maxmind` (DB-IP Lite,
     |                  ежемесячный файл, БЕЗ регистрации; CC BY 4.0 — атрибуция
     |                  в политике конфиденциальности).
     |
     | Рулинг @DECIDE MG 24-08-2026: локальный справочник; Cloudflare отклонён
     | как заграничный процессор (§1.5 RF-only), `ipapi` исключён лицензионно
     | (docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md).
     | Рулинг MG 13-10-2026 (H6577): источник — DB-IP City Lite (без
     | регистрации; приёмник-ридер прежний).
     */
    'driver' => env('SUPPORT_GEO_DRIVER', 'null'),

    // Таймаут внешнего вызова (сек) для драйвера ipapi.
    'timeout' => (int) env('SUPPORT_GEO_TIMEOUT', 3),

    // Базовый эндпоинт ipapi (завершающий слэш необязателен — нормализуется).
    'ipapi_endpoint' => env('SUPPORT_GEO_IPAPI_ENDPOINT', 'http://ip-api.com/json/'),

    // Путь к базе .mmdb для драйвера maxmind (сейчас DB-IP City Lite, тот же
    // формат MaxMind DB). Файл в .gitignore (storage/app/geo/); обновление —
    // support:geo-update-maxmind по расписанию (ежемесячно).
    'maxmind_path' => env('SUPPORT_GEO_MAXMIND_PATH', storage_path('app/geo/GeoLite2-City.mmdb')),
];
