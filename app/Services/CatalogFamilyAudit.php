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

    /**
     * Скрытый с витрины курс, который ПРОДАЁТСЯ по прямой ссылке куратора
     * (H3812/H3820).
     *
     * Не аномалия и не мусор, а рабочий приём школы: куратор посылает
     * доверенному студенту ссылку на запись, ограниченную по времени. Механика:
     * `/checkout/{tariff}` связывает ТАРИФ и никогда не читает
     * `Course.is_visible` — единственное, что открывает и закрывает продажу,
     * это `tariffs.is_active`. Поэтому «курс скрыт, значит продаться не может»
     * — ложный вывод; 31-08-2026 по нему погасили пять активных тарифов курса
     * 327 («Йога-сутры … в записи», 129 оплат), и продажу пришлось
     * восстанавливать на проде.
     *
     * Класс существует, чтобы отчёт НИКОГДА не звал такую строку прибрать:
     * ни `catalog:retire-shell`, ни «скрыть вторую карточку», ни гашение
     * тарифов к ней не применимы.
     */
    public const CLASS_CURATOR_GATED_SALE = 'curator_gated_sale';

    /** Столкновение видно покупателю: обе строки открыты на витрине. */
    public const EXPOSURE_PUBLIC = 'public';

    /** Лишние строки скрыты с витрины (404) — расходится только модель данных. */
    public const EXPOSURE_INTERNAL = 'internal';

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
        // Сначала то, что ВИДИТ покупатель: `duplicate` на витрине — работа на
        // сегодня, скрытый `duplicate` — запись в модели данных, и держать их в
        // одной куче значит хоронить срочное под несрочным.
        $weight = [self::VERDICT_DUPLICATE => 0, self::VERDICT_STREAMS => 1, self::VERDICT_UNIQUE => 2];
        usort($rows, fn (array $a, array $b) => [
            $weight[$a['verdict']],
            $a['exposure'] === self::EXPOSURE_PUBLIC ? 0 : 1,
            $a['family'],
        ] <=> [
            $weight[$b['verdict']],
            $b['exposure'] === self::EXPOSURE_PUBLIC ? 0 : 1,
            $b['family'],
        ]);

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
            // H3807: человек уже сказал, что этот курс — ЗАПИСЬ вон того.
            // Столкновение потоков тогда разобрано, а не проглядено.
            'recording_of' => $course->recording_of_course_id !== null
                ? (int) $course->recording_of_course_id
                : null,
            'blocks' => $blocks,
            'active_tariffs' => $activeTariffs,
            // Скрыт с витрины, но КУПИТЬ его можно: `/checkout/{tariff}` берёт
            // тариф, а не курс. См. self::CLASS_CURATOR_GATED_SALE.
            'curator_gated_sale' => ! (bool) $course->is_visible
                && (bool) $course->is_active
                && $activeTariffs >= 1,
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
        // Курируемая продажа по прямой ссылке — свойство ОТДЕЛЬНОЙ строки, а не
        // столкновения, поэтому считается до всякого разбора семьи и одинаково
        // для семьи из одной строки и из пяти.
        $curatorGated = $this->curatorGatedIds($members);

        if (count($members) === 1) {
            return [
                'family' => $family,
                'verdict' => self::VERDICT_UNIQUE,
                'reasons' => [],
                'classes' => $curatorGated !== [] ? [self::CLASS_CURATOR_GATED_SALE] : [],
                'exposure' => self::EXPOSURE_INTERNAL,
                'members' => $members,
                'follow_up' => $this->curatorGatedNote($curatorGated),
            ];
        }

        $reasons = [];
        $classes = $curatorGated !== [] ? [self::CLASS_CURATOR_GATED_SALE] : [];
        $publicCollision = false;

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
                // H3807 (рулинг MG «одна карточка на программу», 31-08-2026):
                // столкновение РАЗОБРАНО, если каждая лишняя строка названа
                // записью другой строки того же столкновения. Тогда витрина
                // показывает одну карточку, канон один, и звать это `duplicate`
                // значит слать человека чинить уже починенное. Тот же принцип,
                // что и у `course_family`: сказанное человеком побеждает вывод.
                if ($this->collisionResolvedByRecordingLinks($members, $ids)) {
                    continue;
                }

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

                // Видит ли столкновение ПОКУПАТЕЛЬ. Скрытая с витрины строка
                // отдаёт 404 (ShopController::show), в каталог не попадает и в
                // sitemap не выходит — то есть «одна программа дважды» для неё
                // просто не наступает.
                if ($this->visibleCount($members, $ids) >= 2) {
                    $publicCollision = true;
                }
            }
        }

        $verdict = $reasons === [] ? self::VERDICT_STREAMS : self::VERDICT_DUPLICATE;
        $exposure = $publicCollision ? self::EXPOSURE_PUBLIC : self::EXPOSURE_INTERNAL;

        return [
            'family' => $family,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'classes' => array_values(array_unique($classes)),
            'exposure' => $exposure,
            'members' => $members,
            'follow_up' => $this->followUp($verdict, $exposure, $curatorGated),
        ];
    }

    /**
     * ID членов семьи, которые продаются по прямой ссылке куратора при скрытой
     * витрине.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<int>
     */
    private function curatorGatedIds(array $members): array
    {
        $ids = [];

        foreach ($members as $member) {
            if ($member['curator_gated_sale'] === true) {
                $ids[] = (int) $member['id'];
            }
        }

        return $ids;
    }

    /**
     * Предупреждение, которое обязано пережить любой вердикт: эти строки
     * ПРОДАЮТСЯ, и трогать их нельзя. Формулировка намеренно называет механику
     * целиком — сессия, которая 31-08-2026 погасила тарифы курса 327, знала и
     * про скрытость, и про тарифы, но не про то, что checkout смотрит на второе.
     *
     * @param  list<int>  $curatorGated
     */
    private function curatorGatedNote(array $curatorGated): ?string
    {
        if ($curatorGated === []) {
            return null;
        }

        return sprintf(
            'НЕ ТРОГАТЬ: курс%s %s скрыт%s с витрины, но продаётся — `/checkout/{tariff}` связывает ТАРИФ и не читает `Course.is_visible`, '
            .'так что активные тарифы держат продажу по прямой ссылке куратора (ограниченная по времени продажа записи доверенному студенту). '
            .'Ни `catalog:retire-shell`, ни скрытие, ни гашение тарифов сюда не применимы: `tariffs.is_active` — гейт ПОКУПКИ, а не доступа',
            count($curatorGated) > 1 ? 'ы' : '',
            implode(', ', $curatorGated),
            count($curatorGated) > 1 ? 'ы' : '',
        );
    }

    /**
     * Сколько из перечисленных курсов реально видны покупателю.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  list<int>  $ids
     */
    private function visibleCount(array $members, array $ids): int
    {
        $n = 0;

        foreach ($members as $member) {
            if (in_array($member['id'], $ids, true) && $member['visible']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Что делать с семьёй — и это РАЗНЫЕ работы, поэтому текст ветвится.
     *
     * 31-08-2026 первая версия аудита звала «свести витрину и SEO» для всех
     * четырёх боевых `duplicate`, а на проде три из них были скрыты с витрины и
     * отдавали 404: покупатель не видел ни одной пары дважды. Совет посылал
     * человека чинить то, чего нет. Вердикт `duplicate` про МОДЕЛЬ данных, и
     * сам по себе он ничего не говорит о витрине — экспозицию надо смотреть
     * отдельно.
     *
     * Второй слой (H3812/H3820): совет «прибраться» действителен, только пока
     * скрытая строка ничего не продаёт. Скрытая строка с активными тарифами —
     * курируемая продажа, и тот же самый текст «прибираться по желанию через
     * `catalog:retire-shell`» и есть то приглашение, по которому 31-08-2026
     * погасили продажу курса 327. Поэтому у курируемой продажи совет
     * ЗАМЕЩАЕТСЯ запретом, а не дополняется им.
     *
     * @param  list<int>  $curatorGated
     */
    private function followUp(string $verdict, string $exposure, array $curatorGated = []): ?string
    {
        $note = $this->curatorGatedNote($curatorGated);

        if ($verdict !== self::VERDICT_DUPLICATE) {
            return $note;
        }

        if ($exposure === self::EXPOSURE_PUBLIC) {
            $public = 'ВИДНО ПОКУПАТЕЛЮ: две строки одной программы открыты на витрине одновременно — свести витрину и SEO на одну, вторую скрыть или увести алиасом слага; записи не трогать';

            return $note !== null ? $public.'. '.$note : $public;
        }

        $internal = 'покупателю НЕ видно (лишние строки скрыты с витрины и отдают 404): витрину чинить нечего, это дубль в модели данных.';

        return $note !== null
            ? $internal.' '.$note
            : $internal.' Прибираться по желанию через `catalog:retire-shell` / `catalog:audit-shells`, срочности нет';
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
    /**
     * Разобрано ли столкновение явными связями «запись ↔ живой курс» (H3807).
     *
     * Условие намеренно строгое: РОВНО ОДНА строка столкновения остаётся без
     * `recording_of`, и каждая остальная указывает именно на неё. Кольцо
     * (`A → B`, `B → A`), ссылка наружу столкновения и две несвязанные строки
     * разобранными не считаются — иначе полузаполненная связь молча погасила бы
     * настоящий дубль, а именно ради этого случая аудит и писался.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  list<int>  $ids
     */
    private function collisionResolvedByRecordingLinks(array $members, array $ids): bool
    {
        $links = [];
        foreach ($members as $member) {
            if (in_array($member['id'], $ids, true)) {
                $links[(int) $member['id']] = $member['recording_of'] ?? null;
            }
        }

        $canonical = array_keys(array_filter($links, static fn ($target) => $target === null));
        if (count($canonical) !== 1) {
            return false;
        }

        $card = $canonical[0];

        foreach ($links as $id => $target) {
            if ($id !== $card && $target !== $card) {
                return false;
            }
        }

        return true;
    }

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
