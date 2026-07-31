<?php

namespace App\Models;

use App\Models\Concerns\TracksBlame;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Lead extends Model
{
    use HasFactory;
    use TracksBlame;

    /**
     * Стадии воронки лида — данные из lead_stages (единый источник для
     * формы/колонки/фильтра), не hardcoded константы. См. GC-C1 / H451.
     *
     * @return array<string,string> key => label, отсортировано по sort_order
     */
    public static function statuses(): array
    {
        return LeadStage::query()->ordered()->pluck('label', 'key')->all();
    }

    /**
     * Финальные статусы воронки — их нельзя молча откатить в «Новый».
     *
     * @return list<string>
     */
    public static function finalStatuses(): array
    {
        return LeadStage::query()->where('is_final', true)->pluck('key')->all();
    }

    /**
     * Ключ первой стадии по sort_order («Новый» на сегодняшних данных).
     */
    public static function firstStageKey(): ?string
    {
        return LeadStage::query()->ordered()->value('key');
    }

    /**
     * Единый гард смены статуса (используется формой/колонкой в LeadResource
     * и канбан-доской LeadKanbanBoard): нельзя молча откатить финальную стадию
     * в первую стадию воронки.
     */
    public static function blocksRollbackToFirstStage(string $currentStatus, string $newStatus): bool
    {
        return $newStatus === self::firstStageKey()
            && in_array($currentStatus, self::finalStatuses(), true);
    }

    protected $fillable = [
        // Основные данные
        'landing_page_id',
        'landing_copy_variant',
        'name',
        'contact',
        'email',            // <--- Важно: Добавили Email
        'social',
        'is_promo_agreed',
        'converted_at',

        // Воронка (статус, ответственный, дата следующего контакта)
        'status',
        'rejection_reason',
        'assigned_to',
        'next_contact_at',
        'reminded_at',

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
        'reminded_at' => 'datetime',
    ];

    // Стадия воронки (LeadStage.key ↔ leads.status) — источник канбан-доски.
    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'status', 'key');
    }

    // Заголовок карточки на канбан-доске: имя, иначе контакт, иначе email.
    public function getKanbanTitleAttribute(): string
    {
        return $this->name ?: ($this->contact ?: ($this->email ?: 'Лид #'.$this->id));
    }

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
            if (! in_array($this->status, self::finalStatuses(), true)) {
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
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();
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
