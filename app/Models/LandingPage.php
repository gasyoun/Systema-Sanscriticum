<?php

namespace App\Models;

use App\Support\StatusBlock;
use App\Support\StorefrontEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LandingPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'is_active',
        'is_listed',
        'hide_default_nav',
        'header_note_text',
        'header_note_url',

        // --- ГЛАВНОЕ ИЗМЕНЕНИЕ: Разрешаем поле конструктора ---
        'content',
        // -----------------------------------------------------

        'image_path',
        'instructor_label',
        'instructor_name',
        'webinar_date',
        'webinar_label',
        'webinar_url',
        'webinar_recording_url',
        'magnet_lead_minutes',
        'video_url',
        'description',
        'button_text',
        'telegram_url',
        'redirect_after_submit_url',
        'yandex_metrika_id',
        'vk_pixel_id',

        // Поля Hero-секции (Legacy)
        'subtitle',
        'hero_description',
        'bullet_1',
        'bullet_2',
        'button_subtext',

        // Особенности обучения (Legacy)
        'features_title',
        'feature_1_title', 'feature_1_text',
        'feature_2_title', 'feature_2_text',
        'feature_3_title', 'feature_3_text',

        // Lead-magnet — файл, который бот пришлёт юзеру после заявки
        'lead_magnet_enabled',
        'lead_magnet_file_path',
        'lead_magnet_title',
        'lead_magnet_caption',
        'lead_magnet_default_channel',
    ];

    protected $casts = [
        'webinar_date' => 'datetime', // точное время старта вебинара
        'magnet_lead_minutes' => 'integer',
        'is_active' => 'boolean',
        'is_listed' => 'boolean',
        'hide_default_nav' => 'boolean',

        // --- ВАЖНО: Превращаем JSON в массив ---
        'content' => 'array',
        // ---------------------------------------

        'lead_magnet_enabled' => 'boolean',
    ];

    /** Сколько ещё выдаём магнит ПОСЛЕ старта вебинара (опоздавшим). */
    public const MAGNET_GRACE_AFTER_START_MINUTES = 120;

    public function hasLeadMagnet(): bool
    {
        return $this->lead_magnet_enabled && ! empty($this->lead_magnet_file_path);
    }

    /**
     * Лендинг обещает подписку на статусы курса (блок status_block) — заявке на
     * нём выдаётся binding-токен, даже если файла-магнита нет (H3339). Файл при
     * этом никогда не доставляется: гейт hasLeadMagnet() остаётся в пути выдачи.
     */
    public function hasStatusBlock(): bool
    {
        return StatusBlock::inContent($this->content);
    }

    /** Группа, чьи статусы обещает status_block этого лендинга (или null). */
    public function statusBlockGroup(): ?Group
    {
        return StatusBlock::resolveGroup(StatusBlock::data($this->content));
    }

    /**
     * У лендинга задан старт вебинара → лид-магнит выдаём не сразу, а в окне
     * «за N минут до старта» (см. magnetLeadMinutes / isMagnetWindowOpen).
     */
    public function hasScheduledWebinar(): bool
    {
        return ! is_null($this->webinar_date);
    }

    /** За сколько минут до старта вебинара выдавать лид-магнит (дефолт 60). */
    public function magnetLeadMinutes(): int
    {
        return $this->magnet_lead_minutes ?? 60;
    }

    /** Момент открытия окна выдачи магнита (старт − offset). null, если вебинар не задан. */
    public function magnetWindowOpensAt(): ?Carbon
    {
        return $this->webinar_date?->copy()->subMinutes($this->magnetLeadMinutes());
    }

    /** Момент закрытия окна выдачи (старт + грейс). null, если вебинар не задан. */
    public function magnetWindowClosesAt(): ?Carbon
    {
        return $this->webinar_date?->copy()->addMinutes(self::MAGNET_GRACE_AFTER_START_MINUTES);
    }

    /** Открыто ли сейчас окно выдачи: [старт − offset; старт + грейс] включительно. */
    public function isMagnetWindowOpen(?Carbon $now = null): bool
    {
        if (! $this->hasScheduledWebinar()) {
            return false;
        }

        $now ??= now();

        return $now->betweenIncluded($this->magnetWindowOpensAt(), $this->magnetWindowClosesAt());
    }

    /** Storefront heading: past dated webinar titles become a recording label (H2760). */
    public function storefrontTitle(?Carbon $now = null): string
    {
        return StorefrontEvent::storefrontTitle($this, $now);
    }

    /** Storefront badge; dated past labels are hidden or relabeled. */
    public function storefrontBadge(?Carbon $now = null): ?string
    {
        return StorefrontEvent::storefrontBadge($this, $now);
    }

    /** CTA on a listing card. Past dated webinars are not «Записаться». */
    public function storefrontButtonText(?Carbon $now = null): string
    {
        return StorefrontEvent::storefrontButtonText($this, $now);
    }

    // Связь с лидами (если понадобится)
    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    // Отдельный Telegram-бот лид-магнита этого лендинга (архитектура «свой бот на лендинг»).
    public function bot()
    {
        return $this->hasOne(LandingBot::class);
    }

    // ==========================================
    // УМНЫЕ ПОЛЯ ДЛЯ ВИТРИНЫ (АКСЕССОРЫ)
    // ==========================================

    /**
     * Ищет картинку для обложки в блоках конструктора
     */
    public function getShowcaseImageAttribute()
    {
        // 1. Сначала ищем в новых блоках конструктора (content)
        if (! empty($this->content) && is_array($this->content)) {
            foreach ($this->content as $block) {
                $data = $block['data'] ?? [];

                // Проверяем популярные названия полей для картинок в вашем Filament
                if (! empty($data['image'])) {
                    return $data['image'];
                }
                if (! empty($data['background_image'])) {
                    return $data['background_image'];
                }
                if (! empty($data['cover'])) {
                    return $data['cover'];
                }
            }
        }

        // 2. Если в конструкторе ничего нет, отдаем старую картинку
        return $this->image_path;
    }

    /**
     * Ищет описание для карточки в блоках конструктора
     */
    public function getShowcaseDescriptionAttribute()
    {
        if (! empty($this->content) && is_array($this->content)) {
            foreach ($this->content as $block) {
                $data = $block['data'] ?? [];

                // Проверяем популярные названия полей для текста
                if (! empty($data['description'])) {
                    return strip_tags($data['description']);
                }
                if (! empty($data['text'])) {
                    return strip_tags($data['text']);
                }
                if (! empty($data['subtitle'])) {
                    return strip_tags($data['subtitle']);
                }
                if (! empty($data['hero_text'])) {
                    return strip_tags($data['hero_text']);
                }
            }
        }

        // Если ничего не нашли в конструкторе, отдаем старое описание
        return $this->description;
    }
}
