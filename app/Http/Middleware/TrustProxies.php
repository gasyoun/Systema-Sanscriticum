<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * H3311: раньше тут был жёсткий '*', т.е. X-Forwarded-* верил ЛЮБОМУ, кто
     * достучался до php-fpm напрямую (мимо nginx) — спуфинг отравлял
     * request()->ip() (rate-limit ключи, geo-jobs, last_login_ip). Теперь
     * список приходит из TRUSTED_PROXIES (config/security.php): IP/CIDR через
     * запятую или '*'; пусто = не доверять никому. Прод ставит реальный адрес
     * своего LB/nginx (см. DEPLOY_QUEUE.md).
     *
     * @var array<int, string>
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $this->proxies = self::parseProxies((string) config('security.trusted_proxies', ''));
    }

    /**
     * «10.0.0.0/8, 192.168.1.1» → ['10.0.0.0/8', '192.168.1.1'];
     * '*' → ['*']; пусто/пробелы → [] (trust none).
     *
     * @return array<int, string>
     */
    public static function parseProxies(string $raw): array
    {
        if (trim($raw) === '*') {
            return ['*'];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));
    }
}
