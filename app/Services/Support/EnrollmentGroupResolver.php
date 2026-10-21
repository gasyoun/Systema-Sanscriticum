<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Tariff;
use App\Support\EnrollmentGroupLink;
use Illuminate\Support\Collection;

/**
 * В какую группу записывается студент — по переписке в личке (20-10-2026).
 *
 * Диалог-образец (прод, 20-10): «есть группа для начинающих?» → «в 8 утра
 * совсем не удобно, есть в выходные?» → «суббота 11 — это в 11:00?» →
 * «можно меня на неё записать?» → «готова оплатить». Группа названа не в
 * последнем сообщении, поэтому смотрим серию входящих от новых к старым и
 * берём ПЕРВОЕ сообщение, в котором есть признак группы:
 *  1) номер («гр.63», «в 61-й группе») — по названию группы/курса;
 *  2) день недели + час («суббота 11», «вт 8:00») — по занятиям группы
 *     (schedules.start) и по времени в названии («— суббота 12:00»).
 *     Фраза с отрицанием («в 8 утра неудобно») признаком не считается.
 * Сужение: «с нуля / начинающих» → группы в наборе (forming); ничья по
 * времени → основы слов названия курса (хинди ≠ санскрит).
 *
 * Не угадываем: совпало не ровно одна группа → null с кандидатами, решает
 * человек. Ничего не пишет; ссылка — штатный чекаут блока с подписанной группой.
 */
final class EnrollmentGroupResolver
{
    /** ISO-день недели → основы/сокращения (рядом с буквами не матчатся). */
    private const DAYS = [
        1 => 'понедельник\p{L}*|пн',
        2 => 'вторник\p{L}*|вт',
        3 => 'сред[аеуы]|ср',
        4 => 'четверг\p{L}*|чт',
        5 => 'пятниц\p{L}*|пт',
        6 => 'суббот\p{L}*|сб',
        7 => 'воскресень\p{L}*|вс',
    ];

    private const NEGATION_PATTERN = '/не\s+(?:очень\s+|совсем\s+|особо\s+)?(?:удобн|подход|смогу|могу|получ|вариант)|неудобн/iu';

    private const BEGINNER_PATTERN = '/с\s+нуля|начина|новичк|нов\p{L}*\s+групп/iu';

    public function __construct(private readonly SupportPayLinkResolver $payLinks) {}

    /**
     * null — признака группы в переписке нет вовсе; group=null — признак есть,
     * но совпала не ровно одна группа (кандидаты в candidates).
     *
     * @param  list<string>  $texts  входящие студента, НОВЫЕ ПЕРВЫМИ
     * @return array{group: ?Group, course: ?Course, tariff: ?Tariff, block: ?int, url: ?string, candidates: Collection<int, Group>}|null
     */
    public function resolve(array $texts): ?array
    {
        $texts = array_values(array_filter(array_map('strval', $texts), fn (string $t) => trim($t) !== ''));
        if ($texts === []) {
            return null;
        }

        $all = implode("\n", $texts);
        $pool = null;

        foreach ($texts as $text) {
            $numbers = $this->payLinks->groupNumbersIn($text);
            $slots = $this->slotsIn($text);

            if ($numbers === [] && $slots === []) {
                continue;
            }

            $pool ??= $this->candidates();

            $matches = $numbers !== []
                ? $pool->filter(fn (Group $g) => array_intersect($this->groupNumbersOf($g), $numbers) !== [])
                : $pool->filter(fn (Group $g) => $this->slotsMatch($this->slotsOf($g), $slots));

            $matches = $this->narrow($matches->values(), $all);

            return $this->result($matches);
        }

        return null;
    }

    /**
     * @param  Collection<int, Group>  $matches
     * @return Collection<int, Group>
     */
    private function narrow(Collection $matches, string $all): Collection
    {
        if ($matches->count() > 1 && preg_match(self::BEGINNER_PATTERN, $all) === 1) {
            $forming = $matches->filter(fn (Group $g) => $g->status === 'forming')->values();
            if ($forming->isNotEmpty()) {
                $matches = $forming;
            }
        }

        // Язык: в школе два — санскрит и хинди. «санскрит» в стоп-словах основ
        // (он в половине названий), поэтому хинди разводим явно: не назван —
        // хинди-группы отпадают, назван — остаются только они.
        if ($matches->count() > 1) {
            $wantsHindi = preg_match('/хинди/iu', $all) === 1;
            $byLanguage = $matches->filter(fn (Group $g) => $wantsHindi === (preg_match(
                '/хинди/iu',
                $g->name.' '.$g->courses->pluck('title')->implode(' '),
            ) === 1))->values();

            if ($byLanguage->isNotEmpty()) {
                $matches = $byLanguage;
            }
        }

        if ($matches->count() > 1) {
            $wanted = $this->payLinks->stems($all);
            $byTopic = $matches->filter(fn (Group $g) => array_intersect(
                $this->payLinks->stems($this->courseOf($g)?->title.' '.$g->name),
                $wanted,
            ) !== [])->values();

            if ($byTopic->isNotEmpty()) {
                $matches = $byTopic;
            }
        }

        return $matches;
    }

    /**
     * @param  Collection<int, Group>  $matches
     * @return array{group: ?Group, course: ?Course, tariff: ?Tariff, block: ?int, url: ?string, candidates: Collection<int, Group>}
     */
    private function result(Collection $matches): array
    {
        $none = ['group' => null, 'course' => null, 'tariff' => null, 'block' => null, 'url' => null, 'candidates' => $matches];

        if ($matches->count() !== 1) {
            return $none;
        }

        /** @var Group $group */
        $group = $matches->first();
        $course = $this->courseOf($group);
        if ($course === null) {
            return $none;
        }

        // Группа в наборе начинает с первого блока; идущая — с текущего блока курса.
        $block = $group->status === 'forming' ? 1 : ($course->currentBlock()?->number ?? 1);

        $tariff = Tariff::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->where('type', 'block')
            ->where('block_number', $block)
            ->whereNull('block_half')
            ->first();

        if (! $tariff instanceof Tariff) {
            return $none;
        }

        return [
            'group' => $group,
            'course' => $course,
            'tariff' => $tariff,
            'block' => $block,
            'url' => EnrollmentGroupLink::url($tariff, $group),
            'candidates' => $matches,
        ];
    }

    /**
     * Группы, в которые сейчас можно записаться: набор или идущие, у курса
     * есть активный поблочный тариф.
     *
     * @return Collection<int, Group>
     */
    private function candidates(): Collection
    {
        return Group::query()
            ->whereIn('status', ['forming', 'active'])
            ->whereHas('courses', fn ($q) => $q->where('courses.is_active', true)
                ->whereHas('tariffs', fn ($t) => $t->where('is_active', true)->where('type', 'block')))
            ->with([
                'courses' => fn ($q) => $q->where('courses.is_active', true),
                'courses.tariffs' => fn ($q) => $q->where('is_active', true)->where('type', 'block'),
                'schedules' => fn ($q) => $q->orderByDesc('start')->limit(200),
            ])
            ->get();
    }

    private function courseOf(Group $group): ?Course
    {
        return $group->courses->first(fn (Course $c) => $c->tariffs->isNotEmpty());
    }

    /** @return list<int> */
    private function groupNumbersOf(Group $group): array
    {
        $titles = $group->name.' '.$group->courses->pluck('title')->implode(' ');

        return $this->payLinks->groupNumbersIn($titles);
    }

    /**
     * Слоты группы: день+час её занятий (последние десять) и время из названия.
     *
     * @return list<array{0: int, 1: ?int}>
     */
    private function slotsOf(Group $group): array
    {
        $slots = $group->schedules
            ->filter(fn ($s) => $s->start !== null)
            ->take(10)
            ->map(fn ($s) => [(int) $s->start->isoWeekday(), (int) $s->start->hour])
            ->values()
            ->all();

        return array_merge($slots, $this->slotsIn($group->name), $this->slotsIn($this->courseOf($group)?->title));
    }

    /**
     * @param  list<array{0: int, 1: ?int}>  $groupSlots
     * @param  list<array{0: int, 1: ?int}>  $wanted
     */
    private function slotsMatch(array $groupSlots, array $wanted): bool
    {
        foreach ($wanted as [$day, $hour]) {
            foreach ($groupSlots as [$gDay, $gHour]) {
                if ($day === $gDay && ($hour === null || $hour === $gHour)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * День недели (+ час, если назван сразу за ним) из текста. Фразы с
     * отрицанием пропускаются: «в 8 утра неудобно» не просьба о 8:00.
     *
     * @return list<array{0: int, 1: ?int}>
     */
    public function slotsIn(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $slots = [];
        foreach (preg_split('/[.!?;\r\n]+/u', mb_strtolower($text)) ?: [] as $sentence) {
            if (preg_match(self::NEGATION_PATTERN, $sentence) === 1) {
                continue;
            }

            foreach (self::DAYS as $day => $pattern) {
                $re = '/(?<!\p{L})(?:'.$pattern.')(?!\p{L})(?:[^\d\p{L}]{0,4}(?:в|с)?[^\d\p{L}]{0,3}(\d{1,2})(?:[:.]\d{2})?)?/u';
                if (preg_match_all($re, $sentence, $m, PREG_SET_ORDER) < 1) {
                    continue;
                }

                foreach ($m as $hit) {
                    $hour = isset($hit[1]) && $hit[1] !== '' ? (int) $hit[1] : null;
                    $slots[] = [$day, $hour !== null && $hour <= 23 ? $hour : null];
                }
            }
        }

        return $slots;
    }
}
