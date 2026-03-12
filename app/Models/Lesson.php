<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'topic',
        'lesson_date',
        'video_url',   // (старое поле, если было)
        'rutube_url',  // Rutube
        'youtube_url', // <--- ВОТ НАШ НОВИЧОК
        'attachments', // Файлы
        'course_id',
        'is_published',
        'block_number', // <--- ВОТ ПОЛЕ ДЛЯ БЛОКА (МОДУЛЯ)
        'transcript_file',
    ];

    // Обязательно добавь это, чтобы JSON превращался в массив
    protected $casts = [
        'attachments' => 'array',
        'is_published' => 'boolean',
        'block_number' => 'integer', // Гарантируем, что это всегда будет число
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}