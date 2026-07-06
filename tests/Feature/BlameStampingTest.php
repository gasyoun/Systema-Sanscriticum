<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BlameStampingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function creating_stamps_created_and_updated_by_from_auth(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $lead = Lead::create(['name' => 'X', 'contact' => '+70000000000', 'status' => 'new']);

        $this->assertSame($admin->id, $lead->created_by_user_id);
        $this->assertSame($admin->id, $lead->updated_by_user_id);
    }

    /** @test */
    public function updating_stamps_only_updated_by_and_keeps_creator(): void
    {
        $creator = User::factory()->create();
        $editor = User::factory()->create();

        $this->actingAs($creator);
        $lead = Lead::create(['name' => 'X', 'contact' => '+70000000000', 'status' => 'new']);

        $this->actingAs($editor);
        $lead->update(['status' => 'in_work']);

        $this->assertSame($creator->id, $lead->fresh()->created_by_user_id, 'creator должен сохраниться');
        $this->assertSame($editor->id, $lead->fresh()->updated_by_user_id, 'updated_by — последний редактор');
    }

    /** @test */
    public function system_context_leaves_blame_null(): void
    {
        // Без actingAs() — эмуляция webhook/CLI/импорта.
        $lead = Lead::create(['name' => 'X', 'contact' => '+70000000000', 'status' => 'new']);

        $this->assertNull($lead->created_by_user_id);
        $this->assertNull($lead->updated_by_user_id);
    }

    /** @test */
    public function payment_blame_does_not_break_or_double_fire_grant_access(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $student = User::factory()->create();
        $course = Course::factory()->create();

        // Оплаченный платёж должен ровно один раз пройти fireOnPaid → enrollInCourse.
        $payment = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Blame проставлен.
        $this->assertSame($admin->id, $payment->created_by_user_id);

        // fireOnPaid отработал ровно один раз: одна запись «Записался» в course_user.
        $this->assertSame(
            1,
            $student->courses()->where('courses.id', $course->id)->count(),
            'fireOnPaid не должен задваиваться из-за blame-хуков',
        );
    }
}
