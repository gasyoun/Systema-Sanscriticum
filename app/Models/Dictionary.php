<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dictionary extends Model
{
    use HasFactory;

    // Разрешаем массовое заполнение всех полей
    protected $guarded = [];

    // Подсказываем Laravel, что это логический тип (true/false)
    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Связь: У одного словаря может быть много слов
    public function words()
    {
        return $this->hasMany(DictionaryWord::class);
    }
}