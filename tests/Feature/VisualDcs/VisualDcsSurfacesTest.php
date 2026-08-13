<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisualDcsSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/complete'));
    }

    private function paidUser(): User
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user->fresh();
    }

    public function test_one_flag_off_does_not_disable_the_others(): void
    {
        config([
            'features.visualdcs_verb' => true,
            'features.visualdcs_nominal' => false,
            'features.visualdcs_passage' => true,
        ]);
        $user = $this->paidUser();

        $this->actingAs($user)->get('/dvaram/visualdcs/verb')->assertOk();
        $this->actingAs($user)->get('/dvaram/visualdcs/nominal')->assertNotFound();
        $this->actingAs($user)->get('/dvaram/visualdcs/passage')->assertOk();
        $this->get('/visualdcs/nominal/preview')->assertNotFound();
        $this->get('/visualdcs/passage/preview')->assertOk();
    }

    public function test_complete_and_sparse_fixtures_render(): void
    {
        config(['features.visualdcs_verb' => true, 'features.visualdcs_nominal' => true, 'features.visualdcs_passage' => true]);
        $user = $this->paidUser();

        $this->actingAs($user)->get('/dvaram/visualdcs/verb')->assertOk()->assertSee('ah');
        $this->actingAs($user)->get('/dvaram/visualdcs/nominal')->assertOk()->assertSee('deva');
        $this->actingAs($user)->get('/dvaram/visualdcs/passage')->assertOk()->assertSee('Hitopadeśa');

        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/sparse'));
        $this->actingAs($user)->get('/dvaram/visualdcs/verb')->assertOk()->assertSee('abhibhañj');
        $this->actingAs($user)->get('/dvaram/visualdcs/passage')
            ->assertOk()
            ->assertSee('Manusmṛti')
            ->assertDontSee('Hitopadeśa');
    }

    public function test_hub_lists_only_enabled_surfaces(): void
    {
        config([
            'features.visualdcs_verb' => true,
            'features.visualdcs_nominal' => false,
            'features.visualdcs_passage' => false,
        ]);

        $this->actingAs($this->paidUser())
            ->get('/dvaram/visualdcs')
            ->assertOk()
            ->assertSee('Глагол')
            ->assertDontSee('>Имя<', false);
    }
}
