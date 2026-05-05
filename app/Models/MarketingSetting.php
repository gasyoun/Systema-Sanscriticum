<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_loyalty_active',
        'bundle_2_discount',
        'bundle_3_discount',
        'wholesale_small_threshold',
        'wholesale_small_discount',
        'wholesale_large_threshold',
        'wholesale_large_discount',
        'blog_yandex_metrika_id',
        'blog_vk_pixel_id',
        // --- ПРАНА ---
        'is_prana_active',
        'prana_rate',
        'prana_max_share_percent',
        'prana_reward_lesson_complete',
        'prana_reward_course_complete',
        'prana_reward_open_lesson_view',
        'prana_reward_daily_login',
        'prana_reward_payment_success',
    ];

    protected $casts = [
        'is_loyalty_active' => 'boolean',
        'bundle_2_discount' => 'integer',
        'bundle_3_discount' => 'integer',
        'wholesale_small_threshold' => 'integer',
        'wholesale_small_discount' => 'integer',
        'wholesale_large_threshold' => 'integer',
        'wholesale_large_discount' => 'integer',
        'is_prana_active' => 'boolean',
        'prana_rate' => 'integer',
        'prana_max_share_percent' => 'integer',
        'prana_reward_lesson_complete' => 'integer',
        'prana_reward_course_complete' => 'integer',
        'prana_reward_open_lesson_view' => 'integer',
        'prana_reward_daily_login' => 'integer',
        'prana_reward_payment_success' => 'integer',
    ];
}