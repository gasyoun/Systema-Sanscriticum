<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\GreetingName;
use App\Support\GreetingNameDictionary;
use Illuminate\Console\Command;

/**
 * Только чтение: как уведомления поздороваются со студентами после перехода
 * на {@see GreetingName}. Печатает спорные случаи — где автомат выбрал НЕ
 * первое слово или сдался до «Друг». По выводу пополняется
 * {@see GreetingNameDictionary} или куратор ставит
 * «Имя для обращения» в карточке. Ничего не пишет и никому не шлет.
 */
class PreviewGreetingNames extends Command
{
    protected $signature = 'users:greeting-names-preview {--limit=300 : Сколько спорных строк напечатать} {--all : Печатать и бесспорные}';

    protected $description = 'Показать, как уведомления обратятся к студентам (только имя). Только чтение.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $all = (bool) $this->option('all');
        $total = 0;
        $manual = 0;
        $doubtful = 0;
        $rows = [];

        User::query()
            ->select(['id', 'name', 'greeting_name'])
            ->orderBy('id')
            ->chunkById(1000, function ($users) use (&$total, &$manual, &$doubtful, &$rows, $limit, $all): void {
                foreach ($users as $user) {
                    $total++;
                    if (filled($user->greeting_name)) {
                        $manual++;

                        continue;
                    }

                    $name = trim((string) $user->name);
                    $greeting = GreetingName::of($name);
                    preg_match('/\p{L}+(?:-\p{L}+)*/u', $name, $m);
                    $first = isset($m[0]) ? GreetingName::of($m[0]) : 'Друг';
                    $isDoubtful = $greeting !== $first || $greeting === 'Друг';

                    if ($isDoubtful) {
                        $doubtful++;
                    }
                    if (($isDoubtful || $all) && count($rows) < $limit) {
                        $rows[] = [$user->id, $name, $greeting];
                    }
                }
            });

        $this->table(['id', 'Имя в карточке', 'Обращение'], $rows);
        $this->info(sprintf(
            'Всего: %d · ручное «Имя для обращения»: %d · спорных (не первое слово или «Друг»): %d',
            $total, $manual, $doubtful,
        ));

        return self::SUCCESS;
    }
}
