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
        'number',
        'file_path',
        'issued_at', // <-- ИСПРАВЛЕНО: добавлена буква 'd'
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
