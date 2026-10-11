<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Кураторская возможность снести ВСЕ голоса ждуна одного человека по всем
 * курсам (кейс MG 11-10-2026: участница попросила убрать её голоса). Поиск
 * юзера по id / email / telegram_id / точному имени; перед удалением
 * показывает, что именно будет снесено. Счётчики ждуна вычисляемые
 * (votesCount() = votes()->count()), пересчитывать нечего — достаточно
 * сбросить кэш публичного фида, как делает castVoteBy/unvote.
 */
final class WaitlistForgetVotesCommand extends Command
{
    protected $signature = 'waitlist:forget-votes
        {user : id, email, telegram_id или точное имя юзера}
        {--force : удалить без интерактивного подтверждения}';

    protected $description = 'Удалить все голоса ждуна одного человека (по всем курсам)';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('user'));

        $user = User::query()
            ->whereKey($needle)
            ->orWhere('email', $needle)
            ->orWhere('name', $needle)
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('telegram_id', (int) $needle))
            ->get(['id', 'name', 'email', 'telegram_id'])
            ->first(function (User $candidate) use ($needle): bool {
                return (string) $candidate->getKey() === $needle
                    || $candidate->email === $needle
                    || (ctype_digit($needle) && (string) $candidate->telegram_id === $needle)
                    || $candidate->name === $needle;
            });

        if ($user === null) {
            $this->error('Юзер не найден: '.$needle);
            User::query()->where('name', 'like', '%'.$needle.'%')->limit(5)
                ->pluck('name')
                ->each(fn (string $name) => $this->line('  похожий: '.$name));

            return self::FAILURE;
        }

        $votes = WaitlistVote::query()
            ->where('user_id', $user->getKey())
            ->with('item:id,course_title')
            ->orderBy('id')
            ->get();

        if ($votes->isEmpty()) {
            $this->info('У «'.$user->name.'» (id '.$user->id.') нет голосов ждуна — удалять нечего.');

            return self::SUCCESS;
        }

        $this->table(
            ['vote id', 'курс', 'слот', 'голос отдан'],
            $votes->map(fn (WaitlistVote $vote): array => [
                $vote->getKey(),
                $vote->item?->course_title ?? '?',
                $vote->slot_preference !== null
                    ? (WaitlistVote::SLOT_PREFERENCES[$vote->slot_preference] ?? $vote->slot_preference)
                    : 'любое время',
                (string) $vote->created_at,
            ]),
        );

        if (! $this->option('force') && ! $this->confirm('Удалить все голоса («'.$user->name.'», id '.$user->id.')?')) {
            $this->line('Отменено — ничего не удалено.');

            return self::SUCCESS;
        }

        WaitlistVote::query()->where('user_id', $user->getKey())->delete();
        Cache::forget('public_waitlist:v1');

        $this->info('Удалено голосов: '.$votes->count().' — «'.$user->name.'» (id '.$user->id.'), курсы: '
            .$votes->pluck('item.course_title')->implode(', ').'.');

        return self::SUCCESS;
    }
}
