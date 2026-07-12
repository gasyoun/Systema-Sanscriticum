<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Filament\Pages\Debtors;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Support\Roles;
use Illuminate\Support\Collection;

/**
 * Куратор-команды `/долги [группа]`, `/группа [название]`, `/кто <имя/username>`
 * поверх DebtorsReport/ClassRoster-паттерна (тикеты S4+S6 годового
 * support-roadmap, H250/H816) — сводки прямо в Telegram, без захода в
 * админку. Денежная логика долга целиком делегирована DebtorsReport +
 * публичным хелперам Debtors::debtBlocks/preloadPairCaches — здесь
 * НИКАКОЙ денежной логики не дублируется, только форматирование ответа.
 */
class DebtorsBotCommand
{
    public const COMMAND_DEBTORS = '/долги';

    public const COMMAND_GROUP = '/группа';

    public const COMMAND_WHO = '/кто';

    private const TOP_LIMIT = 5;

    /** Лимит строк в ростере/списке групп одним сообщением — Telegram-сообщение не резиновое. */
    private const ROSTER_LIMIT = 30;

    /**
     * Роли, которым доступна команда — совпадает с гейтом «куратор» из
     * CRM-cockpit (WorkQueue): admin/manager, super_admin проходит всегда.
     */
    public static function isCurator(User $user): bool
    {
        return $user->isSuperAdmin() || in_array($user->role, [Roles::ADMIN, Roles::MANAGER], true);
    }

    public function isCommand(string $text): bool
    {
        return $this->isDebtorsCommand($text) || $this->isGroupCommand($text) || $this->isWhoCommand($text);
    }

    public function isDebtorsCommand(string $text): bool
    {
        return $this->matchesPrefix($text, self::COMMAND_DEBTORS);
    }

    public function isGroupCommand(string $text): bool
    {
        return $this->matchesPrefix($text, self::COMMAND_GROUP);
    }

    public function isWhoCommand(string $text): bool
    {
        return $this->matchesPrefix($text, self::COMMAND_WHO);
    }

    private function matchesPrefix(string $text, string $command): bool
    {
        $trimmed = trim($text);

        return $trimmed === $command || str_starts_with($trimmed, $command.' ');
    }

    private function argAfter(string $text, string $command): string
    {
        return trim(mb_substr(trim($text), mb_strlen($command)));
    }

    /**
     * Диспетчер: строит текст ответа для авторизованного куратора и логирует
     * использование (для deflection-отчёта S2/S7). Вызывающий код обязан
     * сам проверить DebtorsBotCommand::isCurator() ДО вызова — здесь
     * авторизация не проверяется повторно.
     */
    public function reply(User $curator, string $text): string
    {
        if ($this->isGroupCommand($text)) {
            $arg = $this->argAfter($text, self::COMMAND_GROUP);
            $this->logUsage($curator, 'gruppa', $arg !== '' ? $arg : null);

            return $arg === '' ? $this->groupsOverview() : $this->groupDetail($arg);
        }

        if ($this->isWhoCommand($text)) {
            $arg = $this->argAfter($text, self::COMMAND_WHO);
            $this->logUsage($curator, 'kto', $arg !== '' ? $arg : null);

            return $arg === '' ? 'Укажите имя или @username: /кто <имя или @username>' : $this->whoReply($arg);
        }

        $arg = $this->argAfter($text, self::COMMAND_DEBTORS);

        $group = null;
        if ($arg !== '') {
            $group = Group::query()->where('name', 'like', '%'.$arg.'%')->first();
            if (! $group) {
                $this->logUsage($curator, 'dolgi', $arg);

                return "Группа «{$arg}» не найдена.";
            }
        }

        $reply = $this->summaryFor($group);
        $this->logUsage($curator, 'dolgi', $group?->name);

        return $reply;
    }

    private function summaryFor(?Group $group): string
    {
        $report = app(DebtorsReport::class);
        $query = $report->query();

        if ($group !== null) {
            $courseIds = $group->courses()->pluck('courses.id')->all();
            $userIds = $group->users()->pluck('users.id')->all();

            if (empty($courseIds) || empty($userIds)) {
                return "У группы «{$group->name}» должников нет 🎉";
            }

            $query->whereIn('d.course_id', $courseIds)->whereIn('users.id', $userIds);
        }

        $rows = (clone $query)->limit(5000)->get();
        if ($rows->isEmpty()) {
            return $group
                ? "У группы «{$group->name}» должников нет 🎉"
                : 'Должников нет 🎉';
        }

        $userIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->unique()->all();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy(fn (User $u) => (int) $u->id);

        Debtors::preloadPairCaches($rows);
        $report->preloadDepositCredits($rows);

        /** @var array<int, array{name: string, amount: float}> $byUser */
        $byUser = [];
        $years = $report->years();

        foreach ($rows as $row) {
            $userId = (int) $row->id;
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }

            $blocks = Debtors::debtBlocks($userId, (int) $row->course_id, (int) $row->ref_block_number, $years);
            $info = $report->computeDebtAmount($user, (int) $row->course_id, $blocks);
            $amount = (float) ($info['amount'] ?? 0);

            if (! isset($byUser[$userId])) {
                $byUser[$userId] = ['name' => $user->name ?: $user->email, 'amount' => 0.0];
            }
            $byUser[$userId]['amount'] += $amount;
        }

        $totalAmount = array_sum(array_column($byUser, 'amount'));
        $debtorsCount = count($byUser);

        usort($byUser, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);
        $top = array_slice($byUser, 0, self::TOP_LIMIT);

        $lines = [];
        $lines[] = $group ? "📋 Долги — группа «{$group->name}»" : '📋 Долги — все группы';
        $lines[] = "Должников: {$debtorsCount}";
        $lines[] = 'Сумма: '.number_format($totalAmount, 0, '.', ' ').' ₽';
        $lines[] = '';
        $lines[] = 'Топ-'.count($top).':';
        foreach ($top as $i => $entry) {
            $n = $i + 1;
            $amount = number_format($entry['amount'], 0, '.', ' ').' ₽';
            $lines[] = "{$n}. {$entry['name']} — {$amount}";
        }
        $lines[] = '';
        $lines[] = 'Подробнее: '.config('app.url').'/admin/debtors';

        return implode("\n", $lines);
    }

    /** Список активных/набираемых групп (архивные не засоряют ответ) — без аргумента `/группа`. */
    private function groupsOverview(): string
    {
        $groups = Group::query()
            ->where('status', '!=', 'archived')
            ->withCount('activeUsers as active_count')
            ->orderBy('name')
            ->limit(self::ROSTER_LIMIT)
            ->get();

        if ($groups->isEmpty()) {
            return 'Активных групп нет.';
        }

        $lines = ['📋 Группы:', ''];
        foreach ($groups as $group) {
            $lines[] = self::statusEmoji($group->status)." {$group->name} — {$group->active_count} чел.";
        }
        $lines[] = '';
        $lines[] = 'Подробнее: /группа <название>';

        return implode("\n", $lines);
    }

    /** Ростер одной группы: статус, курс(ы)/преподаватель, активный состав с цвет-статусом долга. */
    private function groupDetail(string $arg): string
    {
        $group = Group::query()->where('name', 'like', '%'.$arg.'%')->first();
        if (! $group) {
            return "Группа «{$arg}» не найдена.";
        }

        $group->loadMissing('courses.teacher');
        $students = $group->activeUsers()->orderBy('name')->get();

        $lines = [];
        $lines[] = self::statusEmoji($group->status)." Группа «{$group->name}» — {$group->statusLabel()}";

        $courseLine = $this->courseLine($group->courses);
        if ($courseLine !== '') {
            $lines[] = "Курс: {$courseLine}";
        }

        $lines[] = "Учеников: {$students->count()}";
        $lines[] = '';

        if ($students->isEmpty()) {
            $lines[] = 'Состав пуст.';

            return implode("\n", $lines);
        }

        $debtorIds = $this->debtorIdsFor($students->pluck('id')->all(), $group->courses->pluck('id')->all());

        foreach ($students->take(self::ROSTER_LIMIT) as $i => $student) {
            $n = $i + 1;
            $mark = in_array((int) $student->id, $debtorIds, true) ? '🔴' : '🟢';
            $lines[] = "{$n}. {$mark} {$student->name}";
        }

        if ($students->count() > self::ROSTER_LIMIT) {
            $lines[] = '… и ещё '.($students->count() - self::ROSTER_LIMIT);
        }

        return implode("\n", $lines);
    }

    /** Поиск студента по @username (точно) либо по имени (подстрока). */
    private function whoReply(string $arg): string
    {
        $needle = ltrim(trim($arg), '@');
        if ($needle === '') {
            return 'Укажите имя или @username: /кто <имя или @username>';
        }

        $exact = User::query()->where('telegram_username', $needle)->first();
        $matches = $exact
            ? collect([$exact])
            : User::query()->where('name', 'like', '%'.$needle.'%')->orderBy('name')->limit(self::TOP_LIMIT + 1)->get();

        if ($matches->isEmpty()) {
            return "Студент «{$arg}» не найден.";
        }

        if ($matches->count() > 1) {
            $lines = ['Найдено несколько — уточните имя:', ''];
            foreach ($matches->take(self::TOP_LIMIT) as $match) {
                $lines[] = "• {$match->name}";
            }

            return implode("\n", $lines);
        }

        return $this->studentDetail($matches->first());
    }

    private function studentDetail(User $student): string
    {
        $groups = $student->activeGroups()->with('courses.teacher')->get();

        $lines = ["👤 {$student->name}"];
        if ($student->email) {
            $lines[] = "E-mail: {$student->email}";
        }
        $lines[] = '';

        if ($groups->isEmpty()) {
            $lines[] = 'Активных групп нет.';

            return implode("\n", $lines);
        }

        foreach ($groups as $group) {
            $courseLine = $this->courseLine($group->courses);
            $lines[] = self::statusEmoji($group->status)." {$group->name}".($courseLine !== '' ? " — {$courseLine}" : '');
        }

        $courseIds = $groups->flatMap(fn (Group $g) => $g->courses->pluck('id'))->unique()->all();
        $isDebtor = ! empty($this->debtorIdsFor([(int) $student->id], $courseIds));

        $lines[] = '';
        $lines[] = $isDebtor ? '🔴 Есть задолженность' : '🟢 Задолженности нет';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private function courseLine($courses): string
    {
        return $courses->map(function (Course $course) {
            $teacher = $course->teacher?->name;

            return $teacher ? "{$course->title} (преп. {$teacher})" : $course->title;
        })->implode(', ');
    }

    private static function statusEmoji(?string $status): string
    {
        return match ($status) {
            'forming' => '🟡',
            'active' => '🟢',
            'archived' => '⚪',
            default => '⚪',
        };
    }

    /**
     * Пользователи из $userIds, у которых есть незакрытый долг по курсам $courseIds
     * (реюз DebtorsReport::query() как в /долги — без пересчёта суммы, только факт).
     *
     * @param  array<int>  $userIds
     * @param  array<int>  $courseIds
     * @return array<int>
     */
    private function debtorIdsFor(array $userIds, array $courseIds): array
    {
        if (empty($userIds) || empty($courseIds)) {
            return [];
        }

        $report = app(DebtorsReport::class);

        return $report->query()
            ->whereIn('d.course_id', $courseIds)
            ->whereIn('users.id', $userIds)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
    }

    private function logUsage(User $curator, string $command, ?string $group): void
    {
        ActivityEvent::create([
            'user_id' => $curator->id,
            'event_type' => ActivityEvent::TYPE_CURATOR_BOT_COMMAND,
            'event_data' => ['command' => $command, 'group' => $group],
            'created_at' => now(),
        ]);
    }
}
