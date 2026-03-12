<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        // Основные данные
        'landing_page_id',
        'name',
        'contact',
        'email',            // <--- Важно: Добавили Email
        'is_promo_agreed',

        // Аналитика (UTM метки) - теперь они будут сохраняться
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'click_id',

        // Технические данные - теперь они будут сохраняться
        'ip_address',
        'user_agent',
        'referrer',
    ];

    // Связь с лендингом (чтобы в админке видеть, откуда пришла заявка)
    public function landingPage()
    {
        return $this->belongsTo(LandingPage::class);
    }
}