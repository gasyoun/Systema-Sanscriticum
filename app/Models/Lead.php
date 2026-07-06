<?php

namespace App\Models;

use App\Models\Concerns\TracksBlame;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;
    use TracksBlame;

    /** Статусы воронки лида (единый источник для формы/колонки/фильтра). */
    public const STATUSES = [
        'new' => 'Новый',
        'in_work' => 'В работе',
        'qualified' => 'Квалифицирован',
        'converted' => 'Конверсия',
        'rejected' => 'Отказ',
    ];

    protected $fillable = [
        // Основные данные
        'landing_page_id',
        'name',
        'contact',
        'email',            // <--- Важно: Добавили Email
        'social',
        'is_promo_agreed',
        'converted_at',

        // Воронка (статус, ответственный, дата следующего контакта)
        'status',
        'assigned_to',
        'next_contact_at',

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
        'next_contact_at' => 'date',
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

    // Ответственный за лид менеджер.
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // История общения: заметки + автолог писем/сообщений.
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    // Аудит-таймлайн: кто/что/когда правил заявку (append-only, LeadAuditObserver).
    public function audits(): HasMany
    {
        return $this->hasMany(LeadAudit::class)->latest();
    }

    public function markConverted(): void
    {
        if (is_null($this->converted_at)) {
            // status переводим в «Конверсия» только если он ещё не финальный
            // (менеджер мог вручную поставить «Отказ» — не перетираем).
            $attrs = ['converted_at' => now()];
            if (! in_array($this->status, ['converted', 'rejected'], true)) {
                $attrs['status'] = 'converted';
            }
            $this->updateQuietly($attrs);
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
