<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Дневное доказательство успешного скана инжестера (H5768).
 *
 * Одна строка = аккаунт × день (Europe/Moscow) с хотя бы одним успешным
 * live-сканом telegram-support:sync. Это единственный авторитетный источник
 * полноты для недельной аналитики: день без строки — неизвестный охват.
 */
class TelegramSupportScanDay extends Model
{
    protected $fillable = [
        'account_name',
        'day',
        'successful_runs',
        'peers_polled_max',
        'first_success_at',
        'last_success_at',
    ];

    protected $casts = [
        'successful_runs' => 'integer',
        'peers_polled_max' => 'integer',
        'first_success_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];
}
