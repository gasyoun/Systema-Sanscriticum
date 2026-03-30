<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Mail\StudentWelcomeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'amount',
        'tariff', 
        'status',
        'transaction_id',
        // --- НОВЫЕ ПОЛЯ: Для поблочной оплаты ---
        'start_block',
        'end_block',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // ==========================================
    // АВТОМАТИЗАЦИЯ ПРИ СОЗДАНИИ ИЛИ ИЗМЕНЕНИИ
    // ==========================================
    protected static function booted()
    {
        // 1. Срабатывает при СОЗДАНИИ нового платежа
        static::created(function (Payment $payment) {
            // Ловим и 'success', и 'paid' (в зависимости от того, как сохраняет админка)
            if (in_array($payment->status, ['success', 'paid'])) {
                $payment->processSuccessfulPayment();
            }
        });

        // 2. Срабатывает при ИЗМЕНЕНИИ существующего платежа
        static::updated(function (Payment $payment) {
            if ($payment->isDirty('status') && in_array($payment->status, ['success', 'paid'])) {
                $payment->processSuccessfulPayment();
            }
        });
    }

    // ==========================================
    // ГЛАВНЫЙ МЕТОД: ЗАПУСКАЕТ ВСЕ ПРОЦЕССЫ
    // ==========================================
    public function processSuccessfulPayment()
    {
        $this->grantAccess();
        $this->sendWelcomeEmailIfNeeded();
        
        // --- ОТПРАВКА В TELEGRAM ---
        if ($this->user) {
            // Пытаемся достать название курса (если связь настроена)
            $courseName = $this->course->title ?? 'Обучающий материал';
            $url = url('/login'); // Ссылка на вход
            
            $text = "🎉 <b>Оплата успешно получена!</b>\n\n";
            $text .= "Намасте! Ваш доступ к курсу <b>«{$courseName}»</b> открыт.\n\n";
            $text .= "Можете приступать к занятиям прямо сейчас:\n";
            $text .= "<a href='{$url}'>Перейти в личный кабинет</a>";

            $this->user->sendTelegramMessage($text);
        }
    }

    // ==========================================
    // ЛОГИКА ВЫДАЧИ ДОСТУПА И ГРУПП
    // ==========================================
    public function grantAccess()
    {
        $user = $this->user;
        $course = $this->course; // Берем курс из этого платежа

        if (!$course) return; // Защита от ошибки, если курс пустой

        // 1. Ищем группу, название которой совпадает с названием курса.
        // Оставили только колонку 'name', так как 'title' в таблице групп нет
        $group = \App\Models\Group::where('name', $course->title)->first();

        // 2. Если такая группа найдена в базе — добавляем в нее студента
        if ($group) {
            $user->groups()->syncWithoutDetaching([$group->id]);
        } else {
            // Пишем в лог сервера, если админ забыл создать группу для курса
            \Illuminate\Support\Facades\Log::warning("Внимание: Не найдена группа для курса: " . $course->title);
        }
    }

    // ==========================================
    // ГЕНЕРАЦИЯ ПАРОЛЯ И ОТПРАВКА ПИСЬМА
    // ==========================================
    public function sendWelcomeEmailIfNeeded()
    {
        $student = $this->user;

        if (!$student) {
            \Illuminate\Support\Facades\Log::error('Студент не найден для платежа ID: ' . $this->id);
            return;
        }

        // Считаем успешные оплаты
        $paymentsCount = $student->payments()->whereIn('status', ['success', 'paid'])->count();

        // Пишем в лог, сколько оплат нашла система
        \Illuminate\Support\Facades\Log::info("Попытка отправки письма. Студент: {$student->email}. Найдено успешных оплат: {$paymentsCount}");

        // Если это первая оплата
        if ($paymentsCount === 1) {
            \Illuminate\Support\Facades\Log::info("Генерируем пароль и отправляем письмо студенту: {$student->email}");
            
            $newPassword = \Illuminate\Support\Str::random(8); 
            $student->password = \Illuminate\Support\Facades\Hash::make($newPassword);
            $student->save();

            \Illuminate\Support\Facades\Mail::to($student->email)->send(new \App\Mail\StudentWelcomeMail($student, $newPassword));
            
            \Illuminate\Support\Facades\Log::info("Письмо успешно передано в почтовик!");
        } else {
            \Illuminate\Support\Facades\Log::warning("Письмо НЕ отправлено, так как это не первая оплата (счетчик: {$paymentsCount})");
        }
    }
}