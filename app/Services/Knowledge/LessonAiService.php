<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Lesson;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\LessonQaService;
use App\Support\TranscriptParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ИИ-помощник на странице занятия (features.lesson_ai) — функции расширения
 * ClassNote, перенесённые в кабинет:
 *
 *   summary  пересказ по стенограмме: «Кратко / Оглавление / Главные мысли»
 *   quiz     вопросы для самопроверки (по стенограмме, без неё — по заметкам)
 *   outline  конспект из черновых заметок студента
 *   explain  объяснить выделенный фрагмент простыми словами
 *   ask      вопрос по занятию — ответ только по фрагментам его стенограммы
 *
 * Границы те же, что у {@see LessonQaService}:
 *  - доступ к уроку проверяет вызывающий (LessonGate::canWatch);
 *  - генерация ТОЛЬКО локальная ({@see CuratorAi::localChatWithUsage()}),
 *    внешнего фолбэка нет (рулинг #1633) — ни стенограмма, ни заметки
 *    студента стороннему провайдеру не уходят;
 *  - тексты студента не сохраняются: результат живёт в кэше полчаса
 *    (LessonAiJob), общий для всех студентов урока — только пересказ.
 *
 * Сбой — RuntimeException с коротким кодом (offline, bad-answer, …), тексты
 * для студента собирает фронт (public/js/lesson-ai.js).
 */
final class LessonAiService
{
    public const TASKS = ['summary', 'quiz', 'outline', 'explain', 'ask'];

    /** Правка промптов пересказа/тезисов ⇒ +1: кэш занятий пересчитается. */
    private const PROMPT_VERSION = 2;

    /** Пересказ стенограммы одинаков для всех студентов урока — держим долго. */
    private const SUMMARY_TTL = 60 * 60 * 24 * 30;

    private const NO_INTRO = 'Пиши по-русски. Санскритские и хинди-термины оставляй так, как они записаны в материале '
        .'(IAST, деванагари или кириллица). Без вступлений вроде «Конечно!» и без заключений от себя.';

    public function __construct(private readonly CuratorAi $ai) {}

    public function enabled(): bool
    {
        return (bool) config('features.lesson_ai', false);
    }

    public function hasTranscript(Lesson $lesson): bool
    {
        return $this->transcriptLines($lesson) !== [];
    }

    /**
     * Стенограмма укрупнённо: строка ≈ 25 секунд речи, таймкод — её начало.
     *
     * @return list<array{t: int, text: string}>
     */
    public function transcriptLines(Lesson $lesson): array
    {
        $sentences = TranscriptParser::sentencesFromStoredFile($lesson->transcript_file);
        $lines = [];
        $start = null;
        $buf = [];
        foreach ($sentences as $s) {
            $text = trim((string) $s['text']);
            if ($text === '') {
                continue;
            }
            $start ??= (int) $s['start'];
            $buf[] = $text;
            if ((int) $s['end'] - $start >= 25 || mb_strlen(implode(' ', $buf)) > 400) {
                $lines[] = ['t' => $start, 'text' => implode(' ', $buf)];
                $start = null;
                $buf = [];
            }
        }
        if ($buf !== []) {
            $lines[] = ['t' => (int) $start, 'text' => implode(' ', $buf)];
        }

        return $lines;
    }

    /** Готовый пересказ из кэша занятия (без обращения к модели) или null. */
    public function cachedSummary(Lesson $lesson): ?string
    {
        $md = Cache::get($this->summaryKey($lesson));

        return is_string($md) && $md !== '' ? $md : null;
    }

    /**
     * @param  array{text?: string, notes?: string, question?: string}  $input
     * @return array{md: string, questions?: list<array{q: string, a: string, t: string}>}
     */
    public function run(string $task, Lesson $lesson, array $input): array
    {
        return match ($task) {
            'summary' => ['md' => $this->summary($lesson)],
            'quiz' => $this->quiz($lesson, (string) ($input['notes'] ?? '')),
            'outline' => ['md' => $this->outline($lesson, (string) ($input['notes'] ?? ''))],
            'explain' => ['md' => $this->explain($lesson, (string) ($input['text'] ?? ''))],
            'ask' => ['md' => $this->ask($lesson, (string) ($input['question'] ?? ''))],
            default => throw new RuntimeException('bad-task'),
        };
    }

    // ---------------------------------------------------------------------
    // 1. Пересказ по стенограмме
    // ---------------------------------------------------------------------

    public function summary(Lesson $lesson): string
    {
        $cached = $this->cachedSummary($lesson);
        if ($cached !== null) {
            return $cached;
        }

        $digest = $this->digest($lesson);
        $what = $digest['parts'] > 1
            ? 'Перед тобой тезисы длинного занятия по частям, у каждого тезиса таймкод [мм:сс]. '
                .'Не переписывай тезисы подряд — обобщи их до крупных тем.'
            : 'Перед тобой расшифровка занятия, каждая строка начинается с таймкода [мм:сс]. '
                .'Это автоматическое распознавание устной речи: возможны ошибки в словах и терминах.';

        $md = $this->chat(
            $what."\n"
                ."Сделай в Markdown три раздела:\n"
                ."## Кратко — 3–5 предложений, о чём занятие.\n"
                ."## Оглавление — от 5 до 15 пунктов (не больше 15!) вида «- [мм:сс] Тема»: крупные темы занятия, мелкие объединяй; таймкод — начало темы.\n"
                ."## Главные мысли — 5–10 пунктов с ключевыми идеями, определениями, правилами; в конце пункта таймкод [мм:сс], где это сказано.\n"
                .'Таймкоды бери только из материала, в том же формате в квадратных скобках. Не добавляй фактов, которых в материале нет. '
                .self::NO_INTRO,
            $this->titleLine($lesson).$digest['text'],
            2500,
        );

        Cache::put($this->summaryKey($lesson), $md, self::SUMMARY_TTL);

        return $md;
    }

    // ---------------------------------------------------------------------
    // 2. Вопросы для самопроверки
    // ---------------------------------------------------------------------

    /** @return array{md: string, questions: list<array{q: string, a: string, t: string}>} */
    public function quiz(Lesson $lesson, string $notes): array
    {
        if ($this->hasTranscript($lesson)) {
            $material = $this->digest($lesson)['text'];
            $kind = 'Материал — расшифровка или тезисы занятия с таймкодами [мм:сс].';
        } else {
            $material = trim($notes);
            $kind = 'Материал — заметки студента к занятию.';
            if (mb_strlen($material) < 80) {
                throw new RuntimeException('empty');
            }
        }

        $raw = $this->chat(
            "Ты составляешь вопросы для самопроверки по занятию. {$kind}\n"
                .'Верни ТОЛЬКО JSON без пояснений: {"questions":[{"q":"вопрос","a":"краткий ответ, 1–3 предложения","t":"мм:сс"}]}.'."\n"
                .'Сделай от 5 до 10 вопросов: от простых (определения, формы) к сложным (применение, сравнение). '
                .'Ответы — строго по материалу. Поле t — таймкод из материала, где об этом говорилось; если таймкода нет — пустая строка. Всё на русском.',
            $this->titleLine($lesson).$material,
            2000,
        );

        $data = $this->extractJson($raw);
        $questions = [];
        foreach (($data['questions'] ?? []) as $q) {
            if (! is_array($q) || ! is_string($q['q'] ?? null) || ! is_string($q['a'] ?? null)) {
                continue;
            }
            $t = is_string($q['t'] ?? null) && preg_match('/^\d{1,2}:[0-5]\d(:[0-5]\d)?$/', trim($q['t'])) === 1 ? trim($q['t']) : '';
            $questions[] = ['q' => trim($q['q']), 'a' => trim($q['a']), 't' => $t];
        }
        if ($questions === []) {
            throw new RuntimeException('bad-answer');
        }
        $questions = array_slice($questions, 0, 12);

        $md = "## Вопросы для самопроверки\n\n".implode("\n\n", array_map(
            static fn (array $q, int $i): string => '**'.($i + 1).'. '.$q['q']."**\n".$q['a'].($q['t'] !== '' ? ' ['.$q['t'].']' : ''),
            $questions,
            array_keys($questions),
        ));

        return ['md' => $md, 'questions' => $questions];
    }

    // ---------------------------------------------------------------------
    // 3. Конспект из заметок
    // ---------------------------------------------------------------------

    public function outline(Lesson $lesson, string $notes): string
    {
        $notes = trim($notes);
        if (mb_strlen($notes) < 40) {
            throw new RuntimeException('empty');
        }

        return $this->chat(
            "Ты помогаешь студенту превратить черновые заметки к занятию в аккуратный конспект.\n"
                ."Формат ответа — Markdown: разделы «## Заголовок», пункты «- », ключевые термины **жирным**. Таблицы не используй.\n"
                .'Сохрани смысл КАЖДОЙ заметки и все таймкоды вида 12:34 — запиши их в квадратных скобках: [12:34]. '
                .'Объединяй заметки по темам, исправляй опечатки и сокращения, можно чуть дополнить связками, '
                .'но не добавляй фактов, которых в заметках нет. Порядок — как в занятии. '
                .'Конспект должен быть не длиннее 4000 знаков. '.self::NO_INTRO,
            $this->titleLine($lesson)."Заметки студента:\n".$notes,
            2200,
        );
    }

    // ---------------------------------------------------------------------
    // 4. Объяснить выделенное
    // ---------------------------------------------------------------------

    public function explain(Lesson $lesson, string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) < 3) {
            throw new RuntimeException('empty');
        }

        return $this->chat(
            'Ты терпеливый преподаватель Академии санскрита. Объясни выделенный студентом фрагмент простыми словами: '
                .'суть в 2–5 предложениях, затем короткий пример, если это помогает. '
                .'Если во фрагменте есть термины, грамматические формы или слова на санскрите/хинди — расшифруй их. '
                .'Если фрагмент — из автоматической расшифровки и похоже, что слово распознано с ошибкой, скажи об этом. '
                .'Можно использовать Markdown-списки. '.self::NO_INTRO,
            $this->titleLine($lesson)."Фрагмент:\n".$text,
            900,
            0.5,
        );
    }

    // ---------------------------------------------------------------------
    // 5. Вопрос по занятию
    // ---------------------------------------------------------------------

    public function ask(Lesson $lesson, string $question): string
    {
        $question = trim($question);
        if (mb_strlen($question) < 5) {
            throw new RuntimeException('empty');
        }

        $picked = $this->pickLines($this->transcriptLines($lesson), $question);
        if ($picked === []) {
            throw new RuntimeException('not-found');
        }

        $fragments = implode("\n", array_map(fn (array $l): string => '['.$this->timecode($l['t']).'] '.$l['text'], $picked));

        return $this->chat(
            'Ты отвечаешь на вопрос студента ТОЛЬКО по фрагментам расшифровки его занятия — они даны ниже, '
                ."у каждого таймкод [мм:сс]. Это автоматическое распознавание устной речи, возможны ошибки в словах.\n"
                .'Ответь кратко (до 8 предложений или списком) и после каждого утверждения поставь таймкод фрагмента-источника '
                .'в квадратных скобках, например: «… это сандхи висарги [12:34]». Таймкоды бери только из фрагментов.'."\n"
                .'Если во фрагментах ответа нет — честно скажи «В записи этого занятия об этом не нашлось» '
                .'и подскажи, какие близкие темы там есть. Не отвечай из общих знаний. '.self::NO_INTRO,
            $this->titleLine($lesson)."Вопрос: {$question}\n\nФрагменты расшифровки:\n".$fragments,
            900,
        );
    }

    /**
     * Строки стенограммы, где больше всего общих со словами вопроса основ —
     * тот же грубый, но предсказуемый поиск, что у ClassNote. Возвращаются в
     * хронологическом порядке.
     *
     * @param  list<array{t: int, text: string}>  $lines
     * @return list<array{t: int, text: string}>
     */
    private function pickLines(array $lines, string $question): array
    {
        $qStems = array_values(array_unique(array_map([$this, 'stem'], $this->words($question))));
        if ($qStems === []) {
            return [];
        }

        $scored = [];
        foreach ($lines as $i => $line) {
            $lineStems = array_unique(array_map([$this, 'stem'], $this->words($line['text'])));
            $score = 0;
            foreach ($qStems as $qs) {
                foreach ($lineStems as $w) {
                    if (str_starts_with($w, $qs) || str_starts_with($qs, $w)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > 0) {
                $scored[$i] = $score;
            }
        }
        arsort($scored);

        $budget = max(2000, (int) config('knowledge.lesson_ai.part_chars', 12000));
        $keep = [];
        $total = 0;
        foreach (array_keys($scored) as $i) {
            $len = mb_strlen($lines[$i]['text']);
            if ($total + $len > $budget || count($keep) >= 30) {
                break;
            }
            $keep[$i] = true;
            $total += $len;
        }
        ksort($keep);

        return array_values(array_map(static fn (int $i): array => $lines[$i], array_keys($keep)));
    }

    /** @return list<string> */
    private function words(string $s): array
    {
        static $stop = null;
        $stop ??= array_flip(explode(' ', 'что как где когда почему зачем какой какая какие каких это эта этот эти для при или про над под без его она они оно был была были было есть тот там тут все мне меня нас вас ли же уже еще так также чем кто чтобы если такое значит говорил говорили'));

        $parts = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($s))) ?: [];

        return array_values(array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 3 && ! isset($stop[$w])));
    }

    private function stem(string $w): string
    {
        return mb_strlen($w) > 5 ? mb_substr($w, 0, max(4, mb_strlen($w) - 2)) : $w;
    }

    // ---------------------------------------------------------------------
    // Стенограмма → материал для модели
    // ---------------------------------------------------------------------

    /**
     * Короткая стенограмма идёт как есть. Длинная режется на части по
     * knowledge.lesson_ai.part_chars, каждая сжимается в тезисы с таймкодами;
     * результат кэшируется вместе с пересказом — вопросы потом не пересчитывают.
     *
     * @return array{text: string, parts: int}
     */
    public function digest(Lesson $lesson): array
    {
        $lines = $this->transcriptLines($lesson);
        if ($lines === []) {
            throw new RuntimeException('no-transcript');
        }

        $partChars = max(2000, (int) config('knowledge.lesson_ai.part_chars', 12000));
        $parts = [];
        $cur = '';
        foreach ($lines as $l) {
            $row = '['.$this->timecode($l['t']).'] '.$l['text'];
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($row) + 1 > $partChars) {
                $parts[] = $cur;
                $cur = '';
            }
            $cur .= ($cur === '' ? '' : "\n").$row;
        }
        if ($cur !== '') {
            $parts[] = $cur;
        }

        if (count($parts) === 1) {
            return ['text' => $parts[0], 'parts' => 1];
        }

        $key = 'lesson_ai.digest.'.$lesson->id.'.'.$this->fingerprint($lesson);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return ['text' => $cached, 'parts' => count($parts)];
        }

        $out = [];
        foreach ($parts as $n => $part) {
            $out[] = '### Часть '.($n + 1)."\n".$this->chat(
                'Перед тобой часть расшифровки занятия, каждая строка начинается с таймкода [мм:сс]. '
                    .'Это автоматическое распознавание устной речи. Сожми её в 6–12 тезисов списком «- [мм:сс] тезис»: '
                    .'определения, правила, примеры, выводы. Таймкод — начало места, где это сказано, только из расшифровки. '
                    .'Пропускай организационные разговоры и болтовню. '.self::NO_INTRO,
                $this->titleLine($lesson).$part,
                1200,
            );
        }
        $text = implode("\n\n", $out);
        Cache::put($key, $text, self::SUMMARY_TTL);

        return ['text' => $text, 'parts' => count($parts)];
    }

    // ---------------------------------------------------------------------
    // Модель и рендер
    // ---------------------------------------------------------------------

    private function chat(string $system, string $user, int $maxTokens, float $temperature = 0.3): string
    {
        $r = $this->ai->localChatWithUsage([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], null, $temperature, false, $maxTokens);

        $text = $r['content'];
        if ($text === null) {
            throw new RuntimeException('offline');
        }
        // qwen3 иногда отдаёт рассуждение в теле ответа даже без think.
        $text = trim((string) preg_replace('/<think>.*?<\/think>/su', '', $text));
        if ($text === '') {
            throw new RuntimeException('bad-answer');
        }

        return $text;
    }

    /**
     * Markdown ответа → безопасный HTML (сырой HTML модели вырезается),
     * таймкоды [мм:сс] → кнопки перехода по записи (data-ts — секунды).
     */
    public function render(string $md): string
    {
        $html = (string) Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return (string) preg_replace_callback(
            '/\[(\d{1,2}:\d{2}(?::\d{2})?)\]/',
            static function (array $m): string {
                $sec = 0;
                foreach (explode(':', $m[1]) as $p) {
                    $sec = $sec * 60 + (int) $p;
                }

                return '<button type="button" class="lesson-ai-ts" data-ts="'.$sec.'"><i class="fas fa-play"></i>'.$m[1].'</button>';
            },
            $html,
        );
    }

    /** @return array<string, mixed>|null */
    private function extractJson(string $text): ?array
    {
        $t = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text));
        $a = strpos($t, '{');
        $b = strrpos($t, '}');
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        $d = json_decode(substr($t, $a, $b - $a + 1), true);

        return is_array($d) ? $d : null;
    }

    private function titleLine(Lesson $lesson): string
    {
        $course = $lesson->course?->title;
        $title = trim((string) $lesson->title);

        return ($course ? "Курс: {$course}\n" : '').($title !== '' ? "Занятие: {$title}\n\n" : "\n");
    }

    private function timecode(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    private function fingerprint(Lesson $lesson): string
    {
        return substr(md5(self::PROMPT_VERSION.'|'.$lesson->transcript_file.'|'.$lesson->updated_at?->timestamp.'|'.config('knowledge.generation_model')), 0, 12);
    }

    private function summaryKey(Lesson $lesson): string
    {
        return 'lesson_ai.summary.'.$lesson->id.'.'.$this->fingerprint($lesson);
    }
}
