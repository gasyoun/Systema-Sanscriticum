<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Одно анонимное событие воронки бесплатных тренажёров /lila (H1360).
 *
 * Append-only, без updated_at (как ActivityEvent). Ни IP, ни user-agent —
 * только короткий anon_id с клиента (см. миграцию create_game_events_table).
 *
 * `user_id` (H1680, nullable) — 152-FZ периметр это не расширяет: это то же
 * самое "уже аутентифицированный пользователь получает свою же запись", что
 * и везде в приложении. Ставится ТОЛЬКО когда сессия аутентифицирована
 * (GameTelemetryController — на новой записи; GamesOnboardingImporter —
 * бэкафиллом на прежние anon_id-строки при первом логине). Нужен для H1680
 * SRS-онбординга ("какие паки этот пользователь реально доиграл до конца") —
 * до H1680 такой связи не было вовсе.
 */
class GameEvent extends Model
{
    // Сырые события неизменяемы (append-only).
    public $timestamps = false;

    protected $fillable = [
        'anon_id',
        'user_id',
        'drill',
        'band',
        'event',
        'authenticated',
        'created_at',
    ];

    protected $casts = [
        'authenticated' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Стадии воронки. Константы, а не «магические строки».
    public const START = 'start';               // тренажёр открыт (показ)

    public const COMPLETE = 'complete';         // раунд решён (.feedback.show)

    public const GATE_SHOWN = 'gate_shown';     // показана стена регистрации

    public const GATE_CTA_CLICK = 'gate_cta_click'; // клик «Начать бесплатно»

    /** Белый список: всё, что не отсюда, приёмник отклоняет 422. */
    public const EVENTS = [
        self::START,
        self::COMPLETE,
        self::GATE_SHOWN,
        self::GATE_CTA_CLICK,
    ];

    /**
     * Агрегат воронки за окно [$since, now): по одной строке на (drill, band),
     * с раскладкой plays -> completes -> walls -> CTA. Один источник правды для
     * команды games:funnel и Filament-страницы GamesFunnel.
     *
     * CASE-суммы (а не `event = 'x'`) — ради переносимости MySQL/SQLite.
     *
     * @return array<int, array{drill:string, band:?string, plays:int, completes:int, walls:int, cta:int}>
     */
    public static function funnel(\DateTimeInterface $since): array
    {
        return static::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('drill, band')
            ->selectRaw('SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) AS plays', [self::START])
            ->selectRaw('SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) AS completes', [self::COMPLETE])
            ->selectRaw('SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) AS walls', [self::GATE_SHOWN])
            ->selectRaw('SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) AS cta', [self::GATE_CTA_CLICK])
            ->groupBy('drill', 'band')
            ->orderByDesc('plays')
            ->get()
            ->map(fn ($row): array => [
                'drill' => (string) $row->drill,
                'band' => $row->band !== null ? (string) $row->band : null,
                'plays' => (int) $row->plays,
                'completes' => (int) $row->completes,
                'walls' => (int) $row->walls,
                'cta' => (int) $row->cta,
            ])
            ->all();
    }

    /**
     * Play -> register KPI (H1678, locked D6/D10): among distinct anon_id
     * that clicked the register CTA in the window, the share that later
     * carries an `authenticated=true` event within 7 days of their first
     * click. This is the guest -> user "merge" signal, computed from the
     * existing `authenticated` flag (not `user_id`) since it only needs to
     * know THAT a guest later authenticated, not WHO they are — kept this
     * way even after H1680 added `user_id` for the onboarding-import use
     * case, since this KPI has no reason to touch it.
     *
     * @return array{clickers:int, registered:int, rate:?float, baseline_only:bool}
     */
    public static function ctaRegistrationRate(\DateTimeInterface $since): array
    {
        $firstClicks = static::query()
            ->where('event', self::GATE_CTA_CLICK)
            ->where('created_at', '>=', $since)
            ->whereNotNull('anon_id')
            ->selectRaw('anon_id, MIN(created_at) as first_click')
            ->groupBy('anon_id')
            ->pluck('first_click', 'anon_id');

        $clickers = $firstClicks->count();

        if ($clickers === 0) {
            return ['clickers' => 0, 'registered' => 0, 'rate' => null, 'baseline_only' => true];
        }

        $registered = 0;
        foreach ($firstClicks as $anonId => $firstClick) {
            $mergedIn = static::query()
                ->where('anon_id', $anonId)
                ->where('authenticated', true)
                ->where('created_at', '>=', $firstClick)
                ->where('created_at', '<=', Carbon::parse($firstClick)->addDays(7))
                ->exists();

            if ($mergedIn) {
                $registered++;
            }
        }

        return [
            'clickers' => $clickers,
            'registered' => $registered,
            'rate' => round($registered / $clickers * 100, 1),
            'baseline_only' => $clickers < 50,
        ];
    }
}
