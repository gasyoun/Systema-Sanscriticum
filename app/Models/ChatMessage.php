<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
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
}