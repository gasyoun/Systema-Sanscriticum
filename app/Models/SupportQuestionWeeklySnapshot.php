<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportQuestionWeeklySnapshot extends Model
{
    protected $fillable = [
        'week_start',
        'classifier_version',
        'payload',
        'is_incomplete',
        'incompleteness_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_incomplete' => 'boolean',
    ];

    // week_start хранится голой строкой Y-m-d: date-cast пишет 'Y-m-d H:i:s',
    // и на SQLite upsert-поиск по 'Y-m-d' перестаёт видеть свою же строку,
    // ломая уникальность (week_start, classifier_version).
}
