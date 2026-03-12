<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DictionaryWord extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Обратная связь: Каждое слово принадлежит какому-то одному словарю
    public function dictionary()
    {
        return $this->belongsTo(Dictionary::class);
    }
}