<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Knowledge\AccessibleLessonIds;

/**
 * Этап 4 — детерминированные команды вокруг вопросов по урокам.
 *
 * Конвенция та же, что у {@see StudentSelfService}: matchesXIntent() + ответ
 * готовой Telegram-HTML строкой, собранной ТОЛЬКО из фактов БД. Никакого LLM
 * здесь нет: список доступных занятий — это данные, выдумывать их запрещено.
 */
final class LessonQaBotCommands
{
    /** @var list<string> */
    private const LIST_PHRASES = ['/уроки', '/lessons', 'мои уроки', 'какие уроки', 'список уроков'];

    /** @var list<string> */
    private const PIN_PHRASES = ['/урок', '/lesson'];

    public function __construct(
        private readonly AccessibleLessonIds $accessible,
        private readonly LessonQaService $qa,
    ) {}

    public function matchesListIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        foreach (self::LIST_PHRASES as $phrase) {
            if (str_contains($t, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public function matchesPinIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        foreach (self::PIN_PHRASES as $phrase) {
            if ($t === $phrase || str_starts_with($t, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    /** Список занятий с расшифровкой, открытых этому студенту. */
    public function listSummary(User $user): string
    {
        $lessons = $this->lessons($user);
        if ($lessons === []) {
            return '📚 <b>Вопросы по занятиям</b>'."\n\n".
                'Пока нет ни одного занятия с расшифровкой, открытого вам. Расшифровка появляется через несколько часов после занятия.';
        }

        $lines = ['📚 <b>Занятия, по которым можно спросить</b>', ''];
        foreach ($lessons as $index => $lesson) {
            $number = $index + 1;
            $date = $lesson->lesson_date?->format('d.m.y');
            $lines[] = "{$number}. <b>".e((string) $lesson->title).'</b>'.($date ? " — {$date}" : '');
        }
        $lines[] = '';
        $lines[] = 'Просто задайте вопрос текстом — я поищу по всем этим занятиям.';
        $lines[] = 'Чтобы сузить до одного: <code>/урок 1</code>. Снять: <code>/урок сброс</code>.';

        return rtrim(implode("\n", $lines));
    }

    /** Закрепить или снять урок. Возвращает готовый ответ студенту. */
    public function handlePin(User $user, string $text): string
    {
        $argument = trim(mb_strtolower(preg_replace('~^/(урок|lesson)~u', '', trim($text)) ?? ''));

        if ($argument === '' || in_array($argument, ['сброс', 'reset', 'off', 'все', 'всё'], true)) {
            $this->qa->unpin($user);

            return 'Ок, ищу по всем вашим занятиям сразу.';
        }

        $lessons = $this->lessons($user);
        $index = (int) filter_var($argument, FILTER_SANITIZE_NUMBER_INT);

        if ($index < 1 || $index > count($lessons)) {
            return 'Не понял, какое занятие. Пришлите <code>/уроки</code> — там номера, затем <code>/урок 2</code>.';
        }

        $lesson = $lessons[$index - 1];
        $this->qa->pin($user, (int) $lesson->id);

        return 'Закрепил занятие: <b>'.e((string) $lesson->title).'</b>. Теперь отвечаю только по нему. Снять — <code>/урок сброс</code>.';
    }

    /**
     * Порядок списка обязан быть стабильным: студент нумерует занятия в одном
     * сообщении, а закрепляет другим.
     *
     * @return list<Lesson>
     */
    private function lessons(User $user): array
    {
        $ids = $this->accessible->forUser($user);
        if ($ids === []) {
            return [];
        }

        return Lesson::query()
            ->with('course')
            ->whereIn('id', $ids)
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
