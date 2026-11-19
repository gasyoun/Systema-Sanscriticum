<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportQuestionClassification extends Model
{
    public const POPULATION_STUDENT = 'student';

    public const POPULATION_ENQUIRY = 'enquiry';

    public const POPULATION_STAFF_INTERNAL = 'staff_internal';

    public const POPULATION_UNKNOWN = 'unknown';

    public const EXCLUSION_SERVICE_MESSAGE = 'service_message';

    public const EXCLUSION_BOT = 'bot';

    public const EXCLUSION_DUPLICATE_IMPORT = 'duplicate_import';

    public const EXCLUSION_NOT_QUESTION = 'not_question';

    protected $fillable = [
        'telegram_support_message_id',
        'classifier_version',
        'population',
        'is_question',
        'primary_category',
        'secondary_categories',
        'exclusion_reason',
        'flags',
    ];

    protected $casts = [
        'secondary_categories' => 'array',
        'flags' => 'array',
        'is_question' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportMessage::class, 'telegram_support_message_id');
    }
}
