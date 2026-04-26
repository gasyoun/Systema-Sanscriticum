<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    // 1. Массив разрешенных полей (строгий список, без стрелочек!)
    protected $fillable = [
        'title',
        'preview',
        'target_groups',
        'target_courses',
        'image_path',
        'content',
        'button_text',
        'button_url',
        'is_published',
        'send_to_email',
        'send_to_telegram',
        'send_to_vk',
    ];

    // 2. Настройка типов данных (как в твоей модели User)
    protected function casts(): array
    {
        return [
            'target_groups'  => 'array',
            'target_courses' => 'array',
            'is_published'   => 'boolean',
            'send_to_email'     => 'boolean',
            'send_to_telegram'  => 'boolean',
            'send_to_vk'        => 'boolean',
        ];
    }
}