<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFavorite;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5134 — «Избранное» (сердечки): auth-gate, идемпотентный toggle, счётчики,
 * кабинет-список, флаг OFF = 404 и кнопки скрыты. Сиды — реальные слаги:
 * kosmografiya (waitlist без карточки курса), grammatika-gasuns-2026 (курс).
 */
class CourseFavoritesTest extends TestCase
{
    use RefreshDatabase;

    private const COURSE_SLUG = 'grammatika-gasuns-2026';

    private const WAITLIST_SLUG = 'kosmografiya';

    private function course(): Course
    {
        return Course::create([
            'title' => 'Грамматика Гасунса 2026',
            'slug' => self::COURSE_SLUG,
            'is_visible' => true,
        ]);
    }

    private function waitlistItem(): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => self::WAITLIST_SLUG,
            'course_title' => 'Космография',
            'teacher_name' => 'Марцис Гасунс',
            'min_payers' => 8,
            'kind' => 'other',
        ]);
    }

    // ================= Флаг OFF: механизм не живёт =================

    public function test_flag_off_toggle_is_404(): void
    {
        config(['features.course_favorites' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['course_id' => 1])
            ->assertStatus(404);
    }

    public function test_flag_off_index_is_404(): void
    {
        config(['features.course_favorites' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/favorites')
            ->assertStatus(404);
    }

    public function test_flag_off_hearts_are_hidden_on_surfaces(): void
    {
        config(['features.course_favorites' => false]);
        config(['features.waitlist_voting' => true]);
        $this->waitlistItem();

        $this->get(route('shop.waitlist'))
            ->assertOk()
            ->assertDontSee('data-favorite');
    }

    // ================= Auth-gate: гость — 401 =================

    public function test_guest_toggle_is_401(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();

        $this->postJson('/favorites/toggle', ['course_id' => $course->id])
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'auth_required']);

        $this->assertSame(0, CourseFavorite::count());
    }

    public function test_guest_index_is_401(): void
    {
        config(['features.course_favorites' => true]);

        $this->getJson('/favorites')
            ->assertStatus(401);
    }

    // ================= Toggle: идемпотентность и валидация =================

    public function test_toggle_on_course_is_idempotent(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['course_id' => $course->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'favorited' => true]);
        $this->assertSame(1, CourseFavorite::count());

        // Повтор — снимает сердечко (не дублирует и не ошибка).
        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['course_id' => $course->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'favorited' => false]);
        $this->assertSame(0, CourseFavorite::count());
    }

    public function test_toggle_on_waitlist_card_without_course(): void
    {
        config(['features.course_favorites' => true]);
        $item = $this->waitlistItem();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['waitlist_slug' => self::WAITLIST_SLUG])
            ->assertOk()
            ->assertJson(['ok' => true, 'favorited' => true]);

        $this->assertSame(1, CourseFavorite::query()->where('waitlist_slug', self::WAITLIST_SLUG)->count());
        $this->assertSame(1, $item->fresh()->heartsCount());
    }

    public function test_toggle_requires_exactly_one_target(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();
        $this->waitlistItem();
        $user = User::factory()->create();

        // Ни цели.
        $this->actingAs($user)
            ->postJson('/favorites/toggle', [])
            ->assertStatus(422);

        // Обе цели сразу.
        $this->actingAs($user)
            ->postJson('/favorites/toggle', [
                'course_id' => $course->id,
                'waitlist_slug' => self::WAITLIST_SLUG,
            ])
            ->assertStatus(422);
    }

    public function test_toggle_rejects_unknown_waitlist_slug(): void
    {
        config(['features.course_favorites' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['waitlist_slug' => 'net-takogo-anonsa'])
            ->assertStatus(422);
    }

    public function test_toggle_never_touches_waitlist_votes(): void
    {
        config(['features.course_favorites' => true]);
        $item = $this->waitlistItem();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/favorites/toggle', ['waitlist_slug' => self::WAITLIST_SLUG])
            ->assertOk();

        // Сердечко — отдельный сигнал: голосов ждуна ноль.
        $this->assertSame(0, WaitlistVote::count());
        $this->assertSame(0, $item->fresh()->votesCount());
    }

    // ================= Мой список (GET /favorites) =================

    public function test_index_returns_my_list(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();
        $this->waitlistItem();
        $user = User::factory()->create();

        CourseFavorite::create(['user_id' => $user->id, 'course_id' => $course->id]);
        CourseFavorite::create(['user_id' => $user->id, 'waitlist_slug' => self::WAITLIST_SLUG]);

        $resp = $this->actingAs($user)->getJson('/favorites');
        $resp->assertOk()->assertJson(['ok' => true]);

        $keys = collect($resp->json('data'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['c:'.$course->id, 'w:'.self::WAITLIST_SLUG],
            $keys,
        );

        // Waitlist-сердечко резолвит человекочитаемый заголовок.
        $waitlistRow = collect($resp->json('data'))->firstWhere('waitlist_slug', self::WAITLIST_SLUG);
        $this->assertSame('Космография', $waitlistRow['title']);
    }

    // ================= Поверхности: ждун, /k/, каталог, кабинет =================

    public function test_zhdun_page_renders_hearts_with_state(): void
    {
        config(['features.course_favorites' => true]);
        config(['features.waitlist_voting' => true]);
        $item = $this->waitlistItem();
        $user = User::factory()->create();

        CourseFavorite::create(['user_id' => $user->id, 'waitlist_slug' => self::WAITLIST_SLUG]);

        $resp = $this->actingAs($user)->get(route('shop.waitlist'));
        $resp->assertOk();
        $resp->assertSee('data-favorite="w:'.self::WAITLIST_SLUG.'"', false);
        // Отмеченное состояние: начальный Alpine-state активен на моей карточке.
        $resp->assertSee('active: true', false);
    }

    public function test_course_page_renders_heart(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();

        $this->get(route('shop.course.show', self::COURSE_SLUG))
            ->assertOk()
            ->assertSee('data-favorite="c:'.$course->id.'"', false);
    }

    public function test_catalog_renders_heart_on_course_card(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('data-favorite="c:'.$course->id.'"', false);
    }

    public function test_dashboard_renders_favorites_section(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();
        $user = User::factory()->create();

        CourseFavorite::create(['user_id' => $user->id, 'course_id' => $course->id]);

        $resp = $this->actingAs($user)->get(route('student.dashboard'));
        $resp->assertOk();
        $resp->assertSee('Избранное');
        $resp->assertSee('Грамматика Гасунса 2026');
    }

    public function test_dashboard_without_favorites_renders_no_section(): void
    {
        config(['features.course_favorites' => true]);
        $this->course();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Избранное');
    }

    public function test_dashboard_flag_off_is_byte_stable(): void
    {
        config(['features.course_favorites' => false]);
        $course = $this->course();
        $user = User::factory()->create();
        CourseFavorite::create(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Избранное');
    }

    // ================= Счётчики =================

    public function test_course_hearts_count_relation(): void
    {
        config(['features.course_favorites' => true]);
        $course = $this->course();
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            CourseFavorite::create(['user_id' => $user->id, 'course_id' => $course->id]);
        }

        $this->assertSame(3, $course->favorites()->count());
        $this->assertSame(3, Course::query()->withCount('favorites')->find($course->id)->favorites_count);
    }
}
