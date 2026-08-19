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
    /** Отправила авто-лестница `debts:remind`. */
    public const SOURCE_AUTO = 'auto';

    /** Отправил человек кнопкой «Напомнить» (H3156). */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'user_id',
        'course_id',
        'block_number',
        'sent_at',
        'source',
    ];

    protected $attributes = [
        'source' => self::SOURCE_AUTO,
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
