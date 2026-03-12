<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str; // <--- 1. ДОБАВЬ ЭТУ СТРОКУ

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'number', // Убедись, что это поле есть в fillable
        'file_path',
        'issue_at',
    ];

    // --- 2. ДОБАВЬ ЭТОТ БЛОК КОДА (МАГИЯ АВТОГЕНЕРАЦИИ) ---
    protected static function booted()
    {
        static::creating(function ($certificate) {
            // Генерация номера
            if (empty($certificate->number)) {
                $certificate->number = date('Y') . '-' . strtoupper(Str::random(5));
            }
            
            // 1. АВТОМАТИЧЕСКАЯ ДАТА (ДОБАВЬ ЭТО)
            if (empty($certificate->issued_at)) {
                $certificate->issued_at = now();
            }
        });
    }
    // -----------------------------------------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
