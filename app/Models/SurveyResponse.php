<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ответ на публичную анкету (движок опросов, рулинг MG 24-08-2026: вариант Б —
 * нативно на samskrte.ru). Вопросы живут в config/surveys.php; сюда падает
 * только JSON ответов + контакт и награда. Прана за «прана 500 ₽» начисляется
 * сразу при совпадении контакта с учёткой; иначе строка ждёт куратора.
 */
class SurveyResponse extends Model
{
    protected $fillable = [
        'survey_slug',
        'user_id',
        'answers',
        'contact',
        'reward_choice',
        'reward_user_id',
        'reward_sent_at',
        'ip_hash',
    ];

    protected $casts = [
        'answers' => 'array',
        'reward_sent_at' => 'datetime',
    ];
}
