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
        'social',
        'is_promo_agreed',
        'converted_at',

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
        'source_article_slug',

        // Lead-magnet — токен и канал доставки файла
        'magnet_token',
        'magnet_channel',
        'magnet_delivered_at',
        'telegram_chat_id',
        'vk_user_id',
        'max_user_id',
    ];

    protected $casts = [
        'magnet_delivered_at' => 'datetime',
        'is_promo_agreed' => 'boolean',
        'converted_at' => 'datetime',
    ];

    // Связь с лендингом (чтобы в админке видеть, откуда пришла заявка)
    public function landingPage()
    {
        return $this->belongsTo(LandingPage::class);
    }

    // Пошаговые письма, отправленные лиду (идемпотентность LeadStepMailer).
    public function stepEmails()
    {
        return $this->hasMany(LeadStepEmail::class);
    }

    public function markConverted(): void
    {
        if (is_null($this->converted_at)) {
            $this->updateQuietly(['converted_at' => now()]);
        }
    }

    /**
     * Количество лидов за период с необязательными фильтрами по источнику/лендингу.
     * Период включительно: с начала дня $start по конец дня $end.
     */
    public static function countForPeriod($start, $end, ?string $utmSource = null, $landingPageId = null): int
    {
        $start = \Illuminate\Support\Carbon::parse($start)->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($end)->endOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        return static::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($utmSource, fn ($q, $v) => $q->where('utm_source', $v))
            ->when($landingPageId, fn ($q, $v) => $q->where('landing_page_id', $v))
            ->count();
    }
}
