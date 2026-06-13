<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'student_name',
        'course_title',
        'template',
        'score_clarity',
        'score_letters',
        'score_flow',
        'number',
        'file_path',
        'issued_at', // <-- ИСПРАВЛЕНО: добавлена буква 'd'
    ];

    protected $casts = [
        'score_clarity' => 'float',
        'score_letters' => 'float',
        'score_flow' => 'float',
    ];

    /**
     * Дисциплины экзамена: поле => подпись и максимум. Единый источник
     * для формы, PDF и страницы верификации. Используется только для шаблона «Санка».
     */
    public const EXAM_CRITERIA = [
        'score_clarity' => ['label' => 'Выразительность', 'max' => 20],
        'score_letters' => ['label' => 'Дикция', 'max' => 5],
        'score_flow' => ['label' => 'Плавность', 'max' => 5],
    ];

    /**
     * Доступные шаблоны сертификата. Различаются росписью преподавателя —
     * каждому соответствует своё фоновое изображение целиком.
     */
    public const TEMPLATES = [
        'gasuns' => ['label' => 'Роспись: Гасунс', 'background' => 'images/ganesha_gasuns.jpg'],
        'sanka' => ['label' => 'Роспись: Санка', 'background' => 'images/ganesha_sanka.jpg'],
    ];

    /**
     * Опции для Select (ключ => подпись).
     */
    public static function templateOptions(): array
    {
        return collect(self::TEMPLATES)->map(fn ($t) => $t['label'])->all();
    }

    /**
     * Абсолютный путь к фону для выбранного шаблона. Если файла ещё нет —
     * фолбэк на нейтральный фон, чтобы генерация не падала.
     */
    public function backgroundPath(): string
    {
        $rel = self::TEMPLATES[$this->template ?? 'gasuns']['background']
            ?? self::TEMPLATES['gasuns']['background'];
        $abs = public_path($rel);

        return is_file($abs) ? $abs : public_path('images/ganesha_clean.jpg');
    }

    protected static function booted()
    {
        static::creating(function ($certificate) {
            // Генерация уникального номера сертификата
            if (empty($certificate->number)) {
                $certificate->number = date('Y').'-'.strtoupper(Str::random(5));
            }

            // Автоматическая подстановка даты выдачи
            if (empty($certificate->issued_at)) {
                $certificate->issued_at = now();
            }
        });
    }

    /**
     * Имя для отображения: снимок ФИО с фолбэком на профиль.
     * Используется и в PDF, и на публичной странице верификации.
     */
    public function displayStudentName(): string
    {
        return $this->student_name ?: ($this->user->name ?? '');
    }

    /**
     * Название курса для отображения: снимок с фолбэком на курс.
     */
    public function displayCourseTitle(): string
    {
        return $this->course_title ?: ($this->course->title ?? '');
    }

    /**
     * Заполнен ли хоть один балл экзамена.
     */
    public function hasExamScores(): bool
    {
        foreach (array_keys(self::EXAM_CRITERIA) as $field) {
            if ($this->$field !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Показывать ли блок с баллами: только шаблон «Санка» и есть оценки.
     */
    public function showsExamScores(): bool
    {
        return $this->template === 'sanka' && $this->hasExamScores();
    }

    /**
     * Итоговый балл = сумма по трём дисциплинам (макс. 30).
     */
    public function examTotal(): float
    {
        $sum = 0.0;
        foreach (array_keys(self::EXAM_CRITERIA) as $field) {
            $sum += (float) $this->$field;
        }

        return $sum;
    }

    public static function examTotalMax(): int
    {
        return array_sum(array_column(self::EXAM_CRITERIA, 'max'));
    }

    /**
     * Балл без лишних нулей: 18.0 → «18», 4.5 → «4.5».
     */
    public static function formatScore($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
