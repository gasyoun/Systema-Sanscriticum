<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameEvent;
use App\Models\LilaScoreEvent;
use App\Support\GameLemmaFence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Первопартийный приёмник событий воронки бесплатных тренажёров /lila (H1360).
 *
 * POST /api/games/event { anon_id?, drill, band?, event } — публичный (web-guard,
 * потому что флаг authenticated берётся из браузерной сессии, а не с клиента),
 * с throttle. Живёт рядом с probe /api/games/auth (routes/web.php).
 *
 * Приватность (R20): в БД не попадают ни IP, ни user-agent — только короткий
 * anon_id, очищенный до [A-Za-z0-9]{0,32}, так что PII туда физически не пройдёт.
 *
 * Неизвестное имя события -> 422 (в отличие от кабинетной телеметрии, где старые
 * вкладки шлют устаревшие имена; здесь клиент наш и список закрыт). Любой сбой
 * ЗАПИСИ проглатывается (телеметрия не имеет права ломать страницу тренажёра),
 * как CabinetTelemetry::emit.
 */
class GameTelemetryController extends Controller
{
    /**
     * H3315 — серверная таблица очков /lila по типу события. Единственный
     * источник очков лидерборда: payload.score клиента больше не читается
     * вовсе (раньше им можно было накрутить ранг вплоть до cap 500/событие).
     * COMPLETE сохраняет прежний базовый тариф 10 (= config
     * leaderboards.lila_complete_points, нигде не переопределявшийся);
     * остальные события в борд не пишутся (0) — место в таблице под них
     * зарезервировано явно.
     */
    private const EVENT_POINTS = [
        GameEvent::START => 0,
        GameEvent::COMPLETE => 10,
        GameEvent::GATE_SHOWN => 0,
        GameEvent::GATE_CTA_CLICK => 0,
        GameEvent::ITEM_SEEN => 0,
        // H4396 — бюджетный счётчик бесплатных раундов; в борд не пишется.
        GameEvent::ROUND => 0,
    ];

    public function store(Request $request): JsonResponse
    {
        $event = (string) $request->input('event', '');

        if (! in_array($event, GameEvent::EVENTS, true)) {
            return response()->json(['error' => 'unknown event'], 422);
        }

        $drill = $this->slug($request->input('drill'), 40) ?? 'unknown';
        $band = $this->slug($request->input('band'), 40);
        $user = $request->user();
        $anonId = $this->anonId($request);

        // H5087 (remediation of H5046): запоминаем anon_id, реально присланный
        // ЭТОЙ браузерной сессией — srs-onboarding-import примет только такие
        // (привязка к вызывающему вместо голой клиентской заявки). Приватность
        // дизайна сохранена: телеметрия остаётся fire-and-forget без
        // серверных идентификаторов; в сессии — сам анонимный слаг, не PII.
        $this->rememberAnonId($request, $anonId);

        try {
            GameEvent::create([
                'anon_id' => $anonId,
                'drill' => $drill,
                'band' => $band,
                'event' => $event,
                'payload' => $this->payload($request, $event),
                // Сервер — единственный источник правды по залогиненности:
                // клиент не может это подделать.
                'authenticated' => $user !== null,
                // H2553 §1 (R4-2) — рельс метрики «сыграл → сдал ДЗ». Пишет ТОЛЬКО
                // сервер из web-сессии, у анонима остаётся NULL: клиент этой
                // колонкой не управляет, PII-периметр не расширяется.
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GameTelemetry write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        // H2052 — authenticated completes feed the /lila leaderboards (separate
        // table so game_events stay anonymous / 152-FZ clean).
        //
        // H3315 — очки СЕРВЕРНЫЕ: таблица EVENT_POINTS по типу события,
        // клиентский payload.score игнорируется полностью. Cap 500 оставлен
        // как страховка (belt-and-braces) на случай будущего роста тарифов.
        if ($user !== null
            && $event === GameEvent::COMPLETE
            && Schema::hasTable('lila_score_events')) {
            try {
                $points = min(500, self::EVENT_POINTS[$event] ?? 0);
                LilaScoreEvent::create([
                    'user_id' => $user->id,
                    'drill' => $drill,
                    'band' => $band,
                    'points' => max(1, $points),
                    'event' => $event,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('LilaScoreEvent write failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // fire-and-forget: клиент шлёт sendBeacon и не ждёт тела.
        return response()->json(null, 204);
    }

    /** anon_id -> только [A-Za-z0-9], максимум 32 символа; иначе null. Отсекает любую PII. */
    private function anonId(Request $request): ?string
    {
        $raw = (string) $request->input('anon_id', '');
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, 32);
    }

    /**
     * H5087: список anon_id, реально присыланных этой сессией (последние 10) —
     * источник привязки для /api/games/srs-onboarding-import.
     */
    private function rememberAnonId(Request $request, ?string $anonId): void
    {
        if ($anonId === null) {
            return;
        }

        $known = (array) $request->session()->get('games_anon_ids', []);
        $known[] = $anonId;
        $request->session()->put(
            'games_anon_ids',
            array_slice(array_values(array_unique($known)), -10),
        );
    }

    /** Короткий слаг-ИДЕНТИФИКАТОР (drill/band): только [A-Za-z0-9_-] — как LilaGateController::family(); пустое/не-скаляр -> null. */
    private function slug(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        // H5087: идентификаторы — строгий ASCII-набор, любой другой символ
        // вырезается (раньше проходил произвольный текст).
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /**
     * H5087 (remediation of H5046): контентная строка тренажёра (лемма IAST /
     * русский перевод) — charset-фенс {@see GameLemmaFence}: буквы/цифры
     * Unicode, пробел, дефис, апостроф. Формульные и разметочные символы
     * вырезаются ДО записи: эти строки позже попадают в общую системную
     * SRS-колоду и публичный словарь.
     */
    private function lemma(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $clean = GameLemmaFence::clean((string) $value);

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /**
     * H1680 — `item_seen` только: до 20 {iast, ru} пар, обе стороны отрезаны
     * до безопасной длины через тот же {@see slug()}. H4692 добавил
     * `item_result` ({@see itemResultPayload()}). Любое другое имя
     * события или отсутствие валидного payload -> null (не пишем
     * пустой json без нужды).
     */
    private function payload(Request $request, string $event): ?array
    {
        if ($event === GameEvent::ITEM_SEEN) {
            return $this->itemSeenPayload($request);
        }

        if ($event === GameEvent::ITEM_RESULT) {
            return $this->itemResultPayload($request);
        }

        return null;
    }

    /**
     * H1680 — до 20 {iast, ru} пар, iast обязателен.
     *
     * @return array{items: list<array{iast:string, ru:string}>}|null
     */
    private function itemSeenPayload(Request $request): ?array
    {
        $items = $request->input('payload.items');
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach (array_slice($items, 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $iast = $this->lemma($item['iast'] ?? null, 64);
            if ($iast === null) {
                continue;
            }
            $clean[] = ['iast' => $iast, 'ru' => $this->lemma($item['ru'] ?? null, 160) ?? ''];
        }

        return $clean === [] ? null : ['items' => $clean];
    }

    /**
     * H4692 — до 40 {l, r, ms, wrong} строк на завершённый match-раунд.
     * l/r — тексты пар (контент тренажёра, без PII), обрезаны тем же
     * {@see slug()}; ms зажат 0..3 600 000 (час), wrong 0..50; hints —
     * флаг подсказок, нормализуется до 0|1.
     *
     * @return array{hints: int, items: list<array{l: string, r: string, ms: int, wrong: int}>}|null
     */
    private function itemResultPayload(Request $request): ?array
    {
        $items = $request->input('payload.items');
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach (array_slice($items, 0, 40) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $l = $this->lemma($item['l'] ?? null, 160);
            $r = $this->lemma($item['r'] ?? null, 160);
            if ($l === null || $r === null) {
                continue;
            }
            $clean[] = [
                'l' => $l,
                'r' => $r,
                'ms' => max(0, min(3_600_000, (int) ($item['ms'] ?? 0))),
                'wrong' => max(0, min(50, (int) ($item['wrong'] ?? 0))),
            ];
        }

        if ($clean === []) {
            return null;
        }

        return [
            'hints' => (int) $request->input('payload.hints') === 1 ? 1 : 0,
            'items' => $clean,
        ];
    }
}
