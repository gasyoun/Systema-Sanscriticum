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
    ];

    protected $casts = [
        'is_loyalty_active' => 'boolean',
        'bundle_2_discount' => 'integer',
        'bundle_3_discount' => 'integer',
        'wholesale_small_threshold' => 'integer',
        'wholesale_small_discount' => 'integer',
        'wholesale_large_threshold' => 'integer',
        'wholesale_large_discount' => 'integer',
    ];
}