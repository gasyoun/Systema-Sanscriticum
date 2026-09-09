<?php

declare(strict_types=1);

namespace App\Services\Schedule;

/**
 * H4435 (MG 08-09/09-09): «Канва» — шкала учебника для грамматических курсов.
 *
 * МОДЕЛЬ v2 (MG, чат): ДВЕ шкалы, которые никогда не смешиваются —
 *  1) «наши занятия» (календарные сессии группы, нумерация «9-е занятие»);
 *  2) «уроки учебника» (Кочергина 1..40).
 * Связь — СОБЫТИЙНАЯ: одно наше занятие покрывает набор предметов канвы
 * («Занятие VII (читка), Эмено 1 (начало)» = 1 сессия, 2+ предмета).
 * Прогресс группы = КУРСОР по учебнику (макс. «читка»), НЕ пропорция сессий.
 * Вес урока эмпирический: середина книги — 2-5 наших занятий на урок;
 * КОНЕЦ ТЯЖЁЛЫЙ (правка MG 09-09) — урок у конца может занимать 2-3 наших
 * занятия, наше занятие покрывает часть урока. Никаких линейных «≈ N занятий».
 *
 * Источники: заголовки записей уроков (titles) — де-факто разметка кураторов
 * («Кочергина 3 (читка)», «Кочергина 7 (проверка), Кочергина 8 (читка)»);
 * колонка lessons.textbook_lesson — вторичный приоритет, когда заполнена.
 *
 * Эмено-бюджет: эталонно 5 наших занятий на полный курс (MG 09-09).
 */
final class TextbookScale
{
    /** @var array<string, array{pattern: string, total: int}> семейства канвы (config). */
    public static function families(): array
    {
        return config('edutech.canvas_families', self::DEFAULT_FAMILIES);
    }

    /** @var array<string, int> бюджеты ответвлений-источников в наших занятиях (эталон Гасунца). */
    public static function deviationBudgets(): array
    {
        return config('edutech.deviation_budgets', self::DEFAULT_BUDGETS);
    }

    private const DEFAULT_FAMILIES = [
        'kochergina' => ['pattern' => '/Кочергина\s+(\d+)\s*\(([^)]+)\)/u', 'total' => 40],
        'buhler' => ['pattern' => '/Бюллер\s+(\d+)\s*\(([^)]+)\)/u', 'total' => 40],
    ];

    private const DEFAULT_BUDGETS = [
        'emeno' => 5, // MG 09-09: «на Эмено эталонно уходит 5 занятий»
    ];

    /**
     * Разбор одного заголовка записи урока → предметы канвы.
     *
     * @return list<array{family: string, lesson: int, kind: string}> kind: chitka|proverka|drugoje
     */
    public static function parseTitle(string $title): array
    {
        $items = [];

        foreach (self::families() as $family => $cfg) {
            if (preg_match_all($cfg['pattern'], $title, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $lesson = (int) $m[1];
                $kindRaw = mb_strtolower(trim($m[2]));
                $kind = str_contains($kindRaw, 'читка') ? 'chitka' : (str_contains($kindRaw, 'проверка') ? 'proverka' : 'drugoje');
                $items[] = ['family' => $family, 'lesson' => $lesson, 'kind' => $kind];
            }
        }

        // Источники-ответвления (Эмено и пр.) — отдельный предмет канвы.
        if (preg_match_all('/Эмено\s*(\d+)/u', $title, $em, PREG_SET_ORDER)) {
            foreach ($em as $m) {
                $items[] = ['family' => 'emeno', 'lesson' => (int) $m[1], 'kind' => 'deviation'];
            }
        }

        return $items;
    }

    /**
     * Курсор группы по семейству: максимальный урок с «читка» (проверки и
     * подкрепления курсор не двигают — только чтение нового урока).
     */
    public static function cursor(iterable $lessons, string $family = 'kochergina'): int
    {
        $cursor = 0;
        foreach ($lessons as $lesson) {
            $tl = $lesson->textbook_lesson ?? null;
            if ($tl !== null && (int) $tl > $cursor && $family === 'kochergina') {
                // Заполненная колонка — прямой приоритет (H4387-семантика).
                $cursor = (int) $tl;

                continue;
            }
            foreach (self::parseTitle((string) $lesson->title) as $item) {
                if ($item['family'] === $family && $item['kind'] === 'chitka' && $item['lesson'] > $cursor) {
                    $cursor = $item['lesson'];
                }
            }
        }

        return $cursor;
    }

    /**
     * Эмпирический вес урока: сколько наших занятий его касалось.
     *
     * @return array<int, int> урок => число занятий, которые его затронули (любым видом)
     */
    public static function lessonWeights(iterable $lessons, string $family = 'kochergina'): array
    {
        $weights = [];
        foreach ($lessons as $lesson) {
            $touched = [];
            foreach (self::parseTitle((string) $lesson->title) as $item) {
                if ($item['family'] === $family) {
                    $touched[$item['lesson']] = true;
                }
            }
            foreach (array_keys($touched) as $ln) {
                $weights[$ln] = ($weights[$ln] ?? 0) + 1;
            }
        }
        ksort($weights);

        return $weights;
    }

    /**
     * Проекция «до конца учебника ≈ k наших занятий» по последним M урокам
     * этой группы (не глобальное среднее — у конца книги свой вес).
     *
     * @return array{remaining_lessons: int, recent_lessons: int, recent_sessions: int, projection_sessions: int}|null
     */
    public static function projection(iterable $lessons, int $cursor, int $total, string $family = 'kochergina', int $window = 5): ?array
    {
        $remaining = $total - $cursor;
        if ($remaining <= 0) {
            return null;
        }

        $weights = self::lessonWeights($lessons, $family);
        if ($weights === []) {
            return null;
        }

        // Последние M уроков, у которых есть вес (коснулись хотя бы раз).
        $recent = array_slice($weights, -$window, null, true);
        if ($recent === []) {
            return null;
        }

        $recentLessons = count($recent);
        $recentSessions = max(1, array_sum($recent)); // занятий, потраченных на эти уроки

        return [
            'remaining_lessons' => $remaining,
            'recent_lessons' => $recentLessons,
            'recent_sessions' => $recentSessions,
            // Конец тяжёлый: экстраполяция по фактическому весу последних уроков.
            'projection_sessions' => (int) ceil($remaining * $recentSessions / max(1, $recentLessons)),
        ];
    }

    /**
     * Канва-lag: разница КУРСОРОВ (в уроках) между группой и медианой семейства.
     * Положительная = группа опережает медиану.
     *
     * @param  array<int, int>  $cursors  course_id => cursor
     */
    public static function lag(int $cursor, array $cursors): int
    {
        $others = array_values(array_filter($cursors, fn ($v): bool => $v > 0));
        if ($others === []) {
            return 0;
        }
        sort($others);
        $n = count($others);
        $median = $n % 2 === 1
            ? $others[intdiv($n, 2)]
            : (int) floor(($others[$n / 2 - 1] + $others[$n / 2]) / 2);

        return $cursor - $median;
    }

    /** Семейство канвы курса по названию (Кочергина/Бюллер), null — не учебник. */
    public static function courseFamilyPublic(string $title): ?string
    {
        foreach (array_keys(self::families()) as $family) {
            $needle = $family === 'kochergina' ? 'Кочерг' : ($family === 'buhler' ? 'Бюллер' : $family);
            if (mb_stripos($title, $needle) !== false) {
                return $family;
            }
        }

        return null;
    }

    /** «Кочергина, 4-я читка» — человекочитаемая подпись последнего факта студента. */
    public static function label(string $family, int $lesson, string $kind = 'chitka'): string
    {
        $familyTitle = mb_strtoupper(mb_substr($family, 0, 1)).mb_substr($family, 1);
        $kindLabel = match ($kind) {
            'chitka' => 'читка',
            'proverka' => 'проверка',
            'deviation' => 'ответвление',
            default => 'занятие',
        };

        return $familyTitle.' '.$lesson.' ('.$kindLabel.')';
    }
}
