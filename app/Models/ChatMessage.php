<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'answered_by',
        'text',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    // Сообщение принадлежит студенту
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Кто из кураторов/админов отправил ответ (для роли curator).
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
