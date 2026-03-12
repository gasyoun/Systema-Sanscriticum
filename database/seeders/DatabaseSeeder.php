<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Создаем Супер-Админа, если его нет
        User::firstOrCreate(
            ['email' => 'pe4kin.85@mail.ru'],
            [
                'name' => 'Admin',
                'password' => Hash::make('240885'), // <-- ЗАМЕНИ 'password' НА СВОЙ ПАРОЛЬ
            ]
        );
    }
}
