<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Сообщение принадлежит студенту
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}