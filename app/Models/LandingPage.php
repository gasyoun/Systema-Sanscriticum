<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan; 

class LandingPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'is_active',
        
        // --- ГЛАВНОЕ ИЗМЕНЕНИЕ: Разрешаем поле конструктора ---
        'content', 
        // -----------------------------------------------------

        'image_path',
        'instructor_label',
        'instructor_name',
        'webinar_date',    
        'webinar_label',   
        'video_url',
        'description',
        'button_text',
        'telegram_url',
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
    ];

    protected $casts = [
        'webinar_date' => 'datetime', // Лучше использовать datetime
        'is_active' => 'boolean',
        
        // --- ВАЖНО: Превращаем JSON в массив ---
        'content' => 'array', 
        // ---------------------------------------
    ];

    // Автоматическая очистка кэша при сохранении
    protected static function booted()
    {
        static::saved(function ($model) {
            // Очищаем кэш, чтобы изменения на сайте появились сразу
            Artisan::call('view:clear'); 
            Artisan::call('cache:clear');
        });
    }

    // Связь с лидами (если понадобится)
    public function leads()
    {
        return $this->hasMany(Lead::class);
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
        if (!empty($this->content) && is_array($this->content)) {
            foreach ($this->content as $block) {
                $data = $block['data'] ?? [];
                
                // Проверяем популярные названия полей для картинок в вашем Filament
                if (!empty($data['image'])) return $data['image'];
                if (!empty($data['background_image'])) return $data['background_image'];
                if (!empty($data['cover'])) return $data['cover'];
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
        if (!empty($this->content) && is_array($this->content)) {
            foreach ($this->content as $block) {
                $data = $block['data'] ?? [];
                
                // Проверяем популярные названия полей для текста
                if (!empty($data['description'])) return strip_tags($data['description']);
                if (!empty($data['text'])) return strip_tags($data['text']);
                if (!empty($data['subtitle'])) return strip_tags($data['subtitle']);
                if (!empty($data['hero_text'])) return strip_tags($data['hero_text']);
            }
        }

        // Если ничего не нашли в конструкторе, отдаем старое описание
        return $this->description;
    }
}