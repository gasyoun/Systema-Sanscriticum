<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Mail\StudentWelcomeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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
    \Illuminate\Support\Facades\DB::transaction(function () {
        $this->grantAccess();
        $this->sendWelcomeEmailIfNeeded();
    });

    // Telegram-уведомление — вне транзакции (не критично если не отправится)
    if ($this->user) {
        $courseName = $this->course->title ?? 'Обучающий материал';
        $url = url('/login');

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
    public function grantAccess(): void
{
    $user = $this->user;
    $course = $this->course;

    if (!$course) {
        Log::warning("grantAccess: платёж #{$this->id} без курса, пропускаем.");
        return;
    }

    if (!$user) {
        Log::warning("grantAccess: платёж #{$this->id} без пользователя, пропускаем.");
        return;
    }

    // Все группы, привязанные к этому курсу через pivot course_group
    $groupIds = $course->groups()->pluck('groups.id')->toArray();

    if (empty($groupIds)) {
        Log::warning(
            "grantAccess: у курса '{$course->title}' (id={$course->id}) " .
            "нет привязанных групп. Проверьте вкладку «Группы» в админке курса."
        );
        return;
    }

    // syncWithoutDetaching — добавляет новые связи, не удаляя существующие
    $user->groups()->syncWithoutDetaching($groupIds);

    Log::info(
        "grantAccess: студент #{$user->id} ({$user->email}) добавлен в " .
        count($groupIds) . " групп(у/ы) курса '{$course->title}'."
    );
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