<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Гвард leads.email (MG 04-10-2026, «да» на распространение users-гварда):
 * CHECK leads_email_valid ловит сырые SQL-вставки мусора; NULL — честное
 * «email нет» (лид с контактом-телефоном/хэндлом). Регрессия: «ahyg13@gma».
 */
class LeadsEmailIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_check_constraint_rejects_raw_junk_insert(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK leads_email_valid ставится только на MySQL/MariaDB (прод).');
        }

        $this->expectException(QueryException::class);

        DB::table('leads')->insert([
            'name' => 'Мусорный лид',
            'contact' => '+7(953)2126423',
            'email' => 'ahyg13@gma',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    public function test_public_lead_form_rejects_dotless_email_with_422_not_500(): void
    {
        // P2 независимого ревью: RFC-правило `email` пропускает домены без
        // точки, CHECK их режет — без HouseEmail форма отдавала 500 и теряла лид.
        $response = $this->post('/leads/store', [
            'contact' => '+7(953)2126423',
            'email' => 'ahyg13@gma',
            'name' => 'Дотлесс',
        ]);

        $this->assertSame(302, $response->status()); // back() с ошибками валидации
        $response->assertSessionHasErrors('email');
        $this->assertSame(0, DB::table('leads')->where('email', 'ahyg13@gma')->count());
    }

    public function test_lead_mutator_throws_on_non_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new \App\Models\Lead)->forceFill(['email' => 'ahyg13@gma']);
    }


    public function test_db_accepts_null_and_valid_emails(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Enforcing-слой только на MySQL/MariaDB (прод).');
        }

        DB::table('leads')->insert([
            ['name' => 'Без email', 'contact' => '@handle', 'email' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Валидный', 'contact' => '+79990001122', 'email' => 'lead@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(2, DB::table('leads')->whereIn('name', ['Без email', 'Валидный'])->count());
    }
}
