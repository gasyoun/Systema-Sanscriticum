<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('services.admin.email');
        $password = config('services.admin.password');

        if (empty($password)) {
            throw new RuntimeException(
                'ADMIN_PASSWORD must be set in .env before seeding. Run: php artisan config:clear && set ADMIN_PASSWORD in .env.'
            );
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Admin']
        );

        // Синхронизируем пароль и роль при каждом запуске сидера:
        // изменение ADMIN_PASSWORD в .env должно вступать в силу после db:seed.
        // Роль — единственный источник правды (booted::saving синхронизирует
        // legacy-флаг is_admin от неё). Задаём super_admin, чтобы сидерный
        // аккаунт мог править других админов и назначать роли.
        $admin->forceFill([
            'password' => Hash::make($password),
            'role' => Roles::SUPER_ADMIN,
        ])->save();
    }
}
