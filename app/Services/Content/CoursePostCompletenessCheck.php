<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Fail-closed completeness gate for course-bearing series posts (H8643, canon
 * D1–D24; MG 27-11-2026).
 *
 * PHP mirror of claude-config scripts/course_post_gate.py: the same checks,
 * the same names and the same deliberate-omission marker — the semantics are
 * mirrored, not the language. The desk-side gate missed three times
 * (t.me/samskrte/699, t.me/institutsanskrita/2382, t.me/samskrtamru/4371), so
 * the canon is enforced at the publishing contour:
 * CalendarPublishService::passesChecklist() holds a non-compliant series post
 * back as `draft` for a human edit.
 *
 * Detection (D23): a series marker (ВОПРОС ИЗ КЛАССА · ЛОВУШКА · СЛОВО С
 * ИСТОРИЕЙ · НЕДЕЛЯ В ГРУППАХ) AND a hashtag-only line. The marker alone is
 * the trigger — the post need not name a course. Prose that merely DISCUSSES
 * the canon without a tag line never fires.
 *
 * Checks (each nameable in an omission marker):
 *   C1 титул   — «Преподаватель курса — Имя Фамилия» present; an anonymised
 *                «Преподаватель объяснил» is legal only with it (D1/D9)
 *   C3 ссылка  — no canonical samskrte.ru/{k,online}/ URL; the course link is
 *                a minted /ga/ key (D8/D12); a missing /ga/ key is a WARNING
 *   C4 тэги    — exactly 5 slots [серия][предмет][область][фамилия][курс];
 *                slot 1 from the series set, slot 3 from the closed area set
 *                (D15); every tag is one token (D16)
 *   C5 доступ  — access line: «с <месяц> по <месяц>» for a live run or an
 *                explicit «только в записи» for a recorded course (D17)
 *   C6 словарь — «Монье-Уильямс»/«Monier» without an explicit mark is a defect;
 *                Devanagari without a PWG formula is a warning (D18/D19)
 *   C7 якорь   — the post carries the teacher anchor,
 *                samskrte.ru/prepodavately/<slug> (D3/D4)
 *
 * ONE deliberate difference from the Python file (tightened 27-11-2026): C5
 * no longer accepts a bare «в записи» — it let t.me/samskrtamru/4371 («в
 * записи открытого занятия») through.
 *
 * Deliberate omission: `[course-post-omit: <имя проверки> — причина]` skips
 * the named checks for that post. An unknown name inside a marker warns —
 * never a silent pass.
 *
 * Pure function over text — no DB, no HTTP, no side effects.
 */
final class CoursePostCompletenessCheck
{
    /** Серии (D23): маркер серии + строка хэштегов = пост курсов. */
    public const SERIES_MARKERS = [
        'ВОПРОС ИЗ КЛАССА',
        'ЛОВУШКА', // без 🪤 — чтобы совпадал и просто текст
        'СЛОВО С ИСТОРИЕЙ',
        'НЕДЕЛЯ В ГРУППАХ',
    ];

    /** Начало маркера намеренного пропуска: `[course-post-omit: <имя> — <причина>]`. */
    public const OMIT_PREFIX = '[course-post-omit:';

    /** Имена проверок для маркера пропуска, в порядке кодов C1…C7. */
    public const CHECK_NAMES = ['титул', 'ссылка', 'тэги', 'доступ', 'словарь', 'якорь'];

    private const SERIES_TAGS = ['#вопрос', '#ловушка', '#слово', '#неделя'];

    private const AREA_TAGS = [
        '#языкознание', '#литературоведение', '#философия',
        '#напевное_чтение', '#традиционные_науки', '#страноведение',
    ];

    /** Один токен: буквы (кириллица и латиница), цифры и подчёркивания — \w с /u Unicode-овый. */
    private const TAG = '#[^\W\d_][\w]*';

    /** Строка только из тегов (≥ 2), разделённых пробелами. */
    private const HASHTAG_LINE_RE = '/^[ \t]*('.self::TAG.')(?:[ \t]+('.self::TAG.'))+[ \t]*$/mu';

    private const TITLE_RE = '/Преподавател[ья]\s+курса\s+—\s+[А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+/u';

    private const CANONICAL_COURSE_URL_RE = '/samskrte\.ru\/(?:k|online)\/\S*/u';

    private const GA_KEY_RE = '/samskrte\.ru\/ga\/[A-Za-z0-9_-]+/';

    private const MONTH = '(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|'
        .'ноябр|декабр)[а-яё]*';

    private const MONTH_RANGE_RE = '/с\s+'.self::MONTH.'\s+по\s+'.self::MONTH.'/iu';

    /** Ужесточение 27-11-2026: только явное «только в записи»; голое «в записи» не проходит. */
    private const RECORDED_RE = '/только\s+в\s+записи/iu';

    private const MW_RE = '/(?:Мон[ье]е[-\s]?Уильямс|Monier)/iu';

    private const MW_MARKED_RE = '/(?:по\s+Мон[ье]е[-\s]?Уильямсу|с\s+пометой|пометой|пометка)/iu';

    private const PWG_RE = '/петербургском\s+словаре/iu';

    private const DEVANAGARI_RE = '/[\x{0900}-\x{097F}]/u';

    private const TEACHER_PAGE_RE = '/samskrte\.ru\/prepodavately\/[A-Za-z0-9_-]+/';

    private const OMIT_RE = '/\[course-post-omit:\s*([^\]]+)\]/u';

    private const HREF_RE = '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\'"<>]+))/i';

    /** Серия + строка хэштегов = черновик поста курсов (D23). Без строки тегов — не пост курсов. */
    public function isCoursePost(string $title, string $plainBody): bool
    {
        return $this->detected($this->readingText($title, $plainBody));
    }

    /**
     * Не серия — pass без единой проверки: проза, которая просто обсуждает
     * канон без строки тегов, не срабатывает никогда. Дефекты пусты ⇒ pass
     * (предупреждения разрешены).
     *
     * @param  string  $title  заголовок слота (первая строка сообщения)
     * @param  string  $plainBody  PostFormatter::toPlain($slot->body)
     * @param  string  $rawBodyHtml  сырой HTML тела — якорь преподавателя живёт в href
     * @return array{ok: bool, detected: bool, defects: list<string>, warnings: list<string>}
     */
    public function check(string $title, string $plainBody, string $rawBodyHtml = ''): array
    {
        $plain = $this->readingText($title, $plainBody);
        if (! $this->detected($plain)) {
            return ['ok' => true, 'detected' => false, 'defects' => [], 'warnings' => []];
        }

        // Ссылки ищем и в читаемом тексте, и в href сырого HTML.
        $links = $plain."\n".implode("\n", $this->hrefs($rawBodyHtml));

        $defects = [];
        [$omitted, $warnings] = $this->omissions($plain);
        $skipped = static fn (string $check): bool => in_array($check, $omitted, true);

        // C1 титул
        if (! $skipped('титул') && preg_match(self::TITLE_RE, $plain) !== 1) {
            $defects[] = 'C1 титул: нет строки «Преподаватель курса — Имя Фамилия» '
                .'(обезличенное «Преподаватель объяснил» без неё — дефект, D1/D9)';
        }

        // C3 ссылка
        if (! $skipped('ссылка')) {
            foreach ($this->canonicalUrls($links) as $url) {
                $defects[] = 'C3 ссылка: прямой canonical URL курса ('.$url.') — публикуется '
                    .'только /ga/-ключ под якорем (D8/D12)';
            }
            if (preg_match(self::GA_KEY_RE, $links) !== 1) {
                $warnings[] = 'C3 ссылка: в сообщении нет /ga/-ключа — ссылка курса '
                    .'вставляется после смоука ключа (link gate D8)';
            }
        }

        // C4 тэги
        if (! $skipped('тэги')) {
            $rows = $this->hashtagLines($plain);
            if ($rows === []) {
                $defects[] = 'C4 тэги: нет хэштег-строки (последняя строка поста, '
                    .'слоты через пробел, D2/D7/D15)';
            } else {
                $tags = preg_split('/[ \t]+/', trim((string) end($rows))) ?: [];
                if (count($tags) !== 5) {
                    $defects[] = sprintf(
                        'C4 тэги: слотов %d, надо ровно 5 — [серия][предмет][область][фамилия][курс] (D15)',
                        count($tags),
                    );
                } else {
                    if (! in_array(mb_strtolower($tags[0]), self::SERIES_TAGS, true)) {
                        $series = self::SERIES_TAGS;
                        sort($series);
                        $defects[] = 'C4 тэги: слот 1 '.$tags[0].' не из серии ('.implode(', ', $series).')';
                    }
                    if (! in_array(mb_strtolower($tags[2]), self::AREA_TAGS, true)) {
                        $areas = self::AREA_TAGS;
                        sort($areas);
                        $defects[] = 'C4 тэги: слот 3 (область) '.$tags[2].' не из закрытого набора '
                            .'('.implode(', ', $areas).') (D15)';
                    }
                }
                $bad = array_values(array_filter(
                    $tags,
                    static fn (string $tag): bool => preg_match('/^'.self::TAG.'$/u', $tag) !== 1,
                ));
                if ($bad !== []) {
                    $defects[] = 'C4 тэги: не-тэг в строке: '.implode(' ', $bad)
                        .' — каждый тэг один токен, многословные через _ (D16)';
                }
            }
        }

        // C5 доступ (ужесточено 27-11-2026: голое «в записи» не проходит)
        if (! $skipped('доступ')
            && preg_match(self::MONTH_RANGE_RE, $plain) !== 1
            && preg_match(self::RECORDED_RE, $plain) !== 1) {
            $defects[] = 'C5 доступ: нет строки о доступе — месяцы «с … по …» живого прогона '
                .'или «только в записи» (D17)';
        }

        // C6 словарь
        if (! $skipped('словарь')
            && preg_match(self::MW_RE, $plain) === 1
            && preg_match(self::MW_MARKED_RE, $plain) !== 1) {
            $defects[] = 'C6 словарь: «Монье-Уильямс» без пометы — PWG первичен, MW '
                .'только с явной пометой (D18/D19)';
        }
        if (! $skipped('словарь')
            && preg_match(self::DEVANAGARI_RE, $plain) === 1
            && preg_match(self::PWG_RE, $plain) !== 1
            && preg_match(self::MW_RE, $plain) !== 1) {
            $warnings[] = 'C6 словарь: деванагари-слово без словарной справки PWG — '
                .'проверь, нужна ли цитата (D19)';
        }

        // C7 якорь
        if (! $skipped('якорь') && preg_match(self::TEACHER_PAGE_RE, $links) !== 1) {
            $defects[] = 'C7 якорь: имя преподавателя не ведёт на samskrte.ru/prepodavately/<slug> '
                .'(якорь ставится ссылкой на имя в тексте, D3/D4)';
        }

        if ($omitted !== []) {
            $warnings[] = 'пропущены по omit-маркеру: '.implode(', ', $omitted);
        }

        return ['ok' => $defects === [], 'detected' => true, 'defects' => $defects, 'warnings' => $warnings];
    }

    /** One-line journal text for a held-back post. */
    public function journalLine(array $result): string
    {
        return 'course-post-hold: '.implode('; ', $result['defects']);
    }

    /** @param  string  $plain  читаемый текст: заголовок + тело */
    private function detected(string $plain): bool
    {
        foreach (self::SERIES_MARKERS as $marker) {
            if (str_contains($plain, $marker)) {
                return $this->hashtagLines($plain) !== [];
            }
        }

        return false;
    }

    /** Заголовок и тело — один читаемый текст. */
    private function readingText(string $title, string $plainBody): string
    {
        return $this->normalize($title)."\n".$this->normalize($plainBody);
    }

    /**
     * Строки только из тегов (≥ 2) — кандидаты в строку тегов.
     *
     * @return list<string>
     */
    private function hashtagLines(string $plain): array
    {
        if (preg_match_all(self::HASHTAG_LINE_RE, $plain, $m) === false || $m[0] === []) {
            return [];
        }

        return array_map(static fn (string $line): string => trim($line), $m[0]);
    }

    /**
     * Canonical-ссылки курса, каждая — один раз.
     *
     * @return list<string>
     */
    private function canonicalUrls(string $links): array
    {
        if (preg_match_all(self::CANONICAL_COURSE_URL_RE, $links, $m) === false) {
            return [];
        }

        $urls = [];
        foreach ($m[0] as $url) {
            // Читаемый текст даёт «текст (url)»: \S* приносит закрывающую скобку.
            $url = rtrim($url, ')]},;.:»');
            if ($url !== '') {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Маркеры пропуска: `[course-post-omit: <имя> — <причина>]`. Неизвестное
     * имя — предупреждение, никогда не тихий pass.
     *
     * @return array{0: list<string>, 1: list<string>} [пропущенные проверки, предупреждения]
     */
    private function omissions(string $plain): array
    {
        $omitted = [];
        $warnings = [];
        if (preg_match_all(self::OMIT_RE, $plain, $m) > 0) {
            foreach ($m[1] as $body) {
                $named = false;
                foreach (self::CHECK_NAMES as $name) {
                    if (str_contains(mb_strtolower($body), $name)) {
                        $omitted[$name] = true;
                        $named = true;
                    }
                }
                if (! $named) {
                    $warnings[] = 'omit-маркер без известного имени проверки («'.trim($body).'») — '
                        .'известны: '.implode(', ', self::CHECK_NAMES);
                }
            }
        }
        $names = array_keys($omitted);
        sort($names);

        return [$names, $warnings];
    }

    /**
     * href сырого HTML (сущности раскодированы).
     *
     * @return list<string>
     */
    private function hrefs(string $html): array
    {
        if ($html === '' || preg_match_all(self::HREF_RE, $html, $m, PREG_SET_ORDER) === false) {
            return [];
        }

        $urls = [];
        foreach ($m as $set) {
            $url = $set[1] !== '' ? $set[1] : ($set[2] !== '' ? $set[2] : ($set[3] ?? ''));
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * CRLF → LF (иначе `$` в многострочном режиме останавливается перед «\r» и
     * строка тегов, набранная на Windows, не совпадёт); битый UTF-8 чинится —
     * с флагом /u любой preg_* на нём вернул бы false, то есть тихий pass.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
