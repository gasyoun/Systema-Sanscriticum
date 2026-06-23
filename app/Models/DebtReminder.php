<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лог авто-напоминания должнику (команда debts:remind). Одна строка = одна
 * отправка по паре (студент, курс). Используется для анти-спама по cadence.
 */
class DebtReminder extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'block_number',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
