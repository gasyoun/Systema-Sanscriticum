<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\IntakeResource\Pages\CreateIntake;
use App\Filament\Resources\IntakeResource\Pages\ListIntakes;
use App\Filament\Resources\WaitlistEntryResource\Pages\CreateWaitlistEntry;
use App\Filament\Resources\WaitlistEntryResource\Pages\ListWaitlistEntries;
use App\Models\Course;
use App\Models\Group;
use App\Models\Intake;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke-тесты Filament модуля набора: доска (группировка по Intake, выведенные
 * колонки надёжности/блока отвала) и формы строятся без рантайм-ошибок.
 */
class WaitlistFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    public function test_intake_pages_build(): void
    {
        Livewire::actingAs($this->admin())->test(ListIntakes::class)->assertOk();
        Livewire::actingAs($this->admin())->test(CreateIntake::class)->assertOk()->assertFormExists();
    }

    public function test_waitlist_board_renders_with_derived_columns(): void
    {
        // Возвратный студент с отвалом после блока 3 — прогоняем через выведенные
        // колонки надёжности/блока/прежних групп при рендере доски.
        $student = User::factory()->create(['telegram_username' => 'boardtest']);
        $course = Course::factory()->create();
        $student->courses()->attach($course->id, ['status' => 'Покинул', 'left_after_block' => 3]);
        $group = Group::create(['name' => 'Поток 7']);
        $student->groups()->attach($group->id, ['left_at' => now()->subMonth()]);

        $intake = Intake::create(['label' => 'Июль 2026', 'target_year' => 2026, 'target_month' => 7]);
        WaitlistEntry::create(['name' => 'На доске', 'telegram_username' => 'boardtest', 'intake_id' => $intake->id]);
        WaitlistEntry::create(['name' => 'Без набора', 'telegram_username' => 'orphan_handle']);

        Livewire::actingAs($this->admin())
            ->test(ListWaitlistEntries::class)
            ->assertOk()
            ->assertCanSeeTableRecords(WaitlistEntry::all());
    }

    public function test_waitlist_create_form_builds(): void
    {
        Livewire::actingAs($this->admin())->test(CreateWaitlistEntry::class)->assertOk()->assertFormExists();
    }
}
