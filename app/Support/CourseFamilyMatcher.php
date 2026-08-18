<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * H3083 — определение «семьи потоков» по названию курса.
 *
 * Чистый класс: в БД не ходит, поэтому покрывается юнит-тестами без миграций.
 * Порядок разрешения семьи на курсе (см. §3 ARCHITECTURE):
 *
 *   1. заполненный `courses.course_family` побеждает ВСЕГДА — человек прав по
 *      определению, авто его не перетирает;
 *   2. иначе название нормализуется (снимаются хвосты «(N поток, ГОД)»,
 *      «часть N», «ГОД в записи», «в записи») и переводится в слаг;
 *   3. совпали слаги — одна семья.
 *
 * Транслитерация — штатный `Str::slug` без языковой карты: именно он породил
 * боевые слаги курсов (`kasmirskii-sivaizm-2025`), и семья обязана читаться в
 * той же азбуке, иначе `course_family` и `slug` разъедутся на глазах у админа.
 */
final class CourseFamilyMatcher
{
    /** Живой поток: есть блоки и активные тарифы. */
    public const ROLE_LIVE = 'live';

    /** Курс-запись: ни блоков, ни тарифов, но есть оплаченные платежи. */
    public const ROLE_RECORDING = 'recording';

    /** Роль не выводится (нет ни блоков, ни тарифов, ни оплат). */
    public const ROLE_UNKNOWN = 'unknown';

    /**
     * Хвосты названия, не относящиеся к содержанию курса. Порядок важен:
     * «2025 в записи» должно сняться раньше одинокого года.
     *
     * @var list<string>
     */
    private const TAIL_PATTERNS = [
        // «(1 поток, 2025)», «(2 поток)», «(поток 3, 2026)» — со скобками
        '/\(\s*\d+\s*[-й]*\s*поток[^)]*\)/iu',
        '/\(\s*поток\s*\d+[^)]*\)/iu',
        // «(часть 2, 2026)», «(часть 2)»
        '/\(\s*часть\s*\d+[^)]*\)/iu',
        // «(2025)», «(2025 в записи)» — скобка, начинающаяся с года
        '/\(\s*(?:19|20)\d{2}[^)]*\)/u',
        // те же хвосты без скобок
        '/\b\d+\s*[-й]*\s*поток\b/iu',
        '/\bпоток\s*\d+\b/iu',
        '/\bчасть\s*\d+\b/iu',
        // «2025 в записи», «в записи»
        '/\b(?:19|20)\d{2}\s+в\s+записи\b/iu',
        '/\bв\s+записи\b/iu',
        // одинокий год в конце названия
        '/\b(?:19|20)\d{2}\b/u',
    ];

    /**
     * Слаг семьи, выведенный из названия курса. Пустая строка = вывести не
     * удалось (после снятия хвостов не осталось ни одной буквы) — такой курс в
     * семью не встаёт, чтобы «мусорная» семья не схлопнула разные курсы.
     */
    public function familySlug(string $title): string
    {
        $normalized = $title;

        foreach (self::TAIL_PATTERNS as $pattern) {
            $normalized = (string) preg_replace($pattern, ' ', $normalized);
        }

        // Осиротевшие разделители после снятия хвостов: «Курс —  », «Курс, ».
        $normalized = (string) preg_replace('/[\s,;:—–\-]+$/u', '', trim($normalized));

        return Str::slug($normalized);
    }

    /**
     * Семья курса: ручное значение, если оно есть, иначе вывод из названия.
     */
    public function familyFor(Course $course): string
    {
        $manual = trim((string) ($course->course_family ?? ''));

        return $manual !== '' ? $manual : $this->familySlug((string) $course->title);
    }

    /**
     * Роль потока внутри семьи.
     *
     * Признак `recording` намеренно узкий (ни блоков, ни тарифов, но оплаты
     * есть): у курса с временно выключенными тарифами блоки остаются, и он
     * по-прежнему `live`. Это тот же вывод, что описан в §5 VERIFICATION.
     *
     * @param  int  $blocksCount  сколько у курса блоков
     * @param  int  $tariffsCount  сколько у него АКТИВНЫХ тарифов
     * @param  int  $paidPaymentsCount  сколько оплаченных платежей
     */
    public function streamRole(int $blocksCount, int $tariffsCount, int $paidPaymentsCount): string
    {
        if ($blocksCount > 0 || $tariffsCount > 0) {
            return self::ROLE_LIVE;
        }

        return $paidPaymentsCount > 0 ? self::ROLE_RECORDING : self::ROLE_UNKNOWN;
    }

    /**
     * Порядковый номер потока: из «(N поток…)» / «часть N», иначе — 0.
     *
     * Ноль означает «номер из названия не читается»; вызывающий код
     * упорядочивает такие потоки по дате первого платежа (см. ordinalFor).
     */
    public function ordinal(string $title): int
    {
        if (preg_match('/(\d+)\s*[-й]*\s*поток/iu', $title, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/поток\s*(\d+)/iu', $title, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/часть\s*(\d+)/iu', $title, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Номер потока с опорой на дату первого платежа, когда в названии его нет.
     * Возвращает пару [номер, ключ сортировки]: ключ нужен, чтобы потоки без
     * номера («…2025 в записи») не слипались в один и упорядочивались по дате.
     *
     * @return array{int, string}
     */
    public function ordinalFor(string $title, ?Carbon $firstPaymentAt): array
    {
        $ordinal = $this->ordinal($title);

        if ($ordinal > 0) {
            return [$ordinal, sprintf('%03d', $ordinal)];
        }

        // Без номера в названии сортируем по дате первого платежа, после
        // всех пронумерованных потоков.
        return [0, '900-'.($firstPaymentAt?->format('Y-m-d') ?? '9999-99-99')];
    }
}
