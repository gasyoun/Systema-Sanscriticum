<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Segment;
use Illuminate\Database\Seeder;

/**
 * The three GC-A1 built-in segments (H1637), wrapping ReactivationReport /
 * DebtorsReport / StuckStudentsReport 1:1. Idempotent by `builtin_key` — same
 * pattern as MessageTemplateSeeder (firstOrCreate, no duplicate rows across
 * repeat runs).
 */
class SegmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->builtins() as $def) {
            Segment::query()->firstOrCreate(
                ['builtin_key' => $def['builtin_key']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'criteria' => null,
                    'is_builtin' => true,
                ],
            );
        }
    }

    /** @return list<array{builtin_key:string, name:string, description:string}> */
    private function builtins(): array
    {
        return [
            [
                'builtin_key' => Segment::BUILTIN_REACTIVATION,
                'name' => 'Реактивация',
                'description' => 'Кандидаты на реактивацию «уснувшей» базы — обёртка над ReactivationReport (тот же сегмент, что и DebtorsReport, с подсказкой шаблона).',
            ],
            [
                'builtin_key' => Segment::BUILTIN_DEBTORS,
                'name' => 'Должники',
                'description' => 'Не продлившие оплату / без оплат по потоку — обёртка над DebtorsReport.',
            ],
            [
                'builtin_key' => Segment::BUILTIN_STUCK_STUDENTS,
                'name' => 'Застрявшие студенты',
                'description' => 'Неактивен 14+ дней либо ДЗ на доработке без пересдачи 7+ дней — обёртка над StuckStudentsReport.',
            ],
        ];
    }
}
