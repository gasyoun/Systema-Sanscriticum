<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\User;
use Illuminate\Console\Command;

class DebugPaymentSkips extends Command
{
    protected $signature = 'debug:payment-skips {--file=payments.csv}';
    protected $description = 'Показывает, какие строки payments.csv не мапятся на users/courses';

    public function handle(): int
    {
        $path = storage_path('app/imports/' . $this->option('file'));
        
        if (!file_exists($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        // Воспроизводим ТУ ЖЕ логику кэширования, что в ImportAcademyData
        $users   = User::pluck('id', 'name')->toArray();
        $courses = Course::pluck('id', 'title')->toArray();

        $this->info("Загружено в кэш: users={$this->count($users)}, courses={$this->count($courses)}");

        $cleanNumber = static function (string $value): float {
            $value = str_replace(',', '.', trim($value));
            $value = preg_replace('/[^\d.-]/', '', $value);
            return $value === '' ? 0 : (float) $value;
        };

        $file = fopen($path, 'r');
        $firstRow = true;
        $lineNum = 0;
        $skipped = [];

        while (($row = fgetcsv($file, 10000, ',')) !== false) {
            $lineNum++;
            if ($firstRow) { $firstRow = false; continue; }

            $studentName = trim($row[1] ?? '');
            $courseTitle = trim($row[2] ?? '');
            $amount      = $cleanNumber($row[5] ?? '0');

            // Пустые строки и расходы скрипт и так пропустит корректно
            if (empty($studentName) || empty($courseTitle)) continue;
            if ($amount < 0) continue;

            $userExists   = isset($users[$studentName]);
            $courseExists = isset($courses[$courseTitle]);

            if (!$userExists || !$courseExists) {
                $skipped[] = [
                    'line'    => $lineNum,
                    'student' => $studentName,
                    'course'  => $courseTitle,
                    'amount'  => $amount,
                    'reason'  => match (true) {
                        !$userExists && !$courseExists => 'нет студента И курса',
                        !$userExists                   => 'нет студента',
                        default                        => 'нет курса',
                    },
                ];
            }
        }
        fclose($file);

        if (empty($skipped)) {
            $this->info('✅ Пропусков не найдено.');
            return self::SUCCESS;
        }

        $this->warn("Найдено пропусков: " . count($skipped));
        $this->table(
            ['Строка', 'Студент', 'Курс', 'Сумма', 'Причина'],
            array_map(fn($s) => [$s['line'], $s['student'], $s['course'], $s['amount'], $s['reason']], $skipped)
        );

        return self::SUCCESS;
    }

    private function count(array $arr): int
    {
        return count($arr);
    }
}