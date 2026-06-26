<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Сидер админа должен проходить на строгом NOT NULL (SQLite — локальные тесты,
 * `migrate:fresh --seed`). Регресс: firstOrCreate без password в defaults вставлял
 * строку без пароля → «NOT NULL constraint failed: users.password» (MySQL в
 * нестрогом режиме маскировал это пустой строкой).
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeder_creates_super_admin_with_working_password(): void
    {
        config([
            'services.admin.email' => 'seed-admin@example.com',
            'services.admin.password' => 'secret-pass-123',
        ]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'seed-admin@example.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame(Roles::SUPER_ADMIN, $admin->role);
        $this->assertTrue(Hash::check('secret-pass-123', $admin->password));
    }

    /** @test */
    public function seeder_is_idempotent_and_resyncs_password(): void
    {
        config([
            'services.admin.email' => 'seed-admin@example.com',
            'services.admin.password' => 'first-pass-123',
        ]);
        $this->seed(DatabaseSeeder::class);

        // Повторный прогон с новым паролем — без дубля, пароль пере-синхронизирован.
        config(['services.admin.password' => 'second-pass-456']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'seed-admin@example.com')->count());
        $admin = User::where('email', 'seed-admin@example.com')->first();
        $this->assertTrue(Hash::check('second-pass-456', $admin->password));
    }
}
