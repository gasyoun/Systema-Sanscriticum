<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Tariff;
use App\Support\CourseCadence;
use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Вердикт по КАЖДОЙ семье курсов каталога (H3773, остаток H3122).
 *
 * H3122 отвечал на вопрос «что можно удалить»: он искал пустые оболочки —
 * объекты без единой строки в ссылающихся таблицах. Этот аудит отвечает на
 * другой вопрос, который тем разбором не закрывается: «сколько строк `courses`
 * описывают ОДНУ программу и почему».
 *
 * Разница видна на «Кашмирском шиваизме» (332/375/424): три строки, ни одна не
 * пустая, удалять нельзя ни одну — и всё же это ОДИН курс в трёх потоках.
 * Витрина и SEO обязаны знать, что это одна программа, а отчётность — никогда
 * не складывать потоки между собой (семантика
 * {@see CourseCadence}). Наоборот, «Караки по Панини 2025-2026 в
 * записи» (421) при живом 335 — не поток, а осевший дубль.
 *
 * Вердиктов ровно три:
 *
 *   - `unique`    — в семье одна строка. Ничего не требуется.
 *   - `streams`   — несколько строк, и КАЖДАЯ отличима как самостоятельный
 *                   поток: у неё есть собственные данные (роль live/recording,
 *                   {@see CourseFamilyMatcher::streamRole()}) и собственный
 *                   ключ потока — номер из названия либо дата первого платежа
 *                   ({@see CourseFamilyMatcher::ordinalFor()}). Законно.
 *   - `duplicate` — несколько строк, и хотя бы одна не отличима: либо у неё
 *                   нет ни блоков, ни тарифов, ни оплат (роль `unknown` —
 *                   осевшая копия), либо две строки претендуют на один и тот
 *                   же поток. Требует разбора человеком.
 *
 * Порог намеренно строгий в пользу «duplicate»: ложный `duplicate` стоит одного
 * взгляда админа, ложный `streams` прячет дубль от витрины насовсем.
 *
 * Аудит ТОЛЬКО ЧИТАЕТ. Ни одной записи в `courses`/`tariffs` он не делает —
 * консолидация, когда до неё дойдёт дело, будет отдельной командой поверх этого
 * вердикта, как удаление было отдельным от {@see CatalogShellAudit}.
 */
class CatalogFamilyAudit
{
    public const VERDICT_UNIQUE = 'unique';

    public const VERDICT_STREAMS = 'streams';

    public const VERDICT_DUPLICATE = 'duplicate';

    /** Член семьи без единой собственной строки данных — осевшая копия. */
    public const CLASS_EMPTY_SHELL = 'empty_shell';

    /** Живой поток и его же запись, проданная отдельной строкой под тем же номером. */
    public const CLASS_RECORDING_TWIN = 'recording_twin';

    /** Общий ключ потока без признака «в записи» — два потока просто неразличимы. */
    public const CLASS_STREAM_COLLISION = 'stream_collision';

    public function __construct(private readonly CourseFamilyMatcher $families) {}

    /**
     * Одна строка на семью, отсортированные: сначала требующие разбора.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $rows = [];

        foreach ($this->membersByFamily() as $family => $members) {
            $rows[] = $this->verdictFor((string) $family, $members);
        }

        // Сначала duplicate, затем streams, затем unique; внутри — по слагу
        // семьи, чтобы отчёт не переставлялся от прогона к прогону.
        $weight = [self::VERDICT_DUPLICATE => 0, self::VERDICT_STREAMS => 1, self::VERDICT_UNIQUE => 2];
        usort($rows, fn (array $a, array $b) => [$weight[$a['verdict']], $a['family']] <=> [$weight[$b['verdict']], $b['family']]);

        return $rows;
    }

    /**
     * Курсы, разложенные по семьям. Курс с невыводимой семьёй (пустой слаг
     * после снятия хвостов) встаёт в собственную семью под ключом `#<id>`: в
     * общую «мусорную» семью такие сваливать нельзя — они схлопнули бы разные
     * программы в одну строку отчёта.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function membersByFamily(): array
    {
        $byFamily = [];

        foreach (Course::query()->orderBy('id')->get() as $course) {
            $family = $this->families->familyFor($course);
            $key = $family !== '' ? $family : '#'.$course->id;

            $byFamily[$key][] = $this->member($course);
        }

        ksort($byFamily);

        return $byFamily;
    }

    /**
     * Данные одного курса-члена семьи. Всё, что попадает в колонку evidence
     * отчёта, собирается здесь — вердикт ниже не ходит в базу повторно.
     *
     * @return array<string, mixed>
     */
    private function member(Course $course): array
    {
        $blocks = $course->blocks()->count();
        $activeTariffs = $course->tariffs()->where('is_active', true)->count();
        $paidPayments = $course->payments()->paid()->count();

        $firstPaidAt = $this->firstPaidAt($course);
        [$ordinal, $ordinalKey] = $this->families->ordinalFor((string) $course->title, $firstPaidAt);

        return [
            'id' => $course->id,
            'title' => (string) $course->title,
            'slug' => (string) $course->slug,
            'url' => '/k/'.$course->slug,
            'format' => $course->format,
            'visible' => (bool) $course->is_visible,
            'manual_family' => trim((string) ($course->course_family ?? '')) !== '',
            'blocks' => $blocks,
            'active_tariffs' => $activeTariffs,
            'paid_payments' => $paidPayments,
            'enrolled' => $course->users()->count(),
            'first_paid_at' => $firstPaidAt?->format('Y-m-d'),
            'role' => $this->families->streamRole($blocks, $activeTariffs, $paidPayments),
            'ordinal' => $ordinal,
            'ordinal_key' => $ordinalKey,
            'tariff_keys' => $this->tariffKeys($course),
            'groups' => $this->scheduleGroups($course),
        ];
    }

    /**
     * Дата первого ОПЛАЧЕННОГО платежа. Опора — `first_paid_at`, где он
     * проставлен (H1645), иначе `created_at`: аудит только упорядочивает
     * потоки, и час-два разницы вердикт не меняют.
     */
    private function firstPaidAt(Course $course): ?Carbon
    {
        $row = $course->payments()->paid()
            ->selectRaw('MIN(COALESCE(first_paid_at, created_at)) AS at')
            ->value('at');

        return $row !== null ? Carbon::parse((string) $row) : null;
    }

    /**
     * Ключи доступа тарифов курса — та же нотация, что уходит в
     * `payments.tariff` ({@see Tariff::accessKey()}). Именно по ним
     * человек видит, разъехались ли копии по продаваемому объёму.
     *
     * @return list<string>
     */
    private function tariffKeys(Course $course): array
    {
        $keys = [];

        foreach ($course->tariffs()->where('is_active', true)->get() as $tariff) {
            $keys[] = (string) $tariff->accessKey();
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Учебные группы, привязанные к курсу, — второй признак настоящего потока:
     * у своего потока свой набор, у осевшего дубля обычно пусто.
     *
     * @return list<string>
     */
    private function scheduleGroups(Course $course): array
    {
        return DB::table('course_group')
            ->join('groups', 'groups.id', '=', 'course_group.group_id')
            ->where('course_group.course_id', $course->id)
            ->orderBy('groups.id')
            ->pluck('groups.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * Вердикт по семье + причины, из-за которых он именно такой.
     *
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function verdictFor(string $family, array $members): array
    {
        if (count($members) === 1) {
            return [
                'family' => $family,
                'verdict' => self::VERDICT_UNIQUE,
                'reasons' => [],
                'classes' => [],
                'members' => $members,
                'follow_up' => null,
            ];
        }

        $reasons = [];
        $classes = [];

        // 1. Член без единого собственного признака — осевшая копия.
        foreach ($members as $member) {
            if ($member['role'] === CourseFamilyMatcher::ROLE_UNKNOWN) {
                $reasons[] = sprintf(
                    'курс %d «%s» — ни блоков, ни активных тарифов, ни оплат: самостоятельным потоком не является',
                    $member['id'],
                    $member['title'],
                );
                $classes[] = self::CLASS_EMPTY_SHELL;
            }
        }

        // 2. Два члена претендуют на один и тот же поток.
        $seen = [];
        foreach ($members as $member) {
            $seen[$member['ordinal_key']][] = $member['id'];
        }
        foreach ($seen as $key => $ids) {
            if (count($ids) > 1) {
                $reasons[] = sprintf(
                    'курсы %s неотличимы как потоки (общий ключ потока «%s»): ни номер в названии, ни дата первого платежа их не разводят',
                    implode(', ', $ids),
                    $key,
                );

                // Подкласс, ради которого различение и заведено: столкнувшиеся
                // курсы — это ЖИВОЙ поток и его же ЗАПИСЬ, проданная отдельной
                // строкой каталога под тем же номером потока. Удалять там
                // нечего (у записи свои оплаты), а витрина и SEO всё равно
                // показывают одну программу дважды — это правка карточек, а не
                // чистка базы, и путать её с оболочкой нельзя.
                $classes[] = $this->collisionIsRecordingTwin($members, $ids)
                    ? self::CLASS_RECORDING_TWIN
                    : self::CLASS_STREAM_COLLISION;
            }
        }

        $verdict = $reasons === [] ? self::VERDICT_STREAMS : self::VERDICT_DUPLICATE;

        return [
            'family' => $family,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'classes' => array_values(array_unique($classes)),
            'members' => $members,
            'follow_up' => $verdict === self::VERDICT_DUPLICATE
                ? 'разобрать вручную: свести витрину и SEO на один курс семьи, записи переносить только после `catalog:audit-shells` (он проверяет, не отнимет ли удаление у человека единственную запись)'
                : null,
        ];
    }

    /**
     * Столкнулись ли живой поток и его собственная запись: среди курсов с общим
     * ключом потока есть и помеченный «в записи», и не помеченный.
     *
     * Признак читается из названия и слага, а не из роли: запись прошлого
     * потока обычно вполне жива (свои блоки, тарифы и оплаты — курс 327 из
     * «Йога-сутр» продан 129 раз), поэтому роль её от живого потока не
     * отличает. Отличает ровно то, что человек написал в названии.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  list<int>  $ids
     */
    private function collisionIsRecordingTwin(array $members, array $ids): bool
    {
        $recording = 0;
        $plain = 0;

        foreach ($members as $member) {
            if (! in_array($member['id'], $ids, true)) {
                continue;
            }

            $haystack = mb_strtolower($member['title'].' '.$member['slug']);
            if (str_contains($haystack, 'в записи') || str_contains($haystack, 'v-zapisi')) {
                $recording++;
            } else {
                $plain++;
            }
        }

        return $recording > 0 && $plain > 0;
    }
}
