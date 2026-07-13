<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\ActivityEvent;
use App\Models\Group;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Support\Roles;

/**
 * Куратор-команды `/группа [название]` и `/кто <имя|@username>` (тикет S6
 * support-roadmap, H816 PR 3) — ростер группы и поиск студента прямо в Telegram,
 * без захода в админку. Чистый LMS-запрос, без LLM и новых кред. Долговой маркер
 * ⚠️/✅ берётся из ПРИСУТСТВИЯ студента в DebtorsReport (read-only, БЕЗ подсчёта
 * суммы — денежная логика не дублируется). Та же роль-авторизация, что у
 * DebtorsBotCommand (S4): вызывающий код обязан сам проверить isCurator() ДО reply().
 */
class RosterBotCommand
{
    public const COMMAND_GROUP = '/группа';

    public const COMMAND_WHO = '/кто';

    private const ROSTER_LIMIT = 40;

    private const SEARCH_LIMIT = 10;

    public static function isCurator(User $user): bool
    {
        return $user->isSuperAdmin() || in_array($user->role, [Roles::ADMIN, Roles::MANAGER], true);
    }

    public function isCommand(string $text): bool
    {
        return $this->matchesPrefix($text, self::COMMAND_GROUP) || $this->matchesPrefix($text, self::COMMAND_WHO);
    }

    private function matchesPrefix(string $text, string $command): bool
    {
        $trimmed = trim($text);

        return $trimmed === $command || str_starts_with($trimmed, $command.' ');
    }

    private function argOf(string $text, string $command): string
    {
        return trim(mb_substr(trim($text), mb_strlen($command)));
    }

    public function reply(User $curator, string $text): string
    {
        if ($this->matchesPrefix($text, self::COMMAND_GROUP)) {
            $arg = $this->argOf($text, self::COMMAND_GROUP);
            $this->logUsage($curator, 'gruppa', $arg !== '' ? $arg : null);

            return $arg === '' ? $this->groupList() : $this->groupRoster($arg);
        }

        $arg = $this->argOf($text, self::COMMAND_WHO);
        $this->logUsage($curator, 'kto', $arg !== '' ? $arg : null);

        return $arg === '' ? 'Укажите имя или @username: /кто Иван' : $this->whoSearch($arg);
    }

    /** `/группа` без аргумента — список групп-подсказка. */
    private function groupList(): string
    {
        $groups = Group::query()->orderBy('name')->limit(self::ROSTER_LIMIT)->pluck('name');

        if ($groups->isEmpty()) {
            return 'Групп пока нет.';
        }

        $lines = ['👥 Группы (укажите название: /группа <имя>):', ''];
        foreach ($groups as $name) {
            $lines[] = '· '.$name;
        }

        return implode("\n", $lines);
    }

    /** `/группа <название>` — активный ростер с долговым маркером. */
    private function groupRoster(string $arg): string
    {
        $group = Group::query()->where('name', 'like', '%'.$arg.'%')->first();
        if (! $group) {
            return "Группа «{$arg}» не найдена.";
        }

        $students = $group->activeUsers()->orderBy('name')->get();
        $courseNames = $group->courses()->pluck('title');
        $debtorIds = $this->debtorIdsFor($group, $students->pluck('id')->all());

        $lines = [];
        $lines[] = "👥 Группа «{$group->name}»";
        if ($courseNames->isNotEmpty()) {
            $lines[] = 'Курс(ы): '.$courseNames->implode(', ');
        }
        $lines[] = 'Активных студентов: '.$students->count();

        if ($students->isEmpty()) {
            $lines[] = '';
            $lines[] = 'В группе пока нет активных студентов.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        foreach ($students->take(self::ROSTER_LIMIT) as $student) {
            $marker = isset($debtorIds[(int) $student->id]) ? '⚠️' : '✅';
            $handle = $student->telegram_username ? ' @'.$student->telegram_username : '';
            $lines[] = "{$marker} ".($student->name ?: $student->email).$handle;
        }

        if ($students->count() > self::ROSTER_LIMIT) {
            $lines[] = '… и ещё '.($students->count() - self::ROSTER_LIMIT);
        }

        $lines[] = '';
        $lines[] = '⚠️ — есть долг · ✅ — без долга';
        $lines[] = 'Подробнее: '.config('app.url').'/admin/groups';

        return implode("\n", $lines);
    }

    /** `/кто <имя|@username>` — поиск студента по имени/username/email. */
    private function whoSearch(string $arg): string
    {
        $needle = ltrim($arg, '@');
        $like = '%'.$needle.'%';

        $matches = User::query()
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('telegram_username', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT + 1)
            ->get();

        if ($matches->isEmpty()) {
            return "Студент «{$arg}» не найден.";
        }

        if ($matches->count() === 1) {
            return $this->studentCard($matches->first());
        }

        $lines = ["🔎 Найдено несколько по «{$arg}»:", ''];
        foreach ($matches->take(self::SEARCH_LIMIT) as $user) {
            $handle = $user->telegram_username ? ' @'.$user->telegram_username : '';
            $groups = $user->activeGroups->pluck('name')->implode(', ');
            $lines[] = '· '.($user->name ?: $user->email).$handle.($groups !== '' ? " — {$groups}" : '');
        }
        if ($matches->count() > self::SEARCH_LIMIT) {
            $lines[] = '… уточните запрос';
        }

        return implode("\n", $lines);
    }

    private function studentCard(User $user): string
    {
        $lines = [];
        $lines[] = '👤 '.($user->name ?: $user->email);
        if ($user->telegram_username) {
            $lines[] = 'Telegram: @'.$user->telegram_username;
        }
        if ($user->email) {
            $lines[] = 'Email: '.$user->email;
        }

        $groups = $user->activeGroups()->with('courses')->orderBy('name')->get();
        $lines[] = '';
        if ($groups->isEmpty()) {
            $lines[] = 'Активных групп нет.';
        } else {
            $lines[] = 'Активные группы:';
            foreach ($groups as $group) {
                $courses = $group->courses->pluck('title')->implode(', ');
                $lines[] = '· '.$group->name.($courses !== '' ? " ({$courses})" : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * ID студентов группы, числящихся должниками (присутствие в DebtorsReport,
     * без подсчёта суммы). Возвращает set вида [userId => true].
     *
     * @param  array<int, int|string>  $studentIds
     * @return array<int, bool>
     */
    private function debtorIdsFor(Group $group, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $courseIds = $group->courses()->pluck('courses.id')->all();
        if ($courseIds === []) {
            return [];
        }

        return app(DebtorsReport::class)->query()
            ->whereIn('d.course_id', $courseIds)
            ->whereIn('users.id', $studentIds)
            ->limit(5000)
            ->get()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    private function logUsage(User $curator, string $command, ?string $arg): void
    {
        ActivityEvent::create([
            'user_id' => $curator->id,
            'event_type' => ActivityEvent::TYPE_CURATOR_BOT_COMMAND,
            'event_data' => ['command' => $command, 'arg' => $arg],
            'created_at' => now(),
        ]);
    }
}
