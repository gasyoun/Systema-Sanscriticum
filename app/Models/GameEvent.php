<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Одно анонимное событие воронки бесплатных тренажёров /exercises (H1360).
 *
 * Append-only, без updated_at (как ActivityEvent). Никакого student-идентификатора,
 * ни IP, ни user-agent — только короткий anon_id с клиента (см. миграцию).
 */
class GameEvent extends Model
{
    // Сырые события неизменяемы (append-only).
    public $timestamps = false;

    protected $fillable = [
        'anon_id',
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
}
