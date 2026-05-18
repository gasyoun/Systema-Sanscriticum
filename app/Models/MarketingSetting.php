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

        // --- LEAD MAGNET BOTS ---
        'magnet_delivery_mode',
        'tg_bot_username',
        'tg_bot_token',
        'tg_webhook_secret',
        'vk_group_screen_name',
        'vk_group_id',
        'vk_access_token',
        'vk_callback_secret',
        'vk_confirmation_code',
        'max_bot_username',
        'max_bot_token',
        'max_webhook_secret',
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

        // Bot-токены шифруются на уровне модели — в БД лежит шифр, не plaintext.
        'tg_bot_token' => 'encrypted',
        'vk_access_token' => 'encrypted',
        'max_bot_token' => 'encrypted',
    ];
}
