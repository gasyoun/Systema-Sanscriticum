<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H3084, шаг 12 — связка `users.teacher_id` с `teachers` по ФИО.
 *
 * Пока связки нет, «Взаимозачёт» и сверка выплат преподавателя его вообще не
 * видят: платёж-«Расход», заведённый на личного пользователя преподавателя,
 * из данных неотличим от аренды. Ворошилов (`teachers.id 14`) — ровно этот
 * случай: у пользователя 6760 `teacher_id` пуст.
 *
 * Совпадение по ФИО — тот самый случай, где авто ошибается на однофамильце,
 * поэтому:
 *
 *   - без `--apply` не пишется ничего;
 *   - связывается только пара, однозначная в ОБЕ стороны: один преподаватель —
 *     один пользователь с таким ФИО и наоборот. Любая неоднозначность попадает
 *     в отчёт словами «неоднозначно», а не тихим выбором первого;
 *   - заполненный `users.teacher_id` не перетирается никогда.
 *
 * ⚠️ Последствие для денег, которое надо знать заранее (H3084, риск задвоения):
 * как только пользователь связан с преподавателем, платежи-«Расходы» на этого
 * пользователя начинают попадать в `paid_out` сверки САМИ, источником
 * `payment_expense_direct`. Их не надо подтверждать в очереди атрибуции ещё
 * раз — детектор `salary:detect-payout-attributions` их и не предлагает.
 */
class LinkTeacherUsers extends Command
{
    protected $signature = 'salary:link-teacher-users
        {--apply : Записать users.teacher_id для однозначных пар}
        {--teacher= : Ограничить одним преподавателем (teachers.id)}';

    protected $description = 'Сопоставить пользователей и преподавателей по ФИО и (по --apply) проставить users.teacher_id';

    public function handle(): int
    {
        $teachers = Teacher::query()
            ->when($this->option('teacher'), fn ($q) => $q->whereKey((int) $this->option('teacher')))
            ->orderBy('id')
            ->get();

        if ($teachers->isEmpty()) {
            $this->warn('Преподавателей не найдено.');

            return self::SUCCESS;
        }

        // Индекс пользователей по нормализованному ФИО строится один раз:
        // база пользователей большая, а преподавателей два десятка.
        $usersByName = [];
        User::query()->select('id', 'name', 'teacher_id', 'email')->chunkById(2000, function ($chunk) use (&$usersByName) {
            foreach ($chunk as $user) {
                $key = $this->normalizeName((string) $user->name);
                if ($key === '') {
                    continue;
                }
                $usersByName[$key][] = $user;
            }
        });

        $rows = [];
        $toWrite = [];

        foreach ($teachers as $teacher) {
            $key = $this->normalizeName((string) $teacher->name);
            $candidates = $key === '' ? [] : ($usersByName[$key] ?? []);

            if ($candidates === []) {
                $rows[] = [$teacher->id, $this->short((string) $teacher->name), '—', 'пользователя с таким ФИО нет'];

                continue;
            }

            if (count($candidates) > 1) {
                $ids = implode(', ', array_map(fn ($u): string => '#'.$u->id, $candidates));
                $rows[] = [
                    $teacher->id,
                    $this->short((string) $teacher->name),
                    $ids,
                    'НЕОДНОЗНАЧНО: однофамильцы — связывать должен человек',
                ];

                continue;
            }

            $user = $candidates[0];

            if ($user->teacher_id !== null) {
                $verdict = (int) $user->teacher_id === (int) $teacher->id
                    ? 'уже связан'
                    : 'связан с ДРУГИМ преподавателем #'.$user->teacher_id.' — не трогаю';
                $rows[] = [$teacher->id, $this->short((string) $teacher->name), '#'.$user->id, $verdict];

                continue;
            }

            $toWrite[(int) $user->id] = (int) $teacher->id;

            $rows[] = [
                $teacher->id,
                $this->short((string) $teacher->name),
                '#'.$user->id.' '.$this->short((string) $user->name),
                $this->option('apply') ? 'связываю' : 'связал бы',
            ];
        }

        $this->table(['Препод', 'ФИО преподавателя', 'Пользователь', 'Что будет'], $rows);

        if ($toWrite !== []) {
            $this->line('');
            $this->warn(
                'Внимание: после связки платежи-«Расходы», заведённые на этих пользователей, '
                .'начнут попадать в «выплачено» сверки сами. Подтверждать их в очереди атрибуции не нужно.'
            );
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('Режим отчёта: в базу не записано ничего. Повторите с --apply.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($toWrite, &$written) {
            foreach ($toWrite as $userId => $teacherId) {
                // Пустоту перепроверяем в WHERE: гонка с ручной правкой в
                // админке не должна перетереть человеческое решение.
                $written += User::whereKey($userId)
                    ->whereNull('teacher_id')
                    ->update(['teacher_id' => $teacherId]);
            }
        });

        $this->info("Связок записано: {$written}.");

        return self::SUCCESS;
    }

    /**
     * ФИО к сравнимому виду: регистр, ё→е, схлопнутые пробелы, снятая
     * пунктуация. Порядок слов НЕ меняется — «Иванов Иван» и «Иван Иванов»
     * остаются разными: перестановка это уже догадка, а не нормализация.
     */
    private function normalizeName(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = str_replace(['ё', 'Ё'], 'е', $n);
        $n = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n);
        $n = (string) preg_replace('/\s+/u', ' ', $n);

        return trim($n);
    }

    private function short(string $value): string
    {
        return mb_strimwidth($value, 0, 40, '…');
    }
}
