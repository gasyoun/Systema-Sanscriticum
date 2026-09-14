<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Support\TrialBookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicScheduleFeedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/schedule';

    /**
     * Собирает граф Teacher → Course(+Category) → Group → Schedule и возвращает
     * созданные модели. Schedule создаётся вручную — ScheduleFactory в репозитории нет.
     *
     * @return array{course: Course, group: Group, schedule: Schedule}
     */
    private function makeSession(
        string $courseTitle,
        Teacher $teacher,
        Category $category,
        ?int $minSize = null,
        int $activeUsers = 0,
        string $status = 'forming',
    ): array {
        $course = Course::factory()->create([
            'title' => $courseTitle,
            'teacher_id' => $teacher->id,
            'is_visible' => true,
        ]);
        $course->categories()->attach($category->id);

        $group = Group::factory()->create([
            'status' => $status,
            'min_size' => $minSize,
        ]);
        $group->courses()->attach($course->id);

        for ($i = 0; $i < $activeUsers; $i++) {
            $group->users()->attach(User::factory()->create()->id, ['left_at' => null]);
        }

        $schedule = Schedule::create([
            'title' => $courseTitle.' — занятие',
            'group_id' => $group->id,
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(18, 0),
            'end' => now()->addDays(2)->setTime(20, 0),
        ]);

        return ['course' => $course, 'group' => $group, 'schedule' => $schedule];
    }

    /**
     * Рекурсивно собирает все строковые ключи из декодированного ответа.
     *
     * @param  mixed  $data
     * @param  array<int, string>  $keys
     */
    private function collectKeys($data, array &$keys): void
    {
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $this->collectKeys($value, $keys);
        }
    }

    /**
     * ГРАНИЦА БЕЗОПАСНОСТИ (VERIFICATION §2) — этот тест НЕ должен регрессировать.
     * Секретные поля реально проставлены в БД, поэтому тест доказывает именно
     * фильтрацию allowlist'ом, а не случайное отсутствие из-за null.
     */
    public function test_feed_never_exposes_sensitive_fields(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Иван Петров']);
        $category = Category::factory()->create(['name' => 'Санскрит с нуля']);
        $made = $this->makeSession('Санскрит для начинающих', $teacher, $category);

        // Проставляем секреты в саму строку расписания (в обход $fillable).
        $made['schedule']->forceFill([
            'link' => 'https://zoom.us/j/SECRET-LINK',
            'zoom_join_url' => 'https://zoom.us/j/SECRET-JOIN',
            'zoom_start_url' => 'https://zoom.us/s/SECRET-HOST',
        ])->save();

        $response = $this->getJson(self::URL)->assertOk();

        // 1) Секретные значения не утекают нигде в теле ответа.
        $response->assertDontSee('SECRET-LINK');
        $response->assertDontSee('SECRET-JOIN');
        $response->assertDontSee('SECRET-HOST');

        // 2) Запрещённые КЛЮЧИ отсутствуют во всём дереве ответа.
        $keys = [];
        $this->collectKeys($response->json(), $keys);
        foreach (['link', 'zoom_join_url', 'zoom_start_url', 'zoom_meeting_id', 'schedule_id', 'group_id', 'course_id', 'teacher_id', 'user_id', 'id'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Публичный фид не должен отдавать ключ `{$forbidden}`.");
        }
    }

    public function test_feed_exposes_public_fields_including_group_recruitment(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Мария Иванова']);
        $category = Category::factory()->create(['name' => 'Ведийская литература', 'slug' => 'vedijskaya-literatura']);
        $made = $this->makeSession('Введение в Веды', $teacher, $category, minSize: 5, activeUsers: 2);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.0.course.slug', $made['course']->slug);
        $response->assertJsonPath('data.0.teacher', 'Мария Иванова');
        $response->assertJsonPath('data.0.directions.0.slug', 'vedijskaya-literatura');
        // min_size=5, активных пользователей 2 → группа ещё не набрана.
        $response->assertJsonPath('data.0.group.is_recruited', false);
        $response->assertJsonPath('data.0.group.status', 'forming');
        $response->assertJsonPath('data.0.group.seats_min', 5);
        // Ссылка на лендинг курса построена по слагу, без числовых id.
        $this->assertStringContainsString('/k/'.$made['course']->slug, $response->json('data.0.course.url'));
    }

    /** H3790: фид отдаёт каникулы группы (флаг + дата выхода), без PII. */
    public function test_feed_exposes_group_vacation_fields(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Мария Иванова']);
        $category = Category::factory()->create();
        $made = $this->makeSession('Курс каникул', $teacher, $category);

        $made['group']->update(['is_on_vacation' => true, 'vacation_resume_date' => '2026-09-14']);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.0.group.is_on_vacation', true);
        $response->assertJsonPath('data.0.group.vacation_resume_date', '2026-09-14');

        $made2 = $this->makeSession('Курс каникул без даты', $teacher, $category);
        $made2['group']->update(['is_on_vacation' => true]);

        Cache::flush(); // фид кэшируется на 5 минут — второй запрос должен видеть обе группы
        $response2 = $this->getJson(self::URL)->assertOk();
        $rows = collect($response2->json('data'));
        $row2 = $rows->first(fn ($r) => ($r['course']['title'] ?? '') === 'Курс каникул без даты');
        $this->assertNotNull($row2);
        $this->assertTrue($row2['group']['is_on_vacation']);
        $this->assertNull($row2['group']['vacation_resume_date']);
    }

    public function test_direction_filter_returns_only_matching_subset(): void
    {
        $teacherA = Teacher::factory()->create(['name' => 'Препод А']);
        $teacherB = Teacher::factory()->create(['name' => 'Препод Б']);
        $catA = Category::factory()->create(['name' => 'Грамматика', 'slug' => 'grammatika']);
        $catB = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);

        $a = $this->makeSession('Курс А', $teacherA, $catA);
        $this->makeSession('Курс Б', $teacherB, $catB);

        $response = $this->getJson(self::URL.'?direction=grammatika')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course.slug', $a['course']->slug);
    }

    public function test_teacher_filter_returns_only_matching_subset(): void
    {
        $teacherA = Teacher::factory()->create(['name' => 'Анна Смирнова']);
        $teacherB = Teacher::factory()->create(['name' => 'Борис Кузнецов']);
        $catA = Category::factory()->create(['name' => 'Грамматика', 'slug' => 'grammatika-2']);
        $catB = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi-2']);

        $this->makeSession('Курс А', $teacherA, $catA);
        $b = $this->makeSession('Курс Б', $teacherB, $catB);

        $response = $this->getJson(self::URL.'?teacher='.urlencode('Борис Кузнецов'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course.slug', $b['course']->slug);
    }

    public function test_second_identical_request_is_served_from_cache(): void
    {
        $teacher = Teacher::factory()->create();
        $category = Category::factory()->create();
        $this->makeSession('Кэшируемый курс', $teacher, $category, minSize: 3, activeUsers: 1);

        // Первый запрос прогревает кэш.
        $this->getJson(self::URL)->assertOk();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Второй идентичный запрос в пределах TTL должен обслуживаться из кэша.
        $this->getJson(self::URL)->assertOk();

        $this->assertSame(0, $queryCount, 'Повторный идентичный запрос не должен обращаться к БД (кэш 5 мин).');
    }

    public function test_throttle_engages_after_thirty_requests(): void
    {
        $teacher = Teacher::factory()->create();
        $category = Category::factory()->create();
        $this->makeSession('Троттлинг', $teacher, $category);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson(self::URL)->assertOk();
        }

        $this->getJson(self::URL)->assertStatus(429);
    }

    /**
     * H3248 (VERIFICATION C2): при дефолтных флагах booking-поверхности в фиде нет.
     */
    public function test_default_flags_expose_no_booking_surface(): void
    {
        $teacher = Teacher::factory()->create();
        $category = Category::factory()->create();
        $made = $this->makeSession('Обычный курс', $teacher, $category);
        $made['course']->update(['trial_schedule_id' => $made['schedule']->id]);

        $response = $this->getJson(self::URL)->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['bookable']);
            $this->assertArrayNotHasKey('book_token', $row);
        }

        $keys = [];
        $this->collectKeys($response->json(), $keys);
        $this->assertNotContains('book_token', $keys);
    }

    /**
     * H3248 (VERIFICATION C2): флаг виджета ON — токен только у пробной строки,
     * обычные строки остаются bookable=false и без токена.
     */
    public function test_widget_flag_exposes_token_only_on_trial_row(): void
    {
        config(['features.crm_trial_widget_public' => true]);

        $teacher = Teacher::factory()->create();
        $category = Category::factory()->create();

        // Обычная группа 18:00 + отдельная пробная строка того же курса 20:00.
        $made = $this->makeSession('Курс с пробником', $teacher, $category);
        $trial = Schedule::create([
            'title' => 'Пробное — вводное',
            'course_id' => $made['course']->id,
            'start' => now()->addDays(2)->setTime(20, 0),
            'end' => now()->addDays(2)->setTime(21, 30),
        ]);
        $made['course']->update(['trial_schedule_id' => $trial->id]);

        $rows = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertCount(2, $rows);

        $regular = collect($rows)->firstWhere('time', '18:00');
        $bookableRow = collect($rows)->firstWhere('time', '20:00');

        $this->assertFalse($regular['bookable']);
        $this->assertArrayNotHasKey('book_token', $regular);

        $this->assertTrue($bookableRow['bookable']);
        $this->assertMatchesRegularExpression('/^\d+\.[A-Za-z0-9_-]+$/', $bookableRow['book_token']);

        // Токен разворачивается ровно в пробную строку (числового id в фиде нет).
        $resolved = TrialBookToken::resolve($bookableRow['book_token']);
        $this->assertSame($trial->getKey(), $resolved);

        // При включённом флаге allowlist-граница не ослабла: запрещённых ключей по-прежнему нет.
        $keys = [];
        $this->collectKeys($rows, $keys);
        foreach (['link', 'zoom_join_url', 'zoom_start_url', 'zoom_meeting_id', 'schedule_id', 'group_id', 'course_id', 'teacher_id', 'user_id', 'id'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Фид с флагом виджета не должен отдавать ключ `{$forbidden}`.");
        }
    }
}
