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
    public function calculateEarnings($startDate = null, $endDate = null): float
    {
        $total = 0;

        foreach ($this->courses as $course) {
            // Базовый запрос: только успешные оплаты
            $query = $course->payments()->whereIn('status', ['success', 'paid']);
            
            // Если попросили посчитать за конкретный месяц - фильтруем по дате
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
            
            $paymentsCount = $query->count(); 
            $paymentsSum = $query->sum('amount'); 

            if ($course->salary_type === 'percent') {
                $total += $paymentsSum * ($course->salary_value / 100);
            } elseif ($course->salary_type === 'fix_per_student') {
                $total += $paymentsCount * $course->salary_value;
            } elseif ($course->salary_type === 'fix_total') {
                if ($paymentsCount > 0) {
                    $total += $course->salary_value;
                }
            }
        }

        return round($total, 2);
    }
}