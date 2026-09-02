<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Filament\Pages\Debtors;
use App\Models\ActivityEvent;
use App\Models\Group;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Support\Roles;

/**
 * Куратор-команда `/долги [группа]` поверх DebtorsReport (тикет S4
 * годового support-roadmap, H250) — сводка должников прямо в Telegram,
 * без захода в админку. Расчёт долга целиком делегирован DebtorsReport +
 * публичным хелперам Debtors::debtBlocks/preloadPairCaches — здесь
 * НИКАКОЙ денежной логики не дублируется, только форматирование ответа.
 */
class DebtorsBotCommand
{
    public const COMMAND_DEBTORS = '/долги';

    private const TOP_LIMIT = 5;

    private const HINT_LIMIT = 3;

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
        return $this->isDebtorsCommand($text);
    }

    public function isDebtorsCommand(string $text): bool
    {
        return $this->matchesPrefix($text, self::COMMAND_DEBTORS);
    }

    private function matchesPrefix(string $text, string $command): bool
    {
        $trimmed = trim($text);

        return $trimmed === $command || str_starts_with($trimmed, $command.' ');
    }

    /**
     * Диспетчер: строит текст ответа для авторизованного куратора и логирует
     * использование (для deflection-отчёта S2/S7). Вызывающий код обязан
     * сам проверить DebtorsBotCommand::isCurator() ДО вызова — здесь
     * авторизация не проверяется повторно.
     */
    public function reply(User $curator, string $text): string
    {
        $arg = trim(mb_substr(trim($text), mb_strlen(self::COMMAND_DEBTORS)));

        $group = null;
        if ($arg !== '') {
            $group = Group::query()->where('name', 'like', '%'.$arg.'%')->first();
            if (! $group) {
                $this->logUsage($curator, 'dolgi', $arg);

                return $this->unknownGroupReply($arg);
            }
        }

        $reply = $this->summaryFor($group);
        $this->logUsage($curator, 'dolgi', $group?->name);

        return $reply;
    }

    /** Группа не найдена — подсказываем ближайшие названия (H3912). */
    private function unknownGroupReply(string $arg): string
    {
        $reply = "Группа «{$arg}» не найдена.";

        $hints = $this->nearestGroupNames($arg);
        if ($hints !== []) {
            $reply .= "\nПохожие группы: ".implode(', ', array_map(fn (string $name) => "«{$name}»", $hints));
        }

        return $reply;
    }

    /**
     * До HINT_LIMIT самых похожих названий групп (similar_text, нижний порог
     * отсекает случайный шум). Групп в школе десятки — полный проход дёшев.
     *
     * @return array<int, string>
     */
    private function nearestGroupNames(string $arg): array
    {
        $needle = mb_strtolower(trim($arg));
        if ($needle === '') {
            return [];
        }

        $scored = [];
        foreach (Group::query()->orderBy('name')->pluck('name') as $name) {
            similar_text($needle, mb_strtolower((string) $name), $percent);
            if ($percent >= 40) {
                $scored[(string) $name] = $percent;
            }
        }
        arsort($scored);

        return array_slice(array_keys($scored), 0, self::HINT_LIMIT);
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
                $byUser[$userId] = ['name' => $user->name ?: $user->email, 'amount' => 0.0, 'overdue' => 0];
            }
            $byUser[$userId]['amount'] += $amount;

            // Максимальная просрочка по парам студента (H3912): даты блока берём
            // из referenceBlocks, daysOverdueFor возвращает 0 без даты/в будущем.
            $overdue = $report->daysOverdueFor((int) $row->course_id, (int) $row->ref_block_number);
            if ($overdue > $byUser[$userId]['overdue']) {
                $byUser[$userId]['overdue'] = $overdue;
            }
        }

        $totalAmount = array_sum(array_column($byUser, 'amount'));
        $debtorsCount = count($byUser);

        usort($byUser, function (array $a, array $b) {
            $byAmount = $b['amount'] <=> $a['amount'];
            if ($byAmount !== 0) {
                return $byAmount;
            }

            return $b['overdue'] <=> $a['overdue'];
        });
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
            $overdue = DebtorsReport::formatOverdue((int) $entry['overdue']);
            $lines[] = "{$n}. {$entry['name']} — {$amount}".($overdue !== '' ? ", {$overdue}" : '');
        }
        $lines[] = '';
        $lines[] = 'Подробнее: '.config('app.url').'/admin/debtors';

        return implode("\n", $lines);
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
