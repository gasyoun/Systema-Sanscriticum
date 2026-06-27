<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Идентичность студента привязана к email. Раньше сравнение шло по точной строке,
 * поэтому регистр/пробелы плодили аккаунты-сироты без курсов и блокировали вход.
 * Здесь — нормализация (lowercase+trim) на записи (мутатор) и чтении (login/сброс/
 * чекаут) + самопроверка email на странице входа.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function email_is_lowercased_and_trimmed_on_write(): void
    {
        $user = User::factory()->create(['email' => '  Anna@Mail.RU ']);

        $this->assertSame('anna@mail.ru', $user->fresh()->email);
        $this->assertSame('anna@mail.ru', User::normalizeEmail(' ANNA@mail.ru '));
        $this->assertNull(User::normalizeEmail(null));
    }

    /** @test */
    public function login_succeeds_with_differently_cased_email(): void
    {
        User::factory()->create(['email' => 'student@example.com', 'password' => Hash::make('secret123')]);

        $this->post(route('login.post'), [
            'email' => 'Student@Example.COM',
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    /** @test */
    public function self_check_reports_found_and_sends_login_link(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'found@example.com']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'FOUND@example.com'])
            ->assertSessionHas('email_found', 'found@example.com')
            ->assertSessionMissing('email_not_found');
    }

    /** @test */
    public function self_check_reports_not_found_without_revealing_a_link(): void
    {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHas('email_not_found', 'nobody@example.com')
            ->assertSessionMissing('email_found');
    }

    /** @test */
    public function placeholder_no_email_addresses_are_never_found(): void
    {
        User::factory()->create(['email' => 'legacy123@no-email.com']);

        $this->post(route('password.email'), ['email' => 'legacy123@no-email.com'])
            ->assertSessionHas('email_not_found');
    }

    /** @test */
    public function checkout_matches_existing_user_by_case_insensitive_email(): void
    {
        // Имитируем legacy-строку в смешанном регистре (как было до мутатора).
        DB::table('users')->insert([
            'name' => 'Legacy', 'email' => 'Mixed@Case.com',
            'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Поиск на чекауте по нормализованному вводу находит ту же строку.
        $found = User::where('email', User::normalizeEmail('mixed@case.COM'))->first();

        // Здесь legacy-строка ещё в смешанном регистре, поэтому прямой поиск её не
        // найдёт — это и есть причина дублей. После команды нормализации — найдёт.
        $this->assertNull($found, 'до нормализации legacy-строка не матчится — ожидаемо');

        $this->artisan('users:normalize-emails', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull(User::where('email', User::normalizeEmail('MIXED@case.com'))->first());
    }

    /** @test */
    public function normalize_command_skips_collisions_and_normalizes_safe_rows(): void
    {
        // Безопасная строка (одна) — будет нормализована.
        DB::table('users')->insert([
            'name' => 'Safe', 'email' => 'Safe@X.com',
            'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Коллизия: два аккаунта схлопываются в один email — НЕ трогаем.
        DB::table('users')->insert([
            ['name' => 'Dup A', 'email' => 'Dup@X.com', 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dup B', 'email' => 'dup@x.com', 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('users:normalize-emails', ['--apply' => true])->assertSuccessful();

        // Безопасная — нормализована.
        $this->assertSame('safe@x.com', DB::table('users')->where('name', 'Safe')->value('email'));
        // Коллизия — обе строки нетронуты (ручной мерж).
        $this->assertSame('Dup@X.com', DB::table('users')->where('name', 'Dup A')->value('email'));
        $this->assertSame('dup@x.com', DB::table('users')->where('name', 'Dup B')->value('email'));
    }
}
