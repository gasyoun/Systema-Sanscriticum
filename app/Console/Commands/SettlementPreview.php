<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MutualSettlement;
use App\Models\User;
use App\Services\MutualSettlementService;
use Illuminate\Console\Command;

/**
 * Разовая сверка взаимозачёта (H1730, шаг B2).
 *
 * Инструмент ПЕРВОЙ сверки на проде, когда появится read-only доступ к базе:
 * печатает обе цифры с расшифровкой и разницу.
 *
 * ТОЛЬКО ЧТЕНИЕ. Команда не создаёт и не меняет ни одной строки — ни акта, ни
 * выплаты. Фиксация делается осознанно человеком со страницы «Взаимозачёт»,
 * а не побочным эффектом отчёта.
 */
class SettlementPreview extends Command
{
    protected $signature = 'settlement:preview
        {user : id или email пользователя (он же преподаватель)}
        {--course= : id курса, на блок которого фиксируются условия}
        {--block= : номер блока курса}
        {--from= : начало периода (Y-m-d); по умолчанию — даты блока}
        {--to= : конец периода (Y-m-d)}
        {--json : машиночитаемый вывод вместо таблиц}';

    protected $description = 'Сверка взаимозачёта преподаватель-ученик: обе суммы, расшифровка и разница (только чтение)';

    public function handle(MutualSettlementService $service): int
    {
        // Кириллица в консоли Windows: без переключения кодовой страницы вывод
        // уходит в cp866 и превращается в кашу. sapi_windows_cp_set — штатный
        // PHP-API, не shell-хак; на остальных ОС ветка просто не существует.
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $user = $this->resolveUser((string) $this->argument('user'));
        if (! $user instanceof User) {
            $this->error('Пользователь не найден: '.$this->argument('user'));

            return self::FAILURE;
        }

        try {
            $preview = $service->preview(
                $user,
                $this->option('course') !== null ? (int) $this->option('course') : null,
                $this->option('block') !== null ? (int) $this->option('block') : null,
                $this->option('from'),
                $this->option('to'),
            );
        } catch (\DomainException $e) {
            // Гард «один человек»: считать чужие деньги как свои — хуже, чем не посчитать.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderReport($user, $preview);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderReport(User $user, array $p): void
    {
        $period = ($p['period_from'] ?? '—').' — '.($p['period_to'] ?? '—');

        $this->newLine();
        $this->info('Взаимозачёт: '.$user->name.' (user #'.$user->id.' / teacher #'.$p['teacher_id'].')');
        $this->line('Период: '.$period
            .($p['course_id'] ? ' · курс #'.$p['course_id'] : '')
            .($p['block_number'] ? ' · блок '.$p['block_number'] : ''));
        $this->newLine();

        $this->line('<comment>Оплачено как ученик</comment>');
        if ($p['tuition']['lines'] === []) {
            $this->line('  (платежей за период нет)');
        } else {
            $this->table(
                ['Дата', 'Курс', 'Тариф', 'Сумма, ₽'],
                array_map(fn (array $l): array => [
                    $l['date'] ?? '—',
                    $l['course_title'],
                    $l['tariff'] ?? '—',
                    $this->money((float) $l['amount']),
                ], $p['tuition']['lines']),
            );
        }
        $this->line('  Итого оплачено: <info>'.$this->money((float) $p['tuition_amount']).' ₽</info>');
        $this->newLine();

        $this->line('<comment>Начислено как преподавателю</comment>');
        if ($p['salary']['lines'] === []) {
            $this->line('  (начислений за период нет)');
        } else {
            $this->table(
                ['Курс', 'Схема', 'Студентов', 'Выручка, ₽', 'Начислено, ₽'],
                array_map(fn (array $l): array => [
                    $l['course_title'],
                    (string) ($l['salary_type'] ?? '—'),
                    (string) $l['students'],
                    $this->money((float) $l['revenue']),
                    $this->money((float) $l['amount']),
                ], $p['salary']['lines']),
            );
        }
        $this->line('  Итого начислено: <info>'.$this->money((float) $p['salary_amount']).' ₽</info>');
        $this->newLine();

        $this->line('<comment>Зачёт</comment>');
        $this->line('  К зачёту (минимум из двух): <info>'.$this->money((float) $p['offset_amount']).' ₽</info>');
        $this->line('  Остаток: <info>'.$this->money((float) $p['net_amount']).' ₽</info> — '
            .(MutualSettlement::directionLabels()[$p['net_direction']] ?? $p['net_direction']));
        $this->newLine();
        $this->line('<comment>Ничего не записано</comment> — команда только читает. Фиксация акта — на странице «Взаимозачёт».');
        $this->newLine();
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', ' ');
    }

    private function resolveUser(string $key): ?User
    {
        return ctype_digit($key)
            ? User::find((int) $key)
            : User::where('email', $key)->first();
    }
}
