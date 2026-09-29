<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Событие воронки анкеты (H5098): sent / opened / started / page / completed.
 * Строки привязаны к приглашению и/или ответу, session_key — анонимный UUID
 * куки для сопоставления пути гостя. Персональных данных в таблице нет.
 */
class SurveyEvent extends Model
{
    public const SENT = 'sent';

    public const OPENED = 'opened';

    public const STARTED = 'started';

    public const PAGE = 'page';

    public const COMPLETED = 'completed';

    /** @var list<string> */
    public const EVENTS = [
        self::SENT,
        self::OPENED,
        self::STARTED,
        self::PAGE,
        self::COMPLETED,
    ];

    protected $fillable = [
        'survey_slug',
        'survey_invitation_id',
        'survey_response_id',
        'user_id',
        'session_key',
        'event',
        'page_index',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'survey_invitation_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }
}
