<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    use HasFactory;

    // Отключаем updated_at — сырые события неизменяемы (append-only)
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'event_type',
        'event_data',
        'url',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'event_data' => 'array', // JSON поле → массив в PHP
        'created_at' => 'datetime',
    ];

    /**
     * Константы типов событий.
     * Используем константы вместо "магических строк" — чтобы IDE автокомплит ловил
     * и при рефакторинге можно было легко найти все использования.
     */
    public const TYPE_LOGIN = 'login';

    public const TYPE_LOGOUT = 'logout';

    public const TYPE_LESSON_OPEN = 'lesson_open';

    public const TYPE_LESSON_COMPLETE = 'lesson_complete';

    public const TYPE_NOTE_SAVED = 'note_saved';

    public const TYPE_MATERIAL_DOWNLOAD = 'material_download';

    public const TYPE_COURSE_VIEW = 'course_view';

    public const TYPE_SESSION_TIMEOUT = 'session_timeout';

    /** Куратор вызвал бот-команду (/долги, /группа) — см. DebtorsBotCommand (H250). */
    public const TYPE_CURATOR_BOT_COMMAND = 'curator_bot_command';

    // --- События кабинета по спеке ремейка §4 (H962, Phase 0 baseline).
    // Имена — ровно как в docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md §4:
    // они без изменений переезжают в гибрид, поэтому НЕ переименовывать.
    // lesson.view.heartbeat и cabinet.live.zoom.click здесь НЕ дублируются —
    // они уже пишутся в свои таблицы (lesson_views, schedule_join_clicks);
    // baseline-отчёт (cabinet:baseline) агрегирует все источники под именами §4.

    public const CABINET_HOME_VIEW = 'cabinet.home.view';

    public const CABINET_CONTINUE_CLICK = 'cabinet.continue.click';

    public const CABINET_HOMEWORK_REWORK_CLICK = 'cabinet.homework.rework.click';

    public const LESSON_MARK_MASTERED = 'lesson.mark.mastered';

    public const COURSE_TAB_VIEW = 'course.tab.view';

    public const OFFER_IMPRESSION = 'offer.impression';

    public const OFFER_CLICK = 'offer.click';

    public const ACCESS_RENEWAL_START = 'access.renewal.start';

    public const ACCESS_RENEWAL_COMPLETE = 'access.renewal.complete';

    /**
     * События §4, которые разрешено присылать с клиента (first-party JS,
     * POST student.telemetry). Серверные (home.view, mark.mastered,
     * renewal.complete) сюда сознательно НЕ входят — их клиент прислать не может.
     */
    public const CLIENT_CABINET_EVENTS = [
        self::CABINET_CONTINUE_CLICK,
        self::CABINET_HOMEWORK_REWORK_CLICK,
        self::COURSE_TAB_VIEW,
        self::OFFER_IMPRESSION,
        self::OFFER_CLICK,
        self::ACCESS_RENEWAL_START,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }
}
