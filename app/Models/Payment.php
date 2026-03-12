<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id', // <--- Заменили landing_page_id на course_id
        'amount',
        'tariff', 
        'status',
        'transaction_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Новая связь: Платеж принадлежит Курсу
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // ==========================================
    // АВТОМАТИЗАЦИЯ ПРИ ИЗМЕНЕНИИ СТАТУСА
    // ==========================================
    protected static function booted()
    {
        static::updated(function (Payment $payment) {
            if ($payment->isDirty('status') && $payment->status === 'paid') {
                $payment->grantAccess();
            }
        });
    }

    // Логика выдачи доступа
    public function grantAccess()
    {
        $user = $this->user;
        
        // Здесь мы смотрим, какой тариф оплачен:
        if ($this->tariff === 'block_1') {
            // Логика: Добавляем студента в группу "Курс N - Блок 1"
            // $user->groups()->attach( ID_ГРУППЫ_ДЛЯ_БЛОКА_1 );
            
        } elseif ($this->tariff === 'full') {
            // Логика: Добавляем студента в основную группу всего курса
            // $user->groups()->attach( ID_ГРУППЫ_ПОЛНОГО_КУРСА );
        }

        // Отправка письма на почту студенту об успешной оплате (используем курс!)
        // Mail::to($user->email)->send(new CoursePaidMail($this->course));
    }
}