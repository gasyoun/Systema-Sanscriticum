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
        'number',
        'file_path',
        'issued_at', // <-- ИСПРАВЛЕНО: добавлена буква 'd'
    ];

    protected static function booted()
    {
        static::creating(function ($certificate) {
            // Генерация уникального номера сертификата
            if (empty($certificate->number)) {
                $certificate->number = date('Y') . '-' . strtoupper(Str::random(5));
            }
            
            // Автоматическая подстановка даты выдачи
            if (empty($certificate->issued_at)) {
                $certificate->issued_at = now();
            }
        });
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