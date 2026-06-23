<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Payment::grantAccess() — функция, которая реально открывает уроки (через
 * группы курса). Её защитные ветки (нет групп / нет связи) до сих пор
 * проверялись только косвенно; здесь они зафиксированы явно.
 */
class GrantAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function grant_access_adds_course_groups_without_detaching_existing(): void
    {
        $user = User::factory()->create();

        // Не относящаяся к курсу группа, в которой студент уже состоит.
        $unrelated = Group::create(['name' => 'Unrelated']);
        $user->groups()->attach($unrelated->id);

        $course = Course::factory()->create();
        $courseGroup = Group::create(['name' => 'CourseGroup']);
        $course->groups()->attach($courseGroup->id);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $groupIds = $user->fresh()->groups->pluck('id')->all();
        $this->assertContains($courseGroup->id, $groupIds, 'Группа курса должна быть выдана.');
        $this->assertContains($unrelated->id, $groupIds, 'syncWithoutDetaching не должен отвязывать прежние группы.');
    }

    /** @test */
    public function grant_access_is_a_noop_when_course_has_no_groups(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(); // курс без привязанных групп

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertTrue(
            $user->fresh()->groups->isEmpty(),
            'Без привязанных групп доступ через группы не выдаётся, но и ошибки быть не должно.'
        );
    }
}
