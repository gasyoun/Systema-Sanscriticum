<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\TelegramSupportContact;
use App\Models\User;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use App\Support\EnrollmentGroupLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Как оплатить?» → что именно и какой блок (22-10-2026).
 *
 * Бот не угадывает, что студент хочет оплатить, а спрашивает: здесь строятся
 * варианты для вопроса и разбирается ответ. Вариант — курс + блок (+ группа):
 *  1) группа, однозначно понятная из переписки ({@see EnrollmentGroupResolver});
 *  2) курсы с долгом — первый неоплаченный блок с тарифом (договорённости и
 *     рассрочки не предлагаем: там сумма куратора, решает человек);
 *  3) активные группы студента — следующий неоплаченный блок.
 * Ответ: номер варианта («2», «второй»), «да» при одном варианте, либо курс/
 * группа и блок словами («гр.63, 4 блок», «суббота 11, первый блок»), «весь
 * курс» — тариф full. Уже оплаченные блоки не предлагаем.
 * Ничего не пишет; деньги и ссылка — из Tariff, путь — штатный чекаут.
 *
 * @phpstan-type Option array{course_id: int, group_id: ?int, block: int}
 * @phpstan-type Target array{course_id: int, group_id: ?int}
 */
final class PayChoiceOptions
{
    /** «Весь курс» вместо номера блока — тариф full. */
    public const FULL = 0;

    private const MAX_OPTIONS = 5;

    private const FULL_PATTERN = '/весь\s+курс|курс\s+целиком|целиком|полност\p{L}*|полн\p{L}+\s+курс|за\s+весь|все\s+блоки/u';

    /** Основа порядкового числительного → число. */
    private const ORDINALS = [
        'перв' => 1, 'втор' => 2, 'трет' => 3, 'четв' => 4, 'пят' => 5,
        'шест' => 6, 'седьм' => 7, 'восьм' => 8, 'девят' => 9, 'десят' => 10,
    ];

    private const YES_PATTERN = '/^\s*(?:да|ага|угу|верно|точно|его|этот|эту|давайте|подходит|он(?:а|о)?)(?!\p{L})[\s.!)]*$/iu';

    public function __construct(
        private readonly StudentDebtsService $debts,
        private readonly DebtPaymentResolver $debtOptions,
        private readonly EnrollmentGroupResolver $groups,
        private readonly SupportPayLinkResolver $payLinks,
    ) {}

    /**
     * @param  list<string>  $texts  входящие студента, НОВЫЕ ПЕРВЫМИ
     * @return list<Option>
     */
    public function for(?User $user, array $texts): array
    {
        $options = [];

        $enroll = $this->groups->resolve($texts);
        if ($enroll !== null && $enroll['group'] !== null) {
            $options[] = ['course_id' => (int) $enroll['course']->id, 'group_id' => (int) $enroll['group']->id, 'block' => (int) $enroll['block']];
        }

        if ($user !== null) {
            foreach ($this->debts->forUser($user) as $debt) {
                $course = $debt->course ?? null;
                if (! $course instanceof Course) {
                    continue;
                }

                $opts = $this->debtOptions->optionsFor($debt, $user);
                $first = collect($opts['blocks'] ?? [])->sortBy('number')->first();
                if (($opts['type'] ?? null) === 'tariff' && $first !== null) {
                    $options[] = ['course_id' => (int) $course->id, 'group_id' => $this->memberGroupId($user, $course), 'block' => (int) $first['number']];
                }
            }

            $user->activeGroups()->with('courses')->get()->each(function (Group $group) use ($user, &$options): void {
                foreach ($group->courses as $course) {
                    $block = $this->nextBlock($user, $course, $group);
                    if ($block !== null) {
                        $options[] = ['course_id' => (int) $course->id, 'group_id' => (int) $group->id, 'block' => $block];
                    }
                }
            });
        }

        // Сначала отсеять непродаваемое и оплаченное, потом один вариант на курс:
        // иначе оплаченный блок из переписки вытеснял следующий блок курса.
        $unique = [];
        foreach ($options as $option) {
            if ($this->tariff($option) !== null && ! $this->alreadyPaid($user, $option)) {
                $unique[$option['course_id']] ??= $option;
            }
        }

        return array_slice(array_values($unique), 0, self::MAX_OPTIONS);
    }

    /**
     * Студент сам назвал и что, и какой блок — вопрос не нужен.
     *
     * @return Option|null
     */
    public function explicit(?User $user, string $text): ?array
    {
        $block = $this->blockIn($text);
        $target = $block !== null ? $this->targetIn($user, $text) : null;

        if ($target === null) {
            return null;
        }

        $option = [...$target, 'block' => $block];

        return $this->tariff($option) !== null ? $option : null;
    }

    /**
     * Ответ студента → что делать дальше (23-10-2026: бот спрашивает открыто,
     * без списка, и ищет то, что назвал студент).
     *  - link  — однозначно: курс/группа и блок (блок не назван — следующий
     *            неоплаченный, у группы в наборе — первый);
     *  - which — подошло несколько групп: переспросить, назвав только их;
     *  - block — группа ясна, блок нет (или назван недоступный — note);
     *  - none  — ничего не нашли.
     * $context — что бот уже уточнял: target (группа ясна, ждём блок),
     * candidates (переспрос «какая из групп»).
     *
     * @param  array{target?: ?Target, candidates?: list<Target>}  $context
     * @return array{kind: string, option?: Option, targets?: list<Target>, target?: Target, note?: ?string}
     */
    public function resolve(?User $user, string $text, array $context = []): array
    {
        $targets = $this->targetsIn($user, $text, $context['candidates'] ?? []);

        if (count($targets) > 1) {
            return ['kind' => 'which', 'targets' => array_slice($targets, 0, self::MAX_OPTIONS)];
        }

        $target = $targets[0] ?? ($context['target'] ?? null);
        if ($target === null) {
            return ['kind' => 'none'];
        }

        $block = $this->blockIn($text);
        // Ответ на «какой блок?» — просто «2» или «второй».
        if ($block === null && $targets === [] && ($context['target'] ?? null) !== null) {
            $block = $this->choiceNumberIn($text);
        }
        $note = null;

        if ($block !== null) {
            $option = [...$target, 'block' => $block];
            if ($this->alreadyPaid($user, $option)) {
                $note = $block === self::FULL ? 'Курс у вас уже оплачен целиком.' : "Блок {$block} у вас уже оплачен.";
                $block = null;
            } elseif ($this->tariff($option) === null) {
                $note = $block === self::FULL
                    ? 'Весь курс сразу здесь оплатить нельзя — только по блокам.'
                    : "Блока {$block} к оплате здесь нет.";
                $block = null;
            }
        } else {
            $block = $this->defaultBlock($user, $target);
        }

        return $block === null
            ? ['kind' => 'block', 'target' => $target, 'note' => $note]
            : ['kind' => 'link', 'option' => [...$target, 'block' => $block]];
    }

    /**
     * «Санкхья — пн 18:00» / «гр.63» (вторник 08:00) — для переспроса.
     *
     * @param  Target  $target
     */
    public function targetLabel(array $target): string
    {
        $group = $target['group_id'] !== null ? Group::find($target['group_id']) : null;
        if ($group === null) {
            return '«'.(Course::query()->whereKey($target['course_id'])->value('title') ?? 'курс').'»';
        }

        $next = $group->schedules()->where('start', '>=', now())->orderBy('start')->value('start');
        $slot = $next !== null ? Carbon::parse($next)->locale('ru')->isoFormat('dddd HH:mm') : null;

        return "«{$group->name}»".($slot !== null && $this->groups->slotsIn($group->name) === [] ? " ({$slot})" : '');
    }

    /**
     * Что назвал студент: среди переспрошенных групп, своих групп, групп,
     * открытых для записи, затем курс по названию.
     *
     * @param  list<Target>  $asked
     * @return list<Target>
     */
    private function targetsIn(?User $user, string $text, array $asked): array
    {
        if ($asked !== []) {
            $n = $this->choiceNumberIn($text);
            if ($n !== null && isset($asked[$n - 1])) {
                return [$asked[$n - 1]];
            }

            $pool = Group::query()->with(EnrollmentGroupResolver::eagerForMatching())
                ->whereIn('id', array_filter(array_column($asked, 'group_id')))->get();
            $hit = $this->groups->matching($pool, $text);
            if ($hit->isEmpty()) {
                $wanted = $this->payLinks->stems($text);
                $hit = $pool->filter(fn (Group $g) => array_intersect(
                    $this->payLinks->stems($g->name.' '.$g->courses->pluck('title')->implode(' ')),
                    $wanted,
                ) !== []);
            }
            if ($hit->count() === 1) {
                return $this->targetsOf($hit);
            }
        }

        if ($user !== null) {
            $own = $user->activeGroups()->with(EnrollmentGroupResolver::eagerForMatching())->get();
            $hit = $this->groups->matching($own, $text);
            if ($hit->isNotEmpty()) {
                return $this->targetsOf($hit);
            }
        }

        $hit = $this->groups->matching($this->groups->candidates(), $text);
        if ($hit->isNotEmpty()) {
            return $this->targetsOf($hit);
        }

        $course = $this->payLinks->courseNamedIn($text);
        if ($course === null) {
            return [];
        }

        $member = $user !== null ? $this->memberGroupId($user, $course) : null;
        if ($member !== null) {
            return [['course_id' => (int) $course->id, 'group_id' => $member]];
        }

        $open = $course->groups()->whereIn('status', ['forming', 'active'])->pluck('groups.id');

        return $open->isEmpty()
            ? [['course_id' => (int) $course->id, 'group_id' => null]]
            : $open->map(fn ($id) => ['course_id' => (int) $course->id, 'group_id' => (int) $id])->values()->all();
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return list<Target>
     */
    private function targetsOf(Collection $groups): array
    {
        return $groups->map(function (Group $g): ?array {
            $course = $this->groups->courseOf($g);

            return $course !== null ? ['course_id' => (int) $course->id, 'group_id' => (int) $g->id] : null;
        })->filter()->unique(fn (array $t) => $t['group_id'])->values()->all();
    }

    /**
     * Блок по умолчанию: следующий неоплаченный (группа в наборе — с первого).
     * Группа не ясна — null, бот спросит.
     *
     * @param  Target  $target
     */
    private function defaultBlock(?User $user, array $target): ?int
    {
        $group = $target['group_id'] !== null ? Group::find($target['group_id']) : null;
        $course = Course::find($target['course_id']);

        return $group !== null && $course !== null ? $this->nextBlock($user, $course, $group) : null;
    }

    /** Есть ли в ответе хоть какой-то признак выбора — иначе это другой вопрос. */
    public function hasChoiceCue(string $text): bool
    {
        return $this->choiceNumberIn($text) !== null
            || preg_match(self::YES_PATTERN, $text) === 1
            || $this->blockIn($text) !== null
            || $this->payLinks->groupNumbersIn($text) !== []
            || $this->groups->slotsIn($text) !== [];
    }

    /** Назван ли в сообщении блок: «2 блок», «весь курс» или просто «2» / «второй». */
    public function namesBlock(string $text): bool
    {
        return $this->blockIn($text) !== null || $this->choiceNumberIn($text) !== null;
    }

    /** Студент сам указал, что оплачивает: курс, группу, день, номер или блок. */
    public function namesTarget(string $text): bool
    {
        return $this->hasChoiceCue($text) || $this->payLinks->courseNamedIn($text) !== null;
    }

    /**
     * @param  Option  $option
     * @return array{tariff: Tariff, course: Course, group: ?Group, block: int, amount: float, url: string}|null
     */
    public function link(array $option, ?User $user, ?TelegramSupportContact $contact = null): ?array
    {
        $tariff = $this->tariff($option);
        if ($tariff === null) {
            return null;
        }

        $group = $option['group_id'] !== null ? Group::find($option['group_id']) : null;

        return [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'group' => $group,
            'block' => $option['block'],
            'amount' => (float) $tariff->calculateFinalPriceForUser($user),
            'url' => $group !== null ? EnrollmentGroupLink::url($tariff, $group, $contact) : route('checkout.show', $tariff),
        ];
    }

    /** @param  Option  $option */
    public function label(array $option, ?User $user): string
    {
        $link = $this->link($option, $user);
        if ($link === null) {
            return '';
        }

        $what = $link['group'] !== null ? "«{$link['group']->name}»" : "«{$link['course']->title}»";
        $block = $link['block'] === self::FULL ? 'весь курс' : 'блок '.$link['block'];

        return sprintf('%s — %s, %s ₽', $what, $block, number_format($link['amount'], 0, ',', ' '));
    }

    /** «блок 4», «4-й блок», «четвёртый блок»; «весь курс» — {@see self::FULL}. */
    public function blockIn(string $text): ?int
    {
        $lower = mb_strtolower($text);

        if (preg_match('/блок\p{L}*\s*№?\s*(\d{1,2})(?!\d)/u', $lower, $m) === 1
            || preg_match('/(?<!\d)(\d{1,2})\s*-?\s*(?:й|ый|ой|ий|го|ого)?\s+блок/u', $lower, $m) === 1) {
            return (int) $m[1] ?: null;
        }

        if (preg_match('/(\p{L}+)\s+блок/u', $lower, $m) === 1 && ($n = $this->ordinalOf($m[1])) !== null) {
            return $n;
        }

        return preg_match(self::FULL_PATTERN, $lower) === 1 ? self::FULL : null;
    }

    /**
     * Курс/группа, названные в тексте: группа по номеру или дню+часу, иначе
     * курс по словам названия.
     *
     * @return array{course_id: int, group_id: ?int}|null
     */
    private function targetIn(?User $user, string $text): ?array
    {
        $enroll = $this->groups->resolve([$text]);
        if ($enroll !== null && $enroll['group'] !== null) {
            return ['course_id' => (int) $enroll['course']->id, 'group_id' => (int) $enroll['group']->id];
        }

        $course = $this->payLinks->courseNamedIn($text);
        if ($course === null) {
            return null;
        }

        $groupId = $user !== null ? $this->memberGroupId($user, $course) : null;
        if ($groupId === null) {
            $open = $course->groups()->whereIn('status', ['forming', 'active'])->pluck('groups.id');
            $groupId = $open->count() === 1 ? (int) $open->first() : null;
        }

        return ['course_id' => (int) $course->id, 'group_id' => $groupId];
    }

    /** Номер варианта: «2», «вариант 2», «второй», «первое». */
    private function choiceNumberIn(string $text): ?int
    {
        $clean = mb_strtolower(trim($text));

        if (str_contains($clean, 'блок') || mb_strlen($clean) > 25) {
            return null;
        }

        if (preg_match('/^(?:вариант|номер|№)?\s*(\d)\s*[.)!]?$/u', $clean, $m) === 1) {
            return (int) $m[1] ?: null;
        }

        if (preg_match('/^(?:вариант\s+|номер\s+)?(\p{L}+)(?:\s+вариант)?[\s.!)]*$/u', $clean, $m) === 1) {
            return $this->ordinalOf($m[1]);
        }

        return null;
    }

    private function ordinalOf(string $word): ?int
    {
        foreach (self::ORDINALS as $stem => $n) {
            if (str_starts_with($word, $stem) && preg_match('/(?:ый|ий|ой|ая|ое|ье|ья|ую|ого|ему|ом)$/u', $word) === 1) {
                return $n;
            }
        }

        return null;
    }

    private function memberGroupId(User $user, Course $course): ?int
    {
        $id = $user->activeGroups()
            ->whereHas('courses', fn ($q) => $q->whereKey($course->id))
            ->value('groups.id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Следующий неоплаченный блок курса для участника группы: набор → 1,
     * идёт → текущий блок курса, а если он оплачен — следующие по порядку.
     */
    private function nextBlock(?User $user, Course $course, Group $group): ?int
    {
        $block = $group->status === 'forming' ? 1 : ($course->currentBlock()?->number ?? 1);

        $paid = $user !== null ? $this->paidKeys($user, (int) $course->id) : [];

        if (in_array('full', $paid, true)) {
            return null;
        }

        for ($n = (int) $block; $n < $block + 30; $n++) {
            if (! in_array('block_'.$n, $paid, true)) {
                return $this->tariff(['course_id' => (int) $course->id, 'group_id' => null, 'block' => $n]) !== null ? $n : null;
            }
        }

        return null;
    }

    /** @return list<string> ключи оплаченных тарифов курса (block_N, full) */
    private function paidKeys(User $user, int $courseId): array
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where('is_conditional', false)
            ->pluck('tariff')
            ->map(fn ($t) => (string) $t)
            ->all();
    }

    /** @param  Option  $option */
    public function alreadyPaid(?User $user, array $option): bool
    {
        if ($user === null) {
            return false;
        }

        $paid = $this->paidKeys($user, $option['course_id']);

        return in_array('full', $paid, true)
            || ($option['block'] !== self::FULL && in_array('block_'.$option['block'], $paid, true));
    }

    /** @param  Option  $option */
    public function tariff(array $option): ?Tariff
    {
        if ($option['block'] === self::FULL) {
            return Tariff::query()
                ->where('course_id', $option['course_id'])
                ->where('is_active', true)
                ->where('type', 'full')
                ->whereNull('membership_months')
                ->whereHas('course', fn ($q) => $q->where('is_active', true))
                ->first();
        }

        return Tariff::query()
            ->where('course_id', $option['course_id'])
            ->where('is_active', true)
            ->where('type', 'block')
            ->where('block_number', $option['block'])
            ->whereNull('block_half')
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->first();
    }
}
