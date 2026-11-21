<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H8527 «вопрос-стопочка»: нераспознанные reply-вопросы участников на посты
 * zapisi-бота и студенческие предложения переносов. Ничего не отправляет и
 * не мутирует расписание — только машиночитаемый список для проактивной
 * агентной обработки MG (zapisi:student-questions) и черновик куратору
 * (SupportAnswerSuggester-паттерн P1: черновик хранится, не отправляется).
 */
class TelegramStudentQuestion extends Model
{
    public const KIND_QUESTION = 'question';

    public const KIND_PROPOSAL = 'proposal';

    public const POST_KIND_HOMEWORK = 'homework';

    public const POST_KIND_REMINDER = 'reminder';

    public const POST_KIND_SCHEDULE = 'schedule';

    public const POST_KIND_UNKNOWN = 'unknown';

    public const STATUS_NEW = 'new';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_DISMISSED = 'dismissed';

    public const DRAFT_STATE_PENDING = 'pending';

    public const DRAFT_STATE_GENERATED = 'generated';

    public const DRAFT_STATE_FAILED = 'failed';

    public const DRAFT_STATE_OFF = 'off';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'group_id',
        'author_telegram_id',
        'author_name',
        'author_username',
        'kind',
        'post_kind',
        'question_text',
        'reply_to_message_id',
        'replied_post_excerpt',
        'status',
        'curator_draft',
        'curator_draft_model',
        'curator_draft_state',
        'ack_message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
