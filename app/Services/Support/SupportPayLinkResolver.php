<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use Illuminate\Support\Collection;

/**
 * Ссылка «куда платить» для ответов поддержки (бот лички + хелпдеск), 30-09-2026.
 *
 * Шаблон «D2 — куда оплатить» рендерился без курса: студентке, спросившей
 * «как оплатить 66 Лейтана», уходило «Оплатить курс «»… /login». Здесь
 * выбирается курс и, если можно, конкретный блок:
 *  - названа группа («гр.55», «в 55й группе») → курс «… гр.55»; номер блока
 *    из сообщения → штатный чекаут активного тарифа этого block_N, даже если
 *    долга по курсу нет (оплата вперёд). Номер группы блоком не считается;
 *  - курс однозначно назван словами («купить курс хинди») → он же, тоже без
 *    долга: новый курс → чекаут названного блока или страница курса;
 *  - иначе — по долгам студента: номер из сообщения есть в долге
 *    → чекаут этого блока; блок в долге один → он; иначе (несколько блоков,
 *    рассрочка — там POST, блок без тарифа) → «Оплата и доступ».
 * Ничего не создаёт и не пишет; платёжный путь — прежний чекаут.
 * Под флагом features.support_block_pay_link.
 */
final class SupportPayLinkResolver
{
    /** «гр.55», «гр 55», «55й группе», «55-я группа», «группа 55». */
    private const GROUP_PATTERNS = [
        '/гр\.?\s*(\d{1,4})/iu',
        '/(\d{1,4})\s*-?\s*(?:й|я|ой|ая|ую|ей)?\s+групп/iu',
        '/групп\w*\s*№?\s*(\d{1,4})/iu',
    ];

    public function __construct(
        private readonly StudentDebtsService $debts,
        private readonly DebtPaymentResolver $options,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_block_pay_link');
    }

    /**
     * @return array{course: Course, block: ?int, url: string}|null null — курс не определили однозначно
     */
    public function resolve(User $user, ?string $studentText = null): ?array
    {
        $groups = $this->groupNumbersIn($studentText);
        $named = array_values(array_diff($this->numbersIn($studentText), $groups));

        $debts = $this->debts->forUser($user)
            ->filter(fn (object $d) => ($d->course ?? null) instanceof Course)
            ->values();

        if ($groups !== []) {
            return $this->forGroup($user, $debts, $groups, $named);
        }

        // Курс назван словами («купить курс хинди») — не обязательно из долга:
        // студент может спрашивать ссылку на новый курс.
        $namedCourse = $this->courseNamedIn($studentText);
        if ($namedCourse !== null) {
            return $this->forCourse($user, $debts, $namedCourse, $named);
        }

        if ($debts->isEmpty()) {
            return null;
        }

        // Долг, в блоках которого есть названный номер, — если такой ровно один.
        $matching = $debts->filter(fn (object $d) => array_intersect(
            $named,
            array_map('intval', $d->debt_block_numbers ?? []),
        ) !== [])->values();

        if ($matching->count() === 1) {
            return $this->fromDebt($matching->first(), $user, $named);
        }

        if ($debts->count() === 1) {
            return $this->fromDebt($debts->first(), $user, $named);
        }

        return null;
    }

    /**
     * Названа группа: курс берётся по «гр.N» в названии. Долг по нему есть —
     * ведём как по долгу; нет — по активному тарифу названного блока.
     *
     * @param  Collection<int, object>  $debts
     * @param  list<int>  $groups
     * @param  list<int>  $named
     * @return array{course: Course, block: ?int, url: string}|null
     */
    private function forGroup(User $user, Collection $debts, array $groups, array $named): ?array
    {
        $courses = Course::query()
            ->where('title', 'like', '%гр%')
            ->get()
            ->filter(fn (Course $c) => array_intersect($this->groupNumbersIn((string) $c->title), $groups) !== [])
            ->values();

        if ($courses->count() !== 1) {
            return null;
        }

        return $this->forCourse($user, $debts, $courses->first(), $named);
    }

    /**
     * Курс известен: долг по нему есть — ведём как по долгу; нет — на чекаут
     * активного тарифа названного блока, иначе на страницу курса.
     *
     * @param  Collection<int, object>  $debts
     * @param  list<int>  $named
     * @return array{course: Course, block: ?int, url: string}
     */
    private function forCourse(User $user, Collection $debts, Course $course, array $named): array
    {
        $debt = $debts->first(fn (object $d) => (int) $d->course_id === (int) $course->id);
        if ($debt !== null) {
            return $this->fromDebt($debt, $user, $named);
        }

        foreach ($named as $n) {
            $tariff = Tariff::query()
                ->where('course_id', $course->id)
                ->where('is_active', true)
                ->where('type', 'block')
                ->where('block_number', $n)
                ->whereNull('block_half')
                ->first();

            if ($tariff instanceof Tariff) {
                return ['course' => $course, 'block' => $n, 'url' => route('checkout.show', $tariff)];
            }
        }

        return ['course' => $course, 'block' => null, 'url' => route('shop.course.show', $course->slug)];
    }

    /**
     * @param  list<int>  $named
     * @return array{course: Course, block: ?int, url: string}
     */
    private function fromDebt(object $debt, User $user, array $named): array
    {
        $block = null;
        $url = null;
        $opts = $this->options->optionsFor($debt, $user);

        if (($opts['type'] ?? null) === 'tariff') {
            $blocks = collect($opts['blocks'] ?? []);
            $hit = $blocks->first(fn (array $b) => in_array((int) $b['number'], $named, true));

            // Номер не назван (или назван не из долга), а блок в долге один.
            if ($hit === null && $blocks->count() === 1
                && count($debt->debt_block_numbers ?? []) === 1) {
                $hit = $blocks->first();
            }

            if ($hit !== null) {
                $block = (int) $hit['number'];
                $url = (string) $hit['url'];
            }
        }

        return [
            'course' => $debt->course,
            'block' => $block,
            'url' => $url ?? $this->cabinetPaymentsUrl(),
        ];
    }

    /**
     * Курс, однозначно названный в сообщении. Сравниваем основы слов (первые
     * 5 букв слов длиной от 5): «хинди» ↔ «Хинди с нуля», «лейтана» ↔
     * «Лейтан». Кандидаты — только продаваемые курсы (активный курс и хотя бы
     * один активный тариф; скрытость витрины не мешает — curator-gated sale).
     * Выигрывает курс с наибольшим числом совпавших основ; ничья («грамматика»
     * у десятка групп) — не угадываем, null.
     */
    private function courseNamedIn(?string $text): ?Course
    {
        $wanted = $this->stems($text);
        if ($wanted === []) {
            return null;
        }

        $scores = Course::query()
            ->where('is_active', true)
            ->whereHas('tariffs', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'title', 'slug'])
            ->map(fn (Course $c) => [
                'course' => $c,
                'score' => count(array_intersect($this->stems((string) $c->title), $wanted)),
            ])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($scores->isEmpty()) {
            return null;
        }

        if ($scores->count() > 1 && $scores[1]['score'] === $scores[0]['score']) {
            return null;
        }

        return Course::find($scores[0]['course']->id);
    }

    /**
     * Основы значимых слов. Служебные слова сообщения («оплатить», «курс»,
     * «ссылку», «группе» …) выкинуты, чтобы не совпадать с названиями.
     *
     * @return list<string>
     */
    private function stems(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $stop = ['оплат', 'купит', 'курса', 'курсу', 'курсо', 'ссылк', 'добры', 'здрав',
            'подск', 'можно', 'хочет', 'хотел', 'пожал', 'спаси', 'групп', 'блока', 'блоко',
            'сколь', 'стоит', 'нужно', 'нужен', 'будет', 'когда', 'котор', 'занят', 'урока', 'уроко',
            // «санскрит» есть в половине названий школы — курс не определяет.
            'санск',
            // «продолжающие» — слово из обычной речи тоже («продолжение работы»),
            // инцидент 01-10-2026: «Перевела вам деньги для продолжение работы
            // над Рамаяной» опознало единственный курс «…продолжающие».
            'продо',
            // Дни недели в названиях групп («гр. 3, пятница», «гр. 4, суббота»,
            // «Йога-сутры …, вторник 15:00») — время занятий, не курс: «не смогла
            // в субботу» не должно угадывать хинди-группу по стему дня.
            'понед', 'вторн', 'среда', 'среду', 'четве', 'пятни', 'суббо', 'воскр'];

        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $m);

        $stems = array_map(fn (string $w) => mb_substr($w, 0, 5), $m[0]);

        return array_values(array_unique(array_diff($stems, $stop)));
    }

    /** @return list<int> */
    private function numbersIn(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        // Числа в сообщении — это номера блоков; фрагменты времени («занятие
        // в 15:00»), даты («оплата 02.10») и ссылки («samskrte.ru/pay/15»)
        // номерами не являются.
        $clean = (string) preg_replace(['/https?:\/\/\S+/u', '/\d{1,2}[:.]\d{2}/u'], ' ', $text);

        preg_match_all('/\d{1,4}/u', $clean, $m);

        return array_values(array_unique(array_map('intval', $m[0])));
    }

    /** @return list<int> */
    private function groupNumbersIn(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $found = [];
        foreach (self::GROUP_PATTERNS as $pattern) {
            preg_match_all($pattern, $text, $m);
            foreach ($m[1] as $n) {
                $found[] = (int) $n;
            }
        }

        return array_values(array_unique($found));
    }

    /** Кабинет вместо /login — когда курс опознать не удалось. */
    public function cabinetPaymentsUrl(): string
    {
        return config('features.cabinet_hybrid')
            ? route('student.access')
            : route('student.dashboard');
    }
}
