<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Intake;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Импортёр таблицы набора: матч по заголовкам (произвольный порядок колонок),
 * авто-линк возвратного студента по @username, идемпотентность повторного прогона.
 */
class ImportWaitlistSheetTest extends TestCase
{
    use RefreshDatabase;

    private function writeCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wl_').'.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_imports_rows_and_autolinks_returning_students(): void
    {
        // Возвратный студент, которого импорт должен сматчить по хэндлу.
        $student = User::factory()->create(['telegram_username' => 'anna_i']);
        Intake::create(['label' => 'Июль 2026', 'target_year' => 2026, 'target_month' => 7]);

        $csv = $this->writeCsv(<<<'CSV'
        Имя,Никнейм в тукане,Набор,Время и дни,Уровень,Примечания
        Анна Иванова,@anna_i,Июль 2026,Суббота 9:00,Новичок,просила напомнить
        Пётр Смирнов,petr_s,Июль 2026,Воскресенье,Продолжающий,возврат
        Мария,,Июль 2026,будни утро,Новичок,только телефон
        CSV);

        $this->artisan('waitlist:import', ['path' => $csv])
            ->assertSuccessful();

        $this->assertSame(3, WaitlistEntry::count());

        $anna = WaitlistEntry::where('name', 'Анна Иванова')->first();
        $this->assertNotNull($anna);
        $this->assertSame($student->id, $anna->user_id, 'Возвратный студент сматчен по хэндлу.');
        $this->assertSame('novice', $anna->level);
        $this->assertNotNull($anna->intake_id);

        $petr = WaitlistEntry::where('name', 'Пётр Смирнов')->first();
        $this->assertSame('continuing', $petr->level);
        $this->assertNull($petr->user_id, 'Нет аккаунта с таким хэндлом — остаётся несматченным.');

        @unlink($csv);
    }

    public function test_reimport_updates_instead_of_duplicating(): void
    {
        Intake::create(['label' => 'Июль 2026', 'target_year' => 2026, 'target_month' => 7]);

        $csv = $this->writeCsv(<<<'CSV'
        Имя,Никнейм в тукане,Набор,Уровень
        Анна Иванова,@anna_i,Июль 2026,Новичок
        CSV);

        $this->artisan('waitlist:import', ['path' => $csv])->assertSuccessful();
        $this->artisan('waitlist:import', ['path' => $csv])->assertSuccessful();

        $this->assertSame(1, WaitlistEntry::where('telegram_username', 'anna_i')->count(),
            'Повторный прогон обновляет строку по хэндлу, а не дублирует.');

        @unlink($csv);
    }

    public function test_column_order_is_resolved_by_header_names(): void
    {
        $csv = $this->writeCsv(<<<'CSV'
        Уровень,Примечания,Имя,Никнейм в тукане
        Продолжающий,заметка,Смешанный Порядок,@mixed
        CSV);

        $this->artisan('waitlist:import', ['path' => $csv])->assertSuccessful();

        $entry = WaitlistEntry::where('name', 'Смешанный Порядок')->first();
        $this->assertNotNull($entry, 'Колонки матчатся по имени заголовка, а не позиции.');
        $this->assertSame('mixed', $entry->telegram_username);
        $this->assertSame('continuing', $entry->level);

        @unlink($csv);
    }
}
