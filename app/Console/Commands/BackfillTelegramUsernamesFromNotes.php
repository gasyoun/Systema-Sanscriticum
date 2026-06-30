<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillTelegramUsernamesFromNotes extends Command
{
    protected $signature = 'telegram:backfill-usernames-from-notes
        {--apply : Реально записать users.telegram_username (без флага — сухой прогон)}
        {--limit=500 : Максимум пользователей за прогон}';

    protected $description = 'Одноразово перенести старые строки Telegram: @username из users.note в users.telegram_username';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'telegram_username') || ! Schema::hasColumn('users', 'note')) {
            $this->error('Нужны колонки users.telegram_username и users.note.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $query = User::query()
            ->whereNull('telegram_username')
            ->whereNotNull('note')
            ->where(function ($query): void {
                $query->where('note', 'like', '%Telegram:%')
                    ->orWhere('note', 'like', '%TG:%')
                    ->orWhere('note', 'like', '%Телеграм:%');
            });

        $total = (clone $query)->count();
        $users = $query->orderBy('id')->limit($limit)->get();

        $this->info('Кандидатов без telegram_username со старым Telegram в note: '.$total.'. В этом батче: '.$users->count().'.');

        $updated = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $username = $this->firstTelegramUsernameFromNote((string) $user->note);
            if ($username === null) {
                $skipped++;

                continue;
            }

            $this->line("  #{$user->id} {$user->email} -> @{$username}");

            if ($apply) {
                $user->forceFill(['telegram_username' => $username])->save();
            }

            $updated++;
        }

        $mode = $apply ? 'Записано' : 'Будет записано';
        $this->info("{$mode}: {$updated}. Пропущено: {$skipped}. Note оставлен без изменений.");

        if (! $apply) {
            $this->comment('Сухой прогон. Запустите с --apply, чтобы записать usernames.');
        }

        return self::SUCCESS;
    }

    private function firstTelegramUsernameFromNote(string $note): ?string
    {
        preg_match('/(?:Telegram|TG|Телеграм)\s*:\s*([^\r\n,;]+)/iu', $note, $match);

        return User::normalizeTelegramUsername($match[1] ?? null);
    }
}
