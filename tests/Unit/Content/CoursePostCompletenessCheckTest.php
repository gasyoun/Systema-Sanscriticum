<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\CoursePostCompletenessCheck;
use App\Services\Content\PostFormatter;
use PHPUnit\Framework\TestCase;

/**
 * H8643: the course-post completeness gate — a PHP mirror of the selftest in
 * claude-config scripts/course_post_gate.py. Negative controls FIRST: a gate
 * that accepts the original post is not a gate.
 */
class CoursePostCompletenessCheckTest extends TestCase
{
    /** ОРИГИНАЛ t.me/institutsanskrita/2382 дословно — регрессия, ради которой существует гейт. */
    private const RED_2382 = <<<'TXT'
        ❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ

        На курсе «Санскритская литература», на занятии о поэтике, спросили:
        встретилось выражение «литература — сахитья», что оно значит?

        Ответ с занятия: сахитья — это соединение. В литературе гармонично
        соединяются слово и смысл, форма и содержание. Это одно из определений
        кавьи, поэтического произведения.

        О кавье нельзя сказать «написано плохо, зато идеи глубокие», как о
        трактате, или «смысла нет, зато форма красивая». Индийские теоретики
        поэзии, по словам преподавателя, отвергали бессодержательность:
        произведение создаёт именно сочетание двух начал.

        У Монье-Уильямса साहित्य sāhitya — от sahita «соединённый»: «соединение»,
        «гармония», «поэзия».

        Курс «Санскритская литература» — в записи: тексты эпоса, литературы и
        поэзии, доступ к записям бессрочный.

        https://samskrte.ru/k/sanskritskaia-literatura-2025

        #санскрит #вопрос
        TXT;

    /** Исправленный черновик САХИТЬЯ (позитивный контроль). */
    private const GREEN_2382 = <<<'TXT'
        ❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ

        На курсе «Санскритская литература», на занятии о поэтике, спросили:
        встретилось выражение «литература — сахитья», что оно значит?

        Преподаватель курса — Максим Леонов, переводчик санскритской литературы,
        исследователь индийской философии.

        Ответ с занятия: сахитья — это соединение. В литературе гармонично
        соединяются слово и смысл, форма и содержание. Это одно из определений
        кавьи, поэтического произведения.

        В Большом петербургском словаре: साहित्य sāhitya — от sahita «соединённый».
        Два смысла: 1〉 «соединение, сочетание; согласованность»; 2〉 «поэтическое
        произведение, искусство поэзии».

        Курс «Санскритская литература» прошёл в 2025 году — сегодня доступен
        только в записи: тексты эпоса, литературы и поэзии в историческом
        контексте. Доступ к записям бессрочный.

        #вопрос #санскрит #литературоведение #леонов #санскритская_литература
        TXT;

    /** Якорь-план реального черновика — отдельные строки после текста поста. */
    private const GREEN_2382_ANCHORS = <<<'TXT'
        Якорь в редакторе Telegram: «Максим Леонов» →
        https://samskrte.ru/prepodavately/leonov-maksim
        Якорь курса → https://samskrte.ru/ga/sl25-inst-p
        TXT;

    /** ОРИГИНАЛ t.me/samskrtamru/4371 дословно — прошёл desk-side гейт через «в записи открытого занятия». */
    private const RED_4371 = <<<'TXT'
        🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ

        В учебном упражнении попалась фраза о том, что глаза — под бровями. Глагол в ней тот, что в словаре стоит как вартате (vartate).

        Русскому уху сразу слышится «вертеться». Значение «вертеться, вращаться» у этого глагола и правда есть. Так и хочется перевести: «глаза вертятся под бровями».

        Но у вартате есть и другое значение — «находиться, пребывать». Здесь нужно именно оно: глаза просто находятся под бровями.

        Преподаватель заметил, что фраза как раз и ловит на знакомое «вертеться».

        Полезно помнить: если значение слова кажется знакомым, сверьте его со смыслом всей фразы.

        Как проходят занятия, можно увидеть в записи открытого занятия.

        👉 samskrte.ru/online/poprobovat

        #санскрит #ловушка
        TXT;

    private const RED_2382_CODES = ['C1', 'C3', 'C4', 'C5', 'C6', 'C7'];

    /** Имя проверки в маркере пропуска → код дефекта. */
    private const CODE_OF = ['титул' => 'C1', 'ссылка' => 'C3', 'тэги' => 'C4', 'доступ' => 'C5', 'словарь' => 'C6', 'якорь' => 'C7'];

    private const TAGS = '#ловушка #санскрит #языкознание #леонов #санскритская_литература';

    private const LIVE = 'Курс идёт с сентября по декабрь.';

    private CoursePostCompletenessCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = new CoursePostCompletenessCheck;
    }

    public function test_original_2382_is_refused(): void
    {
        $this->assertTrue($this->check->isCoursePost('', self::RED_2382));
        $r = $this->check->check('', self::RED_2382);

        $this->assertFalse($r['ok']);
        // Спека называет C1/C3/C4/C6/C7; ужесточённое C5 добавляет C5 — «— в записи:» здесь голое «в записи».
        $this->assertSame(self::RED_2382_CODES, self::codes($r['defects']));
        $this->assertCount(1, $r['warnings']);
        $this->assertStringStartsWith('C3 ссылка: в сообщении нет /ga/-ключа', $r['warnings'][0]);
    }

    public function test_original_4371_is_refused_including_the_access_line(): void
    {
        $r = $this->check->check('', self::RED_4371);

        $this->assertFalse($r['ok']);
        $this->assertSame(['C1', 'C3', 'C4', 'C5', 'C7'], self::codes($r['defects']), 'C5: «в записи открытого занятия» — не строка о доступе');
    }

    public function test_access_line_needs_a_month_range_or_an_explicit_recorded_only(): void
    {
        foreach ([
            self::LIVE => [],
            'Курс прошёл в 2025 году — сегодня доступен только в записи.' => [],
            'Курс «Санскритская литература» — в записи: доступ к записям бессрочный.' => ['C5'],
            'Как проходят занятия, можно увидеть в записи открытого занятия.' => ['C5'],
            'Занятия по средам.' => ['C5'],
        ] as $accessLine => $codes) {
            $this->assertSame($codes, self::codes($this->check->check('', self::post($accessLine))['defects']), $accessLine);
        }
    }

    public function test_corrected_sahitya_draft_has_no_defects(): void
    {
        // Буквальное зеркало Python-selftest: текст поста + якорь-план одним текстом.
        $r = $this->check->check('', self::GREEN_2382."\n".self::GREEN_2382_ANCHORS);
        $this->assertSame([], $r['defects']);
        $this->assertSame([], $r['warnings']);

        // Форма слота Systema: якоря — в href сырого HTML, читаемый текст — PostFormatter::toPlain().
        $html = self::greenSlotHtml();
        $r = $this->check->check('❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ', PostFormatter::toPlain($html), $html);

        $this->assertTrue($r['detected']);
        $this->assertSame([], $r['defects']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_teacher_anchor_and_course_key_are_seen_in_the_raw_html_href(): void
    {
        // Без сырого HTML якорь преподавателя невидим — пост был бы удержан.
        $this->assertSame(['C7'], self::codes($this->check->check('', self::GREEN_2382)['defects']));

        $canonical = str_replace('https://samskrte.ru/ga/sl25-inst-p', 'https://samskrte.ru/k/sanskritskaia-literatura-2025', self::greenSlotHtml());
        $this->assertSame(['C3'], self::codes($this->check->check('', self::GREEN_2382, $canonical)['defects']));
    }

    public function test_a_tag_line_without_a_series_marker_is_not_detected(): void
    {
        // Строка тегов без маркера серии — не пост курсов.
        $text = "Слово дня: ॐ — oṃ.\n\n#санскрит #словодня";

        $this->assertFalse($this->check->isCoursePost('', $text));
        $this->assertSame(['ok' => true, 'detected' => false, 'defects' => [], 'warnings' => []], $this->check->check('', $text));
    }

    /**
     * 28-11-2026: маркер серии один — триггер (D23). Тело слота серии на проде
     * хранится без хэштегов (их дописывает рубрика в publishBody()), поэтому
     * строка тегов не может быть условием распознавания: пост серии без неё
     * распознан и удержан по C4, а не пропущен молча.
     */
    public function test_a_series_marker_alone_is_detected_and_a_missing_tag_line_is_a_c4_defect(): void
    {
        foreach ([
            'Мы обсудили канон серии ЛОВУШКА и решение D15 по областям — протокол в Uprava.',
            "Как делать посты «ВОПРОС ИЗ КЛАССА»: титул, ссылка /ga/, тэги.\nhttps://samskrte.ru/k/example",
            '🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ',
        ] as $text) {
            $r = $this->check->check('', $text);

            $this->assertTrue($this->check->isCoursePost('', $text), $text);
            $this->assertTrue($r['detected'], $text);
            $this->assertContains('C4', self::codes($r['defects']), $text);
        }
    }

    public function test_every_series_marker_triggers_with_a_hashtag_line(): void
    {
        $body = "Текст поста без названия курса.\n\n#тег #санскрит";

        foreach (['❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ', '🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ', 'СЛОВО С ИСТОРИЕЙ · САХИТЬЯ', 'НЕДЕЛЯ В ГРУППАХ · ИТОГИ'] as $title) {
            $this->assertTrue($this->check->check($title, $body)['detected'], $title);
        }
    }

    public function test_omit_marker_skips_exactly_the_named_check(): void
    {
        $marked = fn (string $name): string => str_replace('У Монье-Уильямса', "[course-post-omit: {$name} — согласовано отдельно] У Монье-Уильямса", self::RED_2382);

        foreach (self::CODE_OF as $name => $code) {
            $r = $this->check->check('', $marked($name));

            $this->assertSame(array_values(array_diff(self::RED_2382_CODES, [$code])), self::codes($r['defects']), "omit «{$name}»: уходит только {$code}");
            $this->assertContains('пропущены по omit-маркеру: '.$name, $r['warnings']);
        }

        // «ссылка» снимает и предупреждение C3 — проверка пропущена целиком.
        $this->assertSame(['пропущены по omit-маркеру: ссылка'], $this->check->check('', $marked('ссылка'))['warnings']);
    }

    public function test_omit_marker_with_an_unknown_name_warns_and_skips_nothing(): void
    {
        $r = $this->check->check('', str_replace('У Монье-Уильямса', '[course-post-omit: источники — согласовано отдельно] У Монье-Уильямса', self::RED_2382));

        $this->assertSame(self::RED_2382_CODES, self::codes($r['defects']));
        // Предупреждения маркера идут первыми, затем обычные проверки (как в Python-файле).
        $this->assertStringStartsWith('omit-маркер без известного имени проверки («источники — согласовано отдельно»)', $r['warnings'][0]);
    }

    public function test_tag_slots_follow_the_canon(): void
    {
        foreach ([
            self::TAGS => [],
            '#санскрит #ловушка #языкознание #леонов #курс' => ['C4'], // слот 1 не из серии
            '#ловушка #санскрит #уроки #леонов #курс' => ['C4'], // слот 3 не из закрытого набора
            '#санскрит #санскрит #уроки #леонов #курс' => ['C4', 'C4'],
            '#ловушка #санскрит' => ['C4'], // слотов 2
        ] as $line => $codes) {
            $this->assertSame($codes, self::codes($this->check->check('', str_replace(self::TAGS, $line, self::post(self::LIVE)))['defects']), $line);
        }
    }

    public function test_dictionary_rule_prefers_pwg_and_demands_an_explicit_mark_for_monyer_williams(): void
    {
        $this->assertSame(['ok' => true, 'detected' => true, 'defects' => [], 'warnings' => []], $this->check->check('', self::post(self::LIVE)), 'каноничный пост — 0 дефектов, 0 предупреждений');

        // Деванагари без PWG — предупреждение, не дефект.
        $r = $this->check->check('', self::post(self::LIVE, 'В словаре: नयन nayana — «глаз».'));
        $this->assertSame([], $r['defects']);
        $this->assertStringStartsWith('C6 словарь: деванагари-слово без словарной справки PWG', $r['warnings'][0]);

        $this->assertSame(['C6'], self::codes($this->check->check('', self::post(self::LIVE, 'У Монье-Уильямса नयन nayana — «глаз».'))['defects']), 'MW без явной пометы — дефект');
        $this->assertSame([], $this->check->check('', self::post(self::LIVE, 'По Монье-Уильямсу नयन nayana — «глаз».'))['defects'], 'MW с явной пометой — норма');
    }

    public function test_journal_line_is_one_line_naming_the_defects(): void
    {
        $line = $this->check->journalLine($this->check->check('', self::RED_4371));

        $this->assertStringStartsWith('course-post-hold: C1 титул: ', $line);
        foreach (['C3 ссылка: ', 'C4 тэги: ', 'C5 доступ: ', 'C7 якорь: '] as $needle) {
            $this->assertStringContainsString($needle, $line);
        }
        $this->assertSame(1, count(explode("\n", $line)));
    }

    public function test_windows_line_endings_do_not_hide_the_tag_line(): void
    {
        $html = self::greenSlotHtml();
        $crlf = str_replace("\n", "\r\n", self::GREEN_2382);

        $this->assertSame($this->check->check('', self::GREEN_2382, $html), $this->check->check('', $crlf, $html));
    }

    /** @param  list<string>  $lines */
    private static function codes(array $lines): array
    {
        return array_map(static fn (string $line): string => explode(' ', $line)[0], $lines);
    }

    /** Полностью каноничный пост серии; меняются строка о доступе и словарная строка. */
    private static function post(string $accessLine, string $dictionary = 'В Большом петербургском словаре: नयन nayana — «глаз».'): string
    {
        return "🪤 ЛОВУШКА · ПРОВЕРКА ГЕЙТА\n\n"
            ."В учебном упражнении попалась фраза о том, что глаза — под бровями.\n"
            .$dictionary."\n\n"
            ."Преподаватель курса — Максим Леонов, переводчик санскритской литературы.\n\n"
            .$accessLine."\n\n"
            ."Подробнее: https://samskrte.ru/ga/sl25-inst-p\n"
            ."Страница преподавателя: https://samskrte.ru/prepodavately/leonov-maksim\n\n"
            .self::TAGS;
    }

    /** Слот в форме Systema: абзац на строку, якорь преподавателя и ключ курса — в href. */
    private static function greenSlotHtml(): string
    {
        $lines = array_map(
            static fn (string $line): string => '<p>'.htmlspecialchars($line, ENT_NOQUOTES, 'UTF-8').'</p>',
            explode("\n", self::GREEN_2382),
        );

        return str_replace(
            ['Преподаватель курса — Максим Леонов,', 'На курсе «Санскритская литература»,'],
            [
                'Преподаватель курса — <a href="https://samskrte.ru/prepodavately/leonov-maksim">Максим Леонов</a>,',
                'На курсе «<a href="https://samskrte.ru/ga/sl25-inst-p">Санскритская литература</a>»,',
            ],
            implode('', $lines),
        );
    }
}
