<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('services.admin.email');
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

        // Синхронизируем пароль и admin-флаг при каждом запуске сидера:
        // изменение ADMIN_PASSWORD в .env должно вступать в силу после db:seed.
        $admin->forceFill([
            'password' => Hash::make($password),
            'is_admin' => true,
        ])->save();
    }
}
