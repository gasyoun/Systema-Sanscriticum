<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Knowledge\LessonRetriever;
use Illuminate\Support\Facades\Cache;

/**
 * Этап 4 — ответ студенту по расшифровкам ЕГО уроков.
 *
 * Границы, которые здесь держатся жёстко:
 *  - выдача ограничена доступом (см. {@see LessonRetriever}), ответ собирается
 *    только из вернувшихся фрагментов;
 *  - генерация ТОЛЬКО локальная ({@see CuratorAi::localChatWithUsage()}).
 *    Внешнего фолбэка нет — текст платного занятия не уходит провайдеру, это
 *    прямое следствие рулинга #1633 об отсутствии отката в облако;
 *  - цитируется коротко: до knowledge.lesson.max_quotes выдержек по
 *    quote_chars знаков. Бот не выгружает лекцию целиком — ради этого её и
 *    убрали с публичного диска (H3308).
 *
 * Узел недоступен → возвращаем STATUS_QUEUED, вопрос доигрывает джоба.
 */
final class LessonQaService
{
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_NOTHING = 'nothing';

    public const STATUS_QUEUED = 'queued';

    /** Ключ закреплённого урока живёт сутки — дольше студент и не помнит. */
    private const PIN_TTL = 86400;

    public function __construct(
        private readonly LessonRetriever $retriever,
        private readonly CuratorAi $ai,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.lesson_qa', false);
    }

    public function pin(User $user, int $lessonId): void
    {
        Cache::put($this->pinKey($user), $lessonId, self::PIN_TTL);
    }

    public function unpin(User $user): void
    {
        Cache::forget($this->pinKey($user));
    }

    public function pinned(User $user): ?int
    {
        $value = Cache::get($this->pinKey($user));

        // Прод-кэш — Redis без сериализатора, и скаляр возвращается СТРОКОЙ
        // ('1661', не 1661). is_int() читал это как «закрепления нет»: студент
        // получал «Закрепил занятие», а поиск шёл по всем доступным урокам.
        // Контракт метода — «id или null», поэтому приводим тип сами, а не
        // полагаемся на драйвер кэша (тесты на array-кэше дефект не видят).
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{status: string, text: ?string, hits: list<array<string, mixed>>}
     */
    public function answer(User $user, string $question): array
    {
        $pinned = $this->pinned($user);
        $hits = $this->retriever->retrieve($user, $question, $pinned !== null ? [$pinned] : null);

        if ($hits === []) {
            return ['status' => self::STATUS_NOTHING, 'text' => null, 'hits' => []];
        }

        // Без закрепления вопрос должен САМ выглядеть «про урок»: слабое
        // совпадение отдаём обычному ИИ-куратору, иначе бот начнёт отвечать
        // цитатами лекции на «когда занятие» и «как оплатить».
        if ($pinned === null) {
            $bestCos = max(array_map(static fn (array $hit): float => (float) $hit['cos'], $hits));
            if ($bestCos < (float) config('knowledge.lesson.min_score', 0.45)) {
                return ['status' => self::STATUS_NOTHING, 'text' => null, 'hits' => []];
            }
        }

        $answer = $this->ai->localChatWithUsage([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($question, $hits)],
        ])['content'];

        if ($answer === null) {
            return ['status' => self::STATUS_QUEUED, 'text' => null, 'hits' => $hits];
        }

        return ['status' => self::STATUS_ANSWERED, 'text' => $this->render($answer, $hits), 'hits' => $hits];
    }

    /** Собрать финальный текст из ответа модели и цитат с таймкодами. */
    public function render(string $answer, array $hits): string
    {
        $maxQuotes = max(1, (int) config('knowledge.lesson.max_quotes', 3));
        $quoteChars = max(80, (int) config('knowledge.lesson.quote_chars', 280));

        $lines = [trim($answer), '', '<b>Где это в записи</b>'];
        $shown = 0;
        foreach ($hits as $hit) {
            if ($shown >= $maxQuotes) {
                break;
            }
            /** @var Lesson $lesson */
            $lesson = $hit['lesson'];
            $chunk = $hit['chunk'];
            $timecode = $this->timecode((int) $chunk->start_seconds);
            $quote = $this->quote((string) $chunk->text, $quoteChars);

            $line = '• '.e($lesson->title).' — '.$timecode;
            if ($lesson->course) {
                $url = route('student.lesson', [$lesson->course->slug, $lesson->id]);
                $line .= " <a href='".e($url)."'>открыть урок</a>";
            }
            $lines[] = $line;
            $lines[] = '<i>'.e($quote).'</i>';
            $shown++;
        }

        return rtrim(implode("\n", $lines));
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'Ты помогаешь студенту Академии Санскрита разобраться в его занятии.',
            'Отвечай СТРОГО по приведённым фрагментам дословной расшифровки: это устная речь,',
            'с оговорками и ошибками распознавания, но домысливать за неё нельзя.',
            'Если во фрагментах ответа нет, так и скажи одной фразой.',
            'Отвечай по-русски, кратко, без вступлений. Не пересказывай весь урок целиком.',
        ]);
    }

    /** @param  list<array<string, mixed>>  $hits */
    private function userPrompt(string $question, array $hits): string
    {
        $parts = [];
        foreach ($hits as $hit) {
            /** @var Lesson $lesson */
            $lesson = $hit['lesson'];
            $chunk = $hit['chunk'];
            $parts[] = '['.$lesson->title.', '.$this->timecode((int) $chunk->start_seconds).'] '.$chunk->text;
        }

        return "Вопрос: {$question}\n\nФрагменты расшифровки:\n".implode("\n\n", $parts);
    }

    private function timecode(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    private function quote(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) <= $maxChars ? $text : rtrim(mb_substr($text, 0, $maxChars)).'…';
    }

    private function pinKey(User $user): string
    {
        return 'lesson_qa.pin.'.$user->id;
    }
}
