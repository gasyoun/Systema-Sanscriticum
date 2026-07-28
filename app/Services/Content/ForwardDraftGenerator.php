<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\DictionaryWord;
use App\Models\Schedule;
use App\Services\Bot\CuratorAi;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Drafts NEW copy for empty `forward`-type ContentCalendarSlot rows (H1567,
 * content calendar Wave 4, ROADMAP "Wave 4"). Rotates four template kinds —
 * reading-group tease, dictionary tip, event promo, FAQ-style micro-answer —
 * grounded in real app data where a safe resolver exists (DictionaryWord,
 * Schedule, the "Новичкам" section of resources/knowledge/faq.md, which is
 * deliberately outside the money/policy FAQ sections). CuratorAi polishes the
 * deterministic base when an OpenRouter key is set; the base itself always
 * covers the no-key/test path (same idiom as SocialDraftGenerator /
 * ArticleDraftGenerator / FaqDraftGenerator).
 *
 * Copy register is governed by docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md (H1754),
 * derived from measured wall engagement: fact-led communal voice, no emoji, no
 * hashtags, no marketing clichés, at most one neutral link. A change to the
 * contract changes these templates and prompts in the same PR.
 *
 * D12 (skip-review): forward is NEW copy, so a filled slot only ever moves
 * `empty` -> `draft` here — never `scheduled`. Only a human monthly Keep in
 * Filament can schedule it.
 */
final class ForwardDraftGenerator
{
    /** Cost cap: CuratorAi calls per artisan run (mirrors ArticleDraftGenerator::WEEKLY_LESSON_LIMIT). */
    public const DEFAULT_MAX_PER_RUN = 10;

    private const KINDS = ['reading_group_tease', 'dictionary_tip', 'event_promo', 'faq_micro_answer'];

    private const FAQ_SAFE_SECTION_HEADING = '## Новичкам (о санскрите и обучении)';

    /**
     * Shared polish constraints — VOICE_CONTRACT §2–§6 condensed for CuratorAi.
     * The banned-phrase list is the measured-underperformer table (§3), not taste.
     */
    private const VOICE_PROMPT_RULES = 'Голос — предметный и конкретный, от первого лица '.
        'множественного числа общины изучающих санскрит. Факты, даты, имена и названия сохраняй '.
        'точно, названия текстов — в «ёлочках»; ничего не выдумывай. Без markdown, без хэштегов, '.
        'без эмодзи. Запрещены рекламные штампы: «уникальная возможность», «не упустите», '.
        '«успейте», «спешите», «приглашаем вас», «погрузитесь», «откройте для себя», «дорогие '.
        'друзья», зачин вопросом и цепочки риторических вопросов. Не добавляй призывов «пишите», '.
        '«запишитесь» и новых ссылок; имеющуюся ссылку сохрани как есть.';

    public function __construct(private readonly CuratorAi $curatorAi) {}

    /**
     * @return array{kind: string, title: string, body: string, meta: array<string, mixed>}|null
     */
    public function draft(string $kind, int $seed = 0): ?array
    {
        return match ($kind) {
            'reading_group_tease' => $this->readingGroupTease(),
            'dictionary_tip' => $this->dictionaryTip($seed),
            'event_promo' => $this->eventPromo(),
            'faq_micro_answer' => $this->faqMicroAnswer($seed),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    public function kindForIndex(int $i): string
    {
        return self::KINDS[$i % count(self::KINDS)];
    }

    private function readingGroupTease(): array
    {
        $base = 'Читательский клуб ОРС: собираемся онлайн и читаем санскритские тексты в '.
            'оригинале — от отдельных шлок до глав «Бхагавадгиты». Каждое слово разбираем по '.
            'составу, перевод сверяем со словарём, тёмные места обсуждаем сообща. Расписание '.
            'ближайшей встречи — в сообществе.';

        $body = $this->polish(
            $base,
            'Ты — редактор сообщества «Общество ревнителей санскрита» (ВК). Перепиши приглашение '.
            'в читательский клуб, 2–3 абзаца, 400–900 знаков. '.self::VOICE_PROMPT_RULES,
        );

        return [
            'kind' => 'reading_group_tease',
            'title' => 'Приглашение в читательский клуб',
            'body' => $body,
            'meta' => ['source' => 'template'],
        ];
    }

    private function dictionaryTip(int $seed): ?array
    {
        // `is_indexable` is an unrelated SEO curated-core flag (H210) — not a
        // content-quality signal, so it's deliberately not filtered on here.
        $word = DictionaryWord::query()
            ->whereNotNull('translation')
            ->where('translation', '!=', '')
            ->inRandomOrder((string) $seed)
            ->first();

        if ($word === null) {
            $base = 'В санскрите есть слова, которым в русском нет однословного эквивалента, — '.
                'их переводят целой фразой. Такие слова мы разбираем по одному: состав, оттенки '.
                'значения, примеры из текстов. Словарь открыт на сайте.';

            return [
                'kind' => 'dictionary_tip',
                'title' => 'Слово дня',
                'body' => $this->polish($base, 'Ты — редактор словарной рубрики сообщества '.
                    'санскритологов. Перепиши заметку о словаре, 1–2 абзаца. '.self::VOICE_PROMPT_RULES),
                'meta' => ['source' => 'template'],
            ];
        }

        $headword = trim((string) ($word->devanagari ?: $word->iast ?: $word->cyrillic));
        $translation = trim((string) $word->translation);
        // VOICE_CONTRACT §2: the word itself is the fact-lead; devanagari + IAST
        // measure above baseline (2.98% vs 2.73%), so both stay in.
        $base = "Слово дня: {$headword}".($word->iast !== null && $word->devanagari !== null ? " ({$word->iast})" : '').
            " — {$translation}. Разбор по составу и параллели из словарей — то, чем мы занимаемся ".
            'на курсе санскрита.';

        return [
            'kind' => 'dictionary_tip',
            'title' => 'Слово дня: '.$headword,
            'body' => $this->polish($base, 'Ты — редактор словарной рубрики сообщества '.
                'санскритологов. Перепиши разбор слова дня, 1–2 абзаца; заглавное слово, '.
                'транслитерацию и перевод сохрани дословно. '.self::VOICE_PROMPT_RULES),
            'meta' => ['source' => 'dictionary_word', 'dictionary_word_id' => $word->id],
        ];
    }

    private function eventPromo(): array
    {
        $schedule = Schedule::query()
            ->where('start', '>', now())
            ->whereHas('course', fn ($q) => $q->where('is_visible', true))
            ->with('course')
            ->orderBy('start')
            ->first();

        if ($schedule === null || $schedule->course === null) {
            $base = 'Курсы санскрита в школе идут круглый год — от азбуки деванагари до чтения '.
                'текстов в оригинале. Каталог курсов и даты ближайших стартов — на сайте.';

            return [
                'kind' => 'event_promo',
                'title' => 'Курсы санскрита',
                'body' => $this->polish($base, 'Ты — редактор анонсов школы санскрита. Перепиши '.
                    'общий анонс курсов, 1–2 абзаца, без цен. '.self::VOICE_PROMPT_RULES),
                'meta' => ['source' => 'template'],
            ];
        }

        $courseTitle = trim((string) $schedule->course->title);
        // Numeric d.m rather than translatedFormat() — app locale is 'en' by
        // default in console context, which would mix an English month name
        // into Russian copy.
        $when = $schedule->start->format('d.m');
        // VOICE_CONTRACT §4: event copy leads with the date + fact (14% of the
        // top decile vs 7% of the bottom half), not with a wind-up.
        $base = "{$when} — очередное занятие курса «{$courseTitle}». Идём по программе; ".
            'присоединиться можно и в середине блока — записи прошлых уроков открыты.';

        return [
            'kind' => 'event_promo',
            'title' => 'Скоро: '.$courseTitle,
            'body' => $this->polish($base, 'Ты — редактор анонсов школы санскрита. Перепиши анонс '.
                'занятия, 1–2 абзаца; первым предложением оставь дату и название курса; без цен; '.
                'не выдумывай фактов сверх названия курса и даты. '.self::VOICE_PROMPT_RULES),
            'meta' => ['source' => 'schedule', 'schedule_id' => $schedule->id, 'course_id' => $schedule->course_id],
        ];
    }

    private function faqMicroAnswer(int $seed): ?array
    {
        $entries = $this->safeFaqEntries();
        if ($entries === []) {
            $base = 'Зачем сегодня учить санскрит — вопрос, который нам задают чаще всего. '.
                'Ответы у каждого свои: читать мантры и первоисточники в оригинале, а не в '.
                'пересказе; разбирать тексты с комментариями; тренировать ум строгой грамматикой '.
                'Панини. Больше вводных ответов — в нашем FAQ.';

            return [
                'kind' => 'faq_micro_answer',
                'title' => 'Знаете ли вы?',
                'body' => $this->polish($base, 'Ты — редактор образовательного FAQ по санскриту. '.
                    'Перепиши заметку, 1–2 абзаца. '.self::VOICE_PROMPT_RULES),
                'meta' => ['source' => 'template'],
            ];
        }

        $entry = $entries[$seed % count($entries)];
        $base = "{$entry['heading']}: {$entry['text']}";
        if ($entry['url'] !== null) {
            // "Подробнее: URL" is the one CTA form that measures at baseline
            // (VOICE_CONTRACT §6) — every harder CTA form measures below it.
            $base .= " Подробнее: {$entry['url']}";
        }

        return [
            'kind' => 'faq_micro_answer',
            'title' => $entry['heading'],
            'body' => $this->polish($base, 'Ты — редактор образовательного FAQ по санскриту. '.
                'Перепиши заметку вопрос-ответ, 1–2 абзаца; отвечай телом текста, не встречным '.
                'вопросом; факты не выдумывай сверх данного текста. '.self::VOICE_PROMPT_RULES),
            'meta' => ['source' => 'faq_knowledge_base', 'faq_heading' => $entry['heading']],
        ];
    }

    /**
     * @return list<array{heading: string, text: string, url: ?string}>
     */
    private function safeFaqEntries(): array
    {
        $path = resource_path('knowledge/faq.md');
        if (! File::exists($path)) {
            return [];
        }

        $content = (string) File::get($path);
        $sectionStart = mb_strpos($content, self::FAQ_SAFE_SECTION_HEADING);
        if ($sectionStart === false) {
            return [];
        }

        $section = mb_substr($content, $sectionStart + mb_strlen(self::FAQ_SAFE_SECTION_HEADING));
        $nextSection = mb_strpos($section, "\n## ");
        if ($nextSection !== false) {
            $section = mb_substr($section, 0, $nextSection);
        }

        $entries = [];
        if (preg_match_all('/^### (.+)\n((?:(?!^#).*\n?)*)/mu', $section, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $m) {
            $heading = trim($m[1]);
            $body = trim($m[2]);
            if ($heading === '' || $body === '') {
                continue;
            }

            $url = null;
            if (preg_match('/https?:\/\/\S+/u', $body, $urlMatch) === 1) {
                $url = $urlMatch[0];
            }
            $text = trim(preg_replace('/Подробнее:\s*https?:\/\/\S+/u', '', $body) ?? $body);
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text === '') {
                continue;
            }

            $entries[] = ['heading' => $heading, 'text' => $text, 'url' => $url];
        }

        return $entries;
    }

    private function polish(string $base, string $systemPrompt): string
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return $base;
        }

        $generated = $this->curatorAi->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $base],
        ]);

        $generated = $generated !== null ? trim($generated) : '';
        if ($generated === '') {
            return $base;
        }

        $violation = VoiceContractLinter::violation($generated);
        if ($violation !== null) {
            Log::info('ForwardDraftGenerator: CuratorAi polish violated the voice contract, falling back to base', [
                'rule' => $violation,
            ]);

            return $base;
        }

        return $generated;
    }
}
