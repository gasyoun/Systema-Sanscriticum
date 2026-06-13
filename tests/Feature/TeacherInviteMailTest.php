<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Filament\Resources\TeacherResource\Pages\EditTeacher;
use App\Filament\Resources\TeacherResource\Pages\ListTeachers;
use App\Mail\TeacherInviteMail;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherAccountService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherInviteMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
    }

    /** @test */
    public function creating_a_teacher_sends_invite_with_login_and_password(): void
    {
        Mail::fake();

        Livewire::test(CreateTeacher::class)
            ->fillForm([
                'name' => 'Новый Препод',
                'email' => 'new-teacher@example.test',
                'account_password' => 'secret123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'new-teacher@example.test',
            'role' => Roles::TEACHER,
        ]);

        Mail::assertQueued(
            TeacherInviteMail::class,
            fn (TeacherInviteMail $mail): bool => $mail->hasTo('new-teacher@example.test')
                && $mail->password === 'secret123'
        );
    }

    /** @test */
    public function editing_a_teacher_without_password_does_not_send_invite(): void
    {
        // Аккаунт уже существует — правка карточки не должна слать письмо.
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'exist@example.test']);
        User::factory()->create([
            'email' => 'exist@example.test',
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);

        Mail::fake();

        Livewire::test(EditTeacher::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['name' => 'Препод Изменённый'])
            ->call('save')
            ->assertHasNoFormErrors();

        Mail::assertNotQueued(TeacherInviteMail::class);
    }

    /** @test */
    public function resetting_password_on_edit_sends_invite_with_new_password(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'reset@example.test']);
        User::factory()->create([
            'email' => 'reset@example.test',
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);

        Mail::fake();

        Livewire::test(EditTeacher::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['account_password' => 'brandnew9'])
            ->call('save')
            ->assertHasNoFormErrors();

        Mail::assertQueued(
            TeacherInviteMail::class,
            fn (TeacherInviteMail $mail): bool => $mail->hasTo('reset@example.test')
                && $mail->password === 'brandnew9'
        );
    }

    /** @test */
    public function service_provisions_account_for_legacy_teacher_without_user(): void
    {
        // Старая карточка: есть email, но связанного User ещё нет.
        $teacher = Teacher::create(['name' => 'Старый Препод', 'email' => 'legacy@example.test']);

        Mail::fake();

        $user = app(TeacherAccountService::class)->resetPasswordAndInvite($teacher);

        $this->assertNotNull($user);
        $this->assertDatabaseHas('users', [
            'email' => 'legacy@example.test',
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);

        Mail::assertQueued(
            TeacherInviteMail::class,
            fn (TeacherInviteMail $mail): bool => $mail->hasTo('legacy@example.test')
                && filled($mail->password)
        );
    }

    /** @test */
    public function service_skips_teacher_without_email(): void
    {
        $teacher = Teacher::create(['name' => 'Без почты']);

        Mail::fake();

        $user = app(TeacherAccountService::class)->resetPasswordAndInvite($teacher);

        $this->assertNull($user);
        Mail::assertNotQueued(TeacherInviteMail::class);
    }

    /** @test */
    public function table_invite_action_sends_invitation(): void
    {
        $teacher = Teacher::create(['name' => 'Кнопочный', 'email' => 'btn@example.test']);

        Mail::fake();

        Livewire::test(ListTeachers::class)
            ->callTableAction('invite', $teacher);

        Mail::assertQueued(
            TeacherInviteMail::class,
            fn (TeacherInviteMail $mail): bool => $mail->hasTo('btn@example.test')
        );
    }

    /** @test */
    public function table_bulk_invite_sends_to_all_selected(): void
    {
        $a = Teacher::create(['name' => 'Препод A', 'email' => 'bulk-a@example.test']);
        $b = Teacher::create(['name' => 'Препод B', 'email' => 'bulk-b@example.test']);

        Mail::fake();

        Livewire::test(ListTeachers::class)
            ->callTableBulkAction('invite', [$a->getKey(), $b->getKey()]);

        Mail::assertQueued(TeacherInviteMail::class, 2);
    }
}
