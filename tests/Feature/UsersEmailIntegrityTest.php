<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Защита users.email от мусора (MG 04-10-2026): мутатор громко отказывает
 * Eloquent-записи не-адреса, CHECK users_email_valid ловит сырые SQL-вставки.
 * Регрессия импорта 22-04 («alexander pavlov», «89384362599», «@handle»).
 */
class UsersEmailIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutator_throws_on_non_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new User)->forceFill(['email' => 'alexander pavlov']);
    }

    public function test_mutator_throws_on_phone_and_handle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new User)->forceFill(['email' => '89384362599']);

        $this->expectException(\InvalidArgumentException::class);
        (new User)->forceFill(['email' => '@nadiyoga_practice']);
    }

    public function test_mutator_normalizes_and_accepts_valid_and_placeholder(): void
    {
        $u = new User;
        $u->email = '  Viktoriya.Balzamova@Yandex.ru ';
        $this->assertSame('viktoriya.balzamova@yandex.ru', $u->email);

        $u = new User;
        $u->email = 'import-5871@no-email.com';
        $this->assertSame('import-5871@no-email.com', $u->email);

        $u = new User;
        $u->email = null;
        $this->assertNull($u->email);
    }

    public function test_db_check_constraint_rejects_raw_junk_insert(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK users_email_valid ставится только на MySQL/MariaDB (прод).');
        }

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Импорт-мусор',
            'email' => 'kostyantyn churikov',
            'password' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_db_accepts_valid_raw_insert(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Enforcing-слой только на MySQL/MariaDB (прод).');
        }

        DB::table('users')->insert([
            'name' => 'Валидный raw',
            'email' => 'raw-valid@example.com',
            'password' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', ['email' => 'raw-valid@example.com']);
    }
}
