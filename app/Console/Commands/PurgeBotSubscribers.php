<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Чистка мусорных «Подписчиков» рассылки (H324), нагенерённых спам-ботами через
 * форму /subscribe до включения анти-бот защиты.
 *
 * Кандидат на удаление — беспарольный подписчик, который:
 *  - имеет дефолтное имя «Подписчик» (никогда не переименован);
 *  - реально подписан (newsletter_subscribed_at задан);
 *  - НИ РАЗУ не входил в кабинет (login_count = 0 → magic-ссылка не открывалась);
 *  - не покупатель (нет payments) и не в группах;
 *  - не админ.
 *
 * По умолчанию — dry-run (только показать список). Реальное удаление — с --force.
 * Опция --keep-days=N исключает свежие подписки (< N дней), чтобы не задеть
 * настоящего человека, который подписался только что и ещё не кликнул ссылку.
 *
 * magic_link_tokens удаляются каскадом (FK cascadeOnDelete). Lead-строки (CRM/UTM)
 * не имеют FK на user — чистим их отдельно по email.
 */
class PurgeBotSubscribers extends Command
{
    protected $signature = 'newsletter:purge-bots
        {--force : Реально удалить (без флага — только показать, dry-run)}
        {--keep-days=0 : Не трогать подписки моложе N дней}';

    protected $description = 'Удаляет бот-«Подписчиков» рассылки (не входивших, без оплат/групп) и их Lead-строки. По умолчанию dry-run.';

    public function handle(): int
    {
        $keepDays = max(0, (int) $this->option('keep-days'));

        $users = $this->query($keepDays)->get(['id', 'email', 'newsletter_subscribed_at']);

        if ($users->isEmpty()) {
            $this->info('Бот-подписчиков под критерии не найдено.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'Подписан'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->email,
                optional($u->newsletter_subscribed_at)->format('d.m.Y H:i'),
            ])->all(),
        );

        $this->line('Найдено кандидатов: <comment>'.$users->count().'</comment>'
            .($keepDays > 0 ? " (исключены подписки моложе {$keepDays} дн.)" : ''));

        if (! $this->option('force')) {
            $this->warn('Это dry-run. Ничего не удалено. Запустите с --force для удаления.');

            return self::SUCCESS;
        }

        $emails = $users->pluck('email')->filter()->values();

        $deletedUsers = 0;
        $deletedLeads = 0;

        DB::transaction(function () use ($users, $emails, &$deletedUsers, &$deletedLeads) {
            // Lead-строки чистим по email (нет FK на user).
            $deletedLeads = Lead::whereIn('email', $emails)->delete();

            // Пользователи — по id; magic_link_tokens уходят каскадом.
            $deletedUsers = User::whereIn('id', $users->pluck('id'))->delete();
        });

        $this->info("Удалено пользователей: {$deletedUsers}, Lead-строк: {$deletedLeads}.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<User>
     */
    private function query(int $keepDays): Builder
    {
        return User::query()
            ->where('name', 'Подписчик')
            ->whereNotNull('newsletter_subscribed_at')
            ->where('login_count', 0)
            ->where('is_admin', false)
            ->whereDoesntHave('payments')
            ->whereDoesntHave('groups')
            ->when($keepDays > 0, fn (Builder $q) => $q->where('newsletter_subscribed_at', '<', now()->subDays($keepDays)))
            ->orderBy('id');
    }
}
