<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Баллы экзамена «Санка» до выдачи сертификата. Источник — Google-таблица
 * преподавателя, затягивается n8n-воркфлоу через POST /api/webhooks/exam-scores.
 * Одна актуальная строка на (user, course); пересдача перезаписывает. Выданный
 * сертификат — замороженный снапшот: баллы копируются в него один раз при
 * создании (MilestoneCertificateIssuer::issueTo), обновления сюда его не трогают.
 */
class ExamScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'score_clarity',
        'score_letters',
        'score_flow',
        'source_row',
        'imported_at',
    ];

    protected $casts = [
        'score_clarity' => 'float',
        'score_letters' => 'float',
        'score_flow' => 'float',
        'imported_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Есть ли хоть один балл (симметрия с Certificate::hasExamScores()). */
    public function hasAnyScore(): bool
    {
        return $this->score_clarity !== null
            || $this->score_letters !== null
            || $this->score_flow !== null;
    }
}
