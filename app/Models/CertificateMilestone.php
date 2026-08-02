<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Веха выдачи сертификатов на курсе: диапазон блоков (start_block..end_block по
 * CourseBlock.number) и параметры сертификата. Средний курс — 4 блока и одна
 * итоговая веха, но длинные курсы (грамматики) настраивают промежуточные:
 * «Деванагари» за блоки 1–2 плюс итоговая за 1–4.
 *
 * Триггер автовыдачи — ends_at блока end_block (ежедневная команда
 * certificates:issue-milestones). Кто именно получает — MilestoneCertificateIssuer.
 */
class CertificateMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'certificate_title',
        'template',
        'start_block',
        'end_block',
        'auto_issue_enabled',
        'is_active',
    ];

    protected $casts = [
        'start_block' => 'integer',
        'end_block' => 'integer',
        'auto_issue_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Дефолты и в памяти, не только в схеме: свежесозданный через create()
    // инстанс без этих ключей иначе вернёт null, и isAutoIssuable() соврёт.
    protected $attributes = [
        'template' => 'gasuns',
        'auto_issue_enabled' => true,
        'is_active' => true,
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** Блок, завершение которого триггерит веху (course_id + number = end_block). */
    public function endBlock(): ?CourseBlock
    {
        return CourseBlock::query()
            ->where('course_id', $this->course_id)
            ->where('number', $this->end_block)
            ->first();
    }

    /**
     * Может ли веха выдаваться автоматикой. Шаблон «Санка» — экзаменационный,
     * требует ручных баллов (EXAM_CRITERIA), автоматика его не заполнит.
     */
    public function isAutoIssuable(): bool
    {
        return $this->is_active
            && $this->auto_issue_enabled
            && $this->template !== 'sanka';
    }

    /** Заголовок для снапшота course_title сертификата. */
    public function resolvedCertificateTitle(): string
    {
        return $this->certificate_title ?: ($this->course->title ?? '');
    }
}
