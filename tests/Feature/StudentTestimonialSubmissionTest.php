<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TestimonialResource;
use App\Filament\Resources\TestimonialResource\Pages\ListTestimonials;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Студент сам оставляет отзыв (/dvaram/otzyv) → модерация → общий пул.
 * Главное: до «Одобрить» отзыв не виден ни на входе, ни на /otzyvy
 * (у колонки is_visible DEFAULT true — создавать скрытым обязан контроллер).
 */
class StudentTestimonialSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.student_testimonials' => true,
            'queue.default' => 'sync',
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'author_name' => 'Анна К.',
            'city' => 'Казань',
            'body' => 'Два года учу санскрит в школе, грамматика наконец сложилась в систему.',
            'rating' => 5,
            'consent' => '1',
        ], $overrides);
    }

    private function submit(User $student, array $overrides = [])
    {
        return $this->actingAs($student)->post(route('student.testimonial.store'), $this->payload($overrides));
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/dvaram/otzyv')->assertRedirect(route('login'));
        $this->post('/dvaram/otzyv', $this->payload())->assertRedirect(route('login'));
        $this->assertSame(0, Testimonial::count());
    }

    public function test_flag_off_gives_404(): void
    {
        config(['features.student_testimonials' => false]);
        $student = User::factory()->create();

        $this->actingAs($student)->get('/dvaram/otzyv')->assertNotFound();
        $this->submit($student)->assertNotFound();
    }

    public function test_form_is_prefilled_with_profile_name(): void
    {
        $student = User::factory()->create(['name' => 'Анна Каренина']);

        $this->actingAs($student)->get('/dvaram/otzyv')
            ->assertOk()
            ->assertSee('value="Анна Каренина"', false)
            ->assertSee('Разрешаю школе опубликовать');
    }

    public function test_submission_is_stored_hidden_and_pending_and_admins_are_notified(): void
    {
        $student = User::factory()->create();

        $this->submit($student)->assertRedirect(route('student.testimonial.create'))
            ->assertSessionHas('status');

        $t = Testimonial::sole();
        $this->assertSame(Testimonial::STATUS_PENDING, $t->moderation_status);
        $this->assertFalse($t->is_visible);
        $this->assertFalse($t->show_on_login);
        $this->assertFalse($t->is_featured);
        $this->assertSame($student->id, $t->user_id);
        $this->assertNotNull($t->publish_consent_at);
        $this->assertNotNull($t->submitted_at);
        $this->assertSame('Анна К.', $t->author_name);
        $this->assertSame(5, $t->rating);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && $request['chat_id'] === '111'
            && str_contains((string) $request['text'], 'Новый отзыв на модерации')
            && str_contains((string) $request['text'], 'Анна К.'));
    }

    public function test_telegram_failure_does_not_lose_the_testimonial(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $student = User::factory()->create();

        $this->submit($student)->assertRedirect(route('student.testimonial.create'));

        $this->assertSame(1, Testimonial::count());
    }

    public function test_consent_is_required(): void
    {
        $student = User::factory()->create();

        $this->submit($student, ['consent' => null])->assertSessionHasErrors('consent');
        $this->assertSame(0, Testimonial::count());
    }

    public function test_too_short_body_is_rejected(): void
    {
        $student = User::factory()->create();

        $this->submit($student, ['body' => 'Хорошо'])->assertSessionHasErrors('body');
        $this->assertSame(0, Testimonial::count());
    }

    public function test_html_is_stripped(): void
    {
        $student = User::factory()->create();

        $this->submit($student, ['body' => '<script>alert(1)</script>Очень полезный курс, спасибо преподавателям за терпение!']);

        $this->assertStringNotContainsString('<script>', Testimonial::sole()->body);
    }

    public function test_second_submission_while_pending_is_not_accepted(): void
    {
        $student = User::factory()->create();
        $this->submit($student);

        $this->actingAs($student)->get('/dvaram/otzyv')
            ->assertOk()
            ->assertSee('Ваш отзыв на проверке')
            ->assertDontSee('Отправить отзыв');

        $this->submit($student, ['body' => 'Второй отзыв, который не должен сохраниться в базе.']);
        $this->assertSame(1, Testimonial::count());
    }

    public function test_pending_is_invisible_until_approved_then_joins_the_pool(): void
    {
        foreach (['Борис Первый', 'Вера Вторая'] as $name) {
            Testimonial::create(['author_name' => $name, 'body' => 'Старый отзыв из админки.']);
        }
        $student = User::factory()->create();
        $this->submit($student, ['author_name' => 'Студентка Новая']);
        $t = Testimonial::where('author_name', 'Студентка Новая')->sole();

        $this->get('/otzyvy')->assertOk()->assertDontSee('Студентка Новая');
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertDontSee('Студентка Новая');

        $t->approve();

        $this->assertSame(Testimonial::STATUS_APPROVED, $t->fresh()->moderation_status);
        $this->assertSame($t->submitted_at->toDateString(), $t->fresh()->reviewed_at->toDateString());
        $this->get('/otzyvy')->assertOk()->assertSee('Студентка Новая');
        $this->get(route('login'))->assertOk()->assertSee('Студентка Новая');
    }

    public function test_rejected_stays_hidden_and_student_can_write_again(): void
    {
        $student = User::factory()->create();
        $this->submit($student, ['author_name' => 'Отклонённый Автор']);
        Testimonial::sole()->reject();

        $this->get('/otzyvy')->assertDontSee('Отклонённый Автор');
        $this->actingAs($student)->get('/dvaram/otzyv')->assertSee('Отправить отзыв');
    }

    public function test_admin_created_testimonials_default_to_approved(): void
    {
        $t = Testimonial::create(['author_name' => 'Админский', 'body' => 'Текст.']);

        $this->assertSame(Testimonial::STATUS_APPROVED, $t->fresh()->moderation_status);
    }

    public function test_turning_visible_in_the_form_counts_as_approval(): void
    {
        $student = User::factory()->create();
        $this->submit($student);
        $t = Testimonial::sole();

        $t->update(['is_visible' => true]);

        $this->assertSame(Testimonial::STATUS_APPROVED, $t->fresh()->moderation_status);
    }

    public function test_admin_badge_counts_pending_and_approve_action_publishes(): void
    {
        $student = User::factory()->create();
        $this->submit($student);
        $t = Testimonial::sole();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $this->assertSame('1', TestimonialResource::getNavigationBadge());

        Livewire::test(ListTestimonials::class)
            ->callTableAction('approve', $t)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($t->fresh()->is_visible);
        $this->assertTrue($t->fresh()->show_on_login);
        $this->assertNull(TestimonialResource::getNavigationBadge());
    }
}
