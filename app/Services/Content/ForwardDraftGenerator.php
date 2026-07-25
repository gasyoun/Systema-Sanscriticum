<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\DictionaryWord;
use App\Models\Schedule;
use App\Services\Bot\CuratorAi;
use Illuminate\Support\Facades\File;

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
        $base = '📖 Читательский клуб: собираемся, чтобы вместе разбирать санскритские тексты — '.
            'от простых строф до отрывков «Бхагавадгиты». Присоединиться может любой уровень, '.
            'заходите в сообщество узнать расписание ближайшей встречи.';

        $body = $this->polish(
            $base,
            'Ты — маркетолог школы санскрита. Перепиши приглашение в читательский клуб коротко и дружелюбно, без markdown, без хэштегов, максимум 3 абзаца.',
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
            $base = '🔤 Санскрит богат словами, у которых нет точного эквивалента в русском — '.
                'каждое такое слово стоит того, чтобы разобрать его отдельно. Загляните в словарь на сайте!';

            return [
                'kind' => 'dictionary_tip',
                'title' => 'Слово дня',
                'body' => $this->polish($base, 'Ты — редактор школы санскрита. Перепиши заметку о словаре коротко, без markdown, максимум 2 абзаца.'),
                'meta' => ['source' => 'template'],
            ];
        }

        $headword = trim((string) ($word->devanagari ?: $word->iast ?: $word->cyrillic));
        $translation = trim((string) $word->translation);
        $base = "🔤 Слово дня: {$headword}".($word->iast !== null && $word->devanagari !== null ? " ({$word->iast})" : '').
            " — {$translation}. Такие разборы — часть нашего курса санскрита.";

        return [
            'kind' => 'dictionary_tip',
            'title' => 'Слово дня: '.$headword,
            'body' => $this->polish($base, 'Ты — редактор школы санскрита. Перепиши разбор слова дня коротко и живо, без markdown, максимум 2 абзаца, факты не выдумывай — используй только данное слово и перевод.'),
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
            $base = '🎓 Курсы санскрита идут постоянно — от полного новичка до продвинутого уровня. '.
                'Загляните в каталог курсов, чтобы выбрать свой.';

            return [
                'kind' => 'event_promo',
                'title' => 'Курсы санскрита',
                'body' => $this->polish($base, 'Ты — маркетолог школы санскрита. Перепиши общий анонс курсов коротко, без markdown, без цен, максимум 2 абзаца.'),
                'meta' => ['source' => 'template'],
            ];
        }

        $courseTitle = trim((string) $schedule->course->title);
        // Numeric d.m rather than translatedFormat() — app locale is 'en' by
        // default in console context, which would mix an English month name
        // into Russian copy.
        $when = $schedule->start->format('d.m');
        $base = "🎓 Ближайшее занятие курса «{$courseTitle}» — {$when}. Присоединиться можно и в процессе — ".
            'запись прошлых уроков доступна.';

        return [
            'kind' => 'event_promo',
            'title' => 'Скоро: '.$courseTitle,
            'body' => $this->polish($base, 'Ты — маркетолог школы санскрита. Перепиши анонс ближайшего занятия коротко, без markdown, без цен, максимум 2 абзаца — не выдумывай факты сверх названия курса и даты.'),
            'meta' => ['source' => 'schedule', 'schedule_id' => $schedule->id, 'course_id' => $schedule->course_id],
        ];
    }

    private function faqMicroAnswer(int $seed): ?array
    {
        $entries = $this->safeFaqEntries();
        if ($entries === []) {
            $base = '💡 Часто спрашивают, зачем сегодня учить санскрит — от чтения мантр в оригинале до тренировки ума. '.
                'Больше вводных материалов — в нашем FAQ.';

            return [
                'kind' => 'faq_micro_answer',
                'title' => 'Знаете ли вы?',
                'body' => $this->polish($base, 'Ты — редактор образовательного FAQ школы санскрита. Перепиши короткую заметку, без markdown, максимум 2 абзаца.'),
                'meta' => ['source' => 'template'],
            ];
        }

        $entry = $entries[$seed % count($entries)];
        $base = "💡 {$entry['heading']}: {$entry['text']}";
        if ($entry['url'] !== null) {
            $base .= " Подробнее: {$entry['url']}";
        }

        return [
            'kind' => 'faq_micro_answer',
            'title' => $entry['heading'],
            'body' => $this->polish($base, 'Ты — редактор образовательного FAQ школы санскрита. Перепиши заметку коротко и живо, без markdown, максимум 2 абзаца, факты не выдумывай сверх данного текста, ссылку сохрани как есть.'),
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

        return $generated !== '' ? $generated : $base;
    }
}
