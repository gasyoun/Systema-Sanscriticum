<?php

/**
 * H5021 — SELECT-only проба перед бэкфиллом: сколько лидов вообще, у скольких
 * есть хоть что-то, из чего leads:infer-source выведет источник, и топ
 * referrer-хостов. Запуск на проде: `php artisan tinker scripts/h5021_lead_source_probe.php`.
 * Ничего не пишет.
 */

use Illuminate\Support\Facades\DB;

$leads = fn () => DB::table('leads');
$nonEmpty = fn ($q, string $col) => $q->whereNotNull($col)->where($col, '!=', '');

$out = [
    'total' => $leads()->count(),
    'utm_source' => $nonEmpty($leads(), 'utm_source')->count(),
    'source_article_slug' => $nonEmpty($leads(), 'source_article_slug')->count(),
    'referrer' => $nonEmpty($leads(), 'referrer')->count(),
    'user_id' => $leads()->whereNotNull('user_id')->count(),
    'magnet_channel' => $leads()->whereNotNull('magnet_channel')->count(),
    'any_signal' => $leads()->where(function ($q) {
        $q->where('utm_source', '!=', '')
            ->orWhere('source_article_slug', '!=', '')
            ->orWhere('referrer', '!=', '')
            ->orWhereNotNull('user_id')
            ->orWhereNotNull('magnet_channel');
    })->count(),
    'any_signal_no_magnet' => $leads()->where(function ($q) {
        $q->where('utm_source', '!=', '')
            ->orWhere('source_article_slug', '!=', '')
            ->orWhere('referrer', '!=', '')
            ->orWhereNotNull('user_id');
    })->count(),
    'top_utm_source' => $nonEmpty($leads(), 'utm_source')
        ->selectRaw('LOWER(utm_source) s, COUNT(*) n')->groupBy('s')->orderByDesc('n')->limit(12)->get(),
    'top_referrer_hosts' => $nonEmpty($leads(), 'referrer')
        ->selectRaw("SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '/', -1) h, COUNT(*) n")
        ->groupBy('h')->orderByDesc('n')->limit(12)->get(),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
