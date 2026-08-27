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

        // Пароль ОБЯЗАН быть в defaults создания: иначе firstOrCreate вставляет
        // строку без password, и на строгом NOT NULL (SQLite — локальные тесты,
        // `migrate:fresh --seed`) падает «NOT NULL constraint failed: users.password».
        // MySQL в нестрогом режиме молча подставлял '' и маскировал это. forceFill
        // ниже всё равно пере-задаёт пароль/роль на КАЖДОМ прогоне (для существующего
        // аккаунта), так что синхронизация ADMIN_PASSWORD сохраняется.
        $admin = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password), 'role' => Roles::SUPER_ADMIN]
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

        // Общая библиотека шаблонов сообщений оператора (H221) — идемпотентно.
        $this->call(MessageTemplateSeeder::class);

        // Системная санскритская колода SRS (H211) — идемпотентно, безопасно при
        // пустом словаре. Данные заводятся всегда; показываются только за флагом srs.enabled.
        $this->call(SrsSanskritDeckSeeder::class);

        // Корни санскрита по частотности (H1280, D4) — идемпотентно, читает
        // committed-фикстуру, не зависит от словаря.
        $this->call(SrsRootFrequencyDeckSeeder::class);

        // Черновая таксономия направлений курсов (H1426) — идемпотентно; MG
        // пересматривает/переименовывает через CategoryResource после посева.
        $this->call(CategorySeeder::class);

        // Три встроенных сегмента GC-A1 (H1637) — идемпотентно; видны только
        // за флагом marketing_segments.
        $this->call(SegmentSeeder::class);

        // Библиотека важных файлов админки (H2570) — идемпотентно; названия
        // строк MG правит через AdminDocumentResource, сидер их не затирает.
        $this->call(AdminDocumentSeeder::class);

        // Каталог живых книг кабинета (H3243) — идемпотентно по slug.
        $this->call(ProductDocSeeder::class);
    }
}
