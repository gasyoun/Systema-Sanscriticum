<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Controllers\Api\GameTelemetryController;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Одно событие воронки бесплатных тренажёров /lila (H1360).
 *
 * Append-only, без updated_at (как ActivityEvent). Ни IP, ни user-agent —
 * только короткий anon_id с клиента (см. миграцию).
 *
 * H2553 §1 (R4-2, авторизовано MG 10-08-2026) — добавлен nullable `user_id`,
 * который отменяет прежний контракт «здесь вообще нет student-идентификатора»
 * (H1360/H1678). Колонку заполняет ТОЛЬКО сервер из web-сессии
 * ({@see GameTelemetryController::store}); аноним
 * остаётся NULL, клиент этой колонкой не управляет. Нужен для метрики
 * «сыграл → сдал ДЗ», которую флаг `authenticated` посчитать не может.
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
        'payload',
        'authenticated',
        // H2553 §1 (R4-2): пишет только сервер из web-сессии, аноним -> NULL.
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'authenticated' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Стадии воронки. Константы, а не «магические строки».
    public const START = 'start';               // тренажёр открыт (показ)

    public const COMPLETE = 'complete';         // раунд решён (.feedback.show)

    public const GATE_SHOWN = 'gate_shown';     // показана стена регистрации

    public const GATE_CTA_CLICK = 'gate_cta_click'; // клик «Начать бесплатно»

    // H1680 — увиденные в раунде леммы (payload.items), сид для onboarding-from-games.
    public const ITEM_SEEN = 'item_seen';

    // H4396 — серверный счётчик бюджета бесплатных игр: один round = один
    // завершённый раунд (тот же edge `.feedback.show`, что считает gate.js
    // локально). Отличается от COMPLETE (та телеметрия шлёт один раз на
    // ЗАГРУЗКУ страницы и остаётся воронкой) — бюджет считается по round,
    // поэтому перезагрузки страницы не сбрасывают и не дублируют счёт.
    public const ROUND = 'round';

    // H4692 — трудность по вопросам: один event на завершённый match-раунд,
    // payload = {hints: 0|1, items: [{l, r, ms, wrong}]}. ms — время от начала
    // раунда до первой связки пары, wrong — сколько «Проверить» оценили пару
    // неверно. Тексты l/r — контент тренажёра (без PII), как в item_seen.
    public const ITEM_RESULT = 'item_result';

    /** Белый список: всё, что не отсюда, приёмник отклоняет 422. */
    public const EVENTS = [
        self::START,
        self::COMPLETE,
        self::GATE_SHOWN,
        self::GATE_CTA_CLICK,
        self::ITEM_SEEN,
        self::ROUND,
        self::ITEM_RESULT,
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
     * click. This KPI stays computed from the `authenticated` flag alone:
     * knowing THAT a guest later authenticated is all it needs, so it must
     * not start joining on `user_id` (added later by H2553 §1 for a
     * different metric) — an anonymous clicker has NULL there, and using it
     * here would silently drop exactly the pre-registration rows the rate
     * is measuring. IP and user-agent remain absent by design (R20).
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

    /**
     * H4692 — агрегат трудности по вопросам за окно: одна строка на
     * (drill, band, текст правой пары), с числом раундов, медианой и средним
     * ms до первой связки пары и долей раундов, где пару хоть раз проверили
     * с ошибкой. Сортировка — самые трудные сверху (wrong_rate, затем медиана).
     * Агрегация в PHP: payload — JSON, объём — строка на раунд, портируемый
     * JSON-SQL ради внутреннего отчёта не нужен (тот же стиль, что funnel()).
     *
     * @return list<array{drill: string, band: ?string, item: string, rounds: int, median_ms: int, avg_ms: int, wrong_rate: float}>
     */
    public static function difficulty(\DateTimeInterface $since): array
    {
        $events = static::query()
            ->where('event', self::ITEM_RESULT)
            ->where('created_at', '>=', $since)
            ->get(['drill', 'band', 'payload']);

        $acc = [];
        foreach ($events as $ev) {
            $items = is_array($ev->payload) ? ($ev->payload['items'] ?? null) : null;
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)
                    || ! isset($item['r'])
                    || ! is_string($item['r'])
                    || $item['r'] === '') {
                    continue;
                }
                $key = $ev->drill.'|'.($ev->band ?? '').'|'.$item['r'];
                $acc[$key] ??= [
                    'drill' => (string) $ev->drill,
                    'band' => $ev->band !== null ? (string) $ev->band : null,
                    'item' => $item['r'],
                    'ms' => [],
                    'wrongRounds' => 0,
                    'rounds' => 0,
                ];
                $acc[$key]['rounds']++;
                $acc[$key]['ms'][] = is_numeric($item['ms'] ?? null) ? (int) $item['ms'] : 0;
                if (is_numeric($item['wrong'] ?? null) && (int) $item['wrong'] > 0) {
                    $acc[$key]['wrongRounds']++;
                }
            }
        }

        $rows = array_map(static function (array $a): array {
            $msList = $a['ms'];
            sort($msList);
            $n = count($msList);
            $median = $n % 2 === 1
                ? $msList[intdiv($n, 2)]
                : intdiv($msList[intdiv($n, 2) - 1] + $msList[intdiv($n, 2)], 2);

            return [
                'drill' => $a['drill'],
                'band' => $a['band'],
                'item' => $a['item'],
                'rounds' => $a['rounds'],
                'median_ms' => $median,
                'avg_ms' => (int) round(array_sum($msList) / max(1, $n)),
                'wrong_rate' => round($a['wrongRounds'] / max(1, $a['rounds']) * 100, 1),
            ];
        }, array_values($acc));

        usort($rows, fn (array $x, array $y): int => [$y['wrong_rate'], $y['median_ms']] <=> [$x['wrong_rate'], $x['median_ms']]);

        return $rows;
    }
}
