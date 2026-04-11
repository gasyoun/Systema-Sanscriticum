<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'telegram', 'vk', 'requisites', 'bio'
    ];

    // Один преподаватель может вести много курсов
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
    
    // ==========================================
    // АВТОМАТИЧЕСКИЙ РАСЧЕТ ЗАРПЛАТЫ ПРЕПОДАВАТЕЛЯ
    // ==========================================
    // Связь: История выплат преподавателю
    public function payouts()
    {
        return $this->hasMany(TeacherPayout::class);
    }

    // Умный расчет: если передать даты, посчитает за этот период. Если нет - за всё время.
    // Умный расчет: если передать даты, посчитает за этот период. Если нет - за всё время.
    public function calculateEarnings($startDate = null, $endDate = null): float
    {
        $total = 0;

        foreach ($this->courses as $course) {
            // Пропускаем технические курсы
            if ($course->slug === 'system-expenses' || $course->title === 'Прочие затраты (Технический)') {
                continue;
            }

            // Базовый запрос: только успешные оплаты
            $query = $course->payments()
                ->whereIn('status', ['success', 'paid'])
                ->where('amount', '>', 0); 
            
            if ($startDate && $endDate) {
                $query->whereBetween('payments.created_at', [$startDate, $endDate]);
            }
            
            // Выгружаем оплаты
            $payments = $query->get();

            if ($payments->isEmpty()) {
                continue;
            }

            // 1. КЛАССИЧЕСКИЕ МЕТРИКИ
            $paymentsSum = $payments->sum('amount'); 
            $uniqueStudentsCount = $payments->unique('user_id')->count();

            // ==========================================
            // 2. ДИНАМИЧЕСКИЙ ПОДСЧЕТ БЛОКОВ
            // ==========================================
            // Ищем максимальный номер блока среди тарифов этого курса
            $maxBlockNumber = \App\Models\Tariff::where('course_id', $course->id)
                ->whereNotNull('block_number')
                ->where('block_number', '>', 0)
                ->max('block_number');
            
            // Если блоков нет (например, обычный курс), делим на 1, чтобы не было ошибки деления на ноль
            $totalBlocksInCourse = $maxBlockNumber ?: 1; 
            
            $blockRevenue = 0;

            foreach ($payments as $payment) {
                // Используем поле tariff из таблицы payments
                if ($payment->tariff === 'full') { 
                    // Если купили курс целиком, берем долю за 1 блок
                    $blockRevenue += ($payment->amount / $totalBlocksInCourse);
                } else {
                    // Если купили конкретный блок (tariff = 'block_1', 'block_2' и т.д.)
                    $blockRevenue += $payment->amount;
                }
            }

            // 3. НАЧИСЛЯЕМ ЗАРПЛАТУ
            switch ($course->salary_type) {
                case 'percent':
                    // % от всей выручки курса
                    $total += $paymentsSum * ($course->salary_value / 100);
                    break;

                case 'fix_per_student':
                    // Фикс за каждого уникального студента на всем курсе
                    $total += $uniqueStudentsCount * $course->salary_value;
                    break;

                case 'fix_total':
                    // Единоразовый фикс за ведение курса
                    $total += $course->salary_value;
                    break;

                case 'percent_per_block':
                    // % от "чистой" доли блока (розница + часть от опта)
                    $total += $blockRevenue * ($course->salary_value / 100);
                    break;

                case 'fix_per_block':
                    // ИСПРАВЛЕНО: Фикс за каждый блок в курсе
                    // Умножаем ставку на общее количество блоков в этом курсе
                    $total += $totalBlocksInCourse * $course->salary_value;
                    break;
            }
        }

        return round($total, 2);
    }
}