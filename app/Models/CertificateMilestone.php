<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Веха выдачи документов (сертификат/справка) на курсе. Пять типов триггера:
 *
 *  - block           — конец блока end_block по CourseBlock.ends_at (v1, курс-уровневая);
 *  - lesson_count    — у группы состоялось trigger_lesson занятий; с repeat_every
 *                      веха повторяется каждые R занятий (Бюлер: справка каждые 10;
 *                      вечные курсы Лейтана: сертификат каждые 16);
 *  - textbook_lesson — у группы прошёл урок с lesson.textbook_lesson >= trigger_lesson
 *                      (Кочергина: справка после 20-го, сертификат после 40-го);
 *  - material_count  — у группы состоялось trigger_lesson занятий с material_tag
 *                      (Эмено: 15 занятий; уроки размечает LessonMaterialTagger по
 *                      стенограммам, куратор может править руками);
 *  - manual          — только кнопкой «Выдать по вехе» (страховка, когда автоматике
 *                      довериться нельзя).
 *
 * «Занятие состоялось» = Lesson группы с lesson_date <= сегодня; is_published НЕ
 * учитывается — публикация ждёт монтажа записи, сертификат ждать не должен.
 * Кто именно получает — MilestoneCertificateIssuer.
 */
class CertificateMilestone extends Model
{
    use HasFactory;

    public const TRIGGER_BLOCK = 'block';

    public const TRIGGER_LESSON_COUNT = 'lesson_count';

    public const TRIGGER_TEXTBOOK = 'textbook_lesson';

    public const TRIGGER_MATERIAL = 'material_count';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_TYPES = [
        self::TRIGGER_BLOCK => 'По блокам (даты)',
        self::TRIGGER_LESSON_COUNT => 'По числу занятий',
        self::TRIGGER_TEXTBOOK => 'По уроку учебника',
        self::TRIGGER_MATERIAL => 'По занятиям материала',
        self::TRIGGER_MANUAL => 'Только вручную',
    ];

    public const DOC_CERTIFICATE = 'certificate';

    public const DOC_SPRAVKA = 'spravka';

    public const DOCUMENT_TYPES = [
        self::DOC_CERTIFICATE => 'Сертификат',
        self::DOC_SPRAVKA => 'Справка об образовании',
    ];

    protected $fillable = [
        'course_id',
        'title',
        'certificate_title',
        'template',
        'trigger_type',
        'start_block',
        'end_block',
        'trigger_lesson',
        'repeat_every',
        'document_type',
        'material_tag',
        'material_keywords',
        'auto_issue_enabled',
        'is_active',
    ];

    protected $casts = [
        'start_block' => 'integer',
        'end_block' => 'integer',
        'trigger_lesson' => 'integer',
        'repeat_every' => 'integer',
        'auto_issue_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Дефолты и в памяти, не только в схеме: свежесозданный через create()
    // инстанс без этих ключей иначе вернёт null, и isAutoIssuable() соврёт.
    protected $attributes = [
        'template' => 'gasuns',
        'trigger_type' => self::TRIGGER_BLOCK,
        'document_type' => self::DOC_CERTIFICATE,
        'auto_issue_enabled' => true,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        // Появилась веха — курс больше не «забытый»: сбрасываем штамп детектора,
        // чтобы при удалении всех вех он мог сработать снова.
        static::created(function (self $milestone): void {
            $milestone->course()->update(['milestones_nudge_sent_at' => null]);
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** Блок, завершение которого триггерит block-веху (course_id + number = end_block). */
    public function endBlock(): ?CourseBlock
    {
        if ($this->end_block === null) {
            return null;
        }

        return CourseBlock::query()
            ->where('course_id', $this->course_id)
            ->where('number', $this->end_block)
            ->first();
    }

    /**
     * Может ли веха выдаваться автоматикой. Шаблон «Санка» сам по себе не
     * блокирует — Issuer дополнительно фильтрует студентов по наличию баллов
     * в exam_scores (без баллов сертификат не выдаётся и не рисуется).
     */
    public function isAutoIssuable(): bool
    {
        return $this->is_active
            && $this->auto_issue_enabled
            && $this->trigger_type !== self::TRIGGER_MANUAL;
    }

    /** Повторяющаяся веха: «каждые R занятий» (только lesson_count). */
    public function isRepeating(): bool
    {
        return $this->trigger_type === self::TRIGGER_LESSON_COUNT
            && (int) $this->repeat_every > 0;
    }

    /**
     * Окно занятий итерации $occurrence (1-based): повторяющаяся веха —
     * (k-1)*R+1..k*R, разовая — 1..trigger_lesson.
     *
     * @return array{from:int,to:int}
     */
    public function lessonWindow(int $occurrence): array
    {
        if ($this->isRepeating()) {
            $r = (int) $this->repeat_every;

            return ['from' => ($occurrence - 1) * $r + 1, 'to' => $occurrence * $r];
        }

        return ['from' => 1, 'to' => (int) $this->trigger_lesson];
    }

    /**
     * Уроки курса в скоупе группы: общие (group_id NULL) + уроки этой группы.
     * Тот же принцип видимости, что у Lesson::scopeForUserGroups.
     */
    public function groupLessons(Group $group): Builder
    {
        return Lesson::query()
            ->where('course_id', $this->course_id)
            ->where(function (Builder $q) use ($group) {
                $q->whereNull('group_id')->orWhere('group_id', $group->id);
            });
    }

    public function documentLabel(): string
    {
        return self::DOCUMENT_TYPES[$this->document_type] ?? self::DOCUMENT_TYPES[self::DOC_CERTIFICATE];
    }

    /** Заголовок для снапшота course_title сертификата. */
    public function resolvedCertificateTitle(): string
    {
        return $this->certificate_title ?: ($this->course->title ?? '');
    }

    /**
     * Заголовок итерации: у повторяющихся вех дописываем окно занятий —
     * PDF, verify и письмо получают контекст без правок рендера.
     */
    public function snapshotTitleFor(int $occurrence): string
    {
        $title = $this->resolvedCertificateTitle();

        if ($this->isRepeating()) {
            $w = $this->lessonWindow($occurrence);
            $title .= " (занятия {$w['from']}–{$w['to']})";
        }

        return $title;
    }

    /** Ключевые слова material_count-вехи списком (CSV → trimmed, без пустых). */
    public function materialKeywords(): array
    {
        return collect(explode(',', (string) $this->material_keywords))
            ->map(fn (string $k) => trim($k))
            ->filter()
            ->values()
            ->all();
    }
}
