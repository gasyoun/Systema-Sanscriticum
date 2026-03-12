<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_loyalty_active',
        'loyalty_discount_percent',
    ];

    protected $casts = [
        'is_loyalty_active' => 'boolean',
        'loyalty_discount_percent' => 'integer',
    ];
}