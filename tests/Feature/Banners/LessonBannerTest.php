<?php

declare(strict_types=1);

namespace Tests\Feature\Banners;

use App\Models\Course;
use App\Models\Group;
use App\Models\LessonBanner;
use App\Models\LessonBannerTemplate;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\Banners\LessonBannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Плашки занятий: отрисовка по расписанию, имя файла для ZOOM 1.4, перерисовка
 * по render_hash, статусы пропуска и API для n8n.
 */
class LessonBannerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'banners-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 12:00:00');
        Storage::fake('public');
        Storage::fake('local');

        config([
            'features.lesson_banners' => true,
            'services.n8n.lesson_banners_secret' => self::SECRET,
            'lesson_banners.lead_days' => 7,
            // Шрифта из spec нет — рендер обязан уйти на запасной DejaVu, а не упасть.
            'lesson_banners.fonts_dir' => storage_path('framework/testing/no-fonts-here'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_flag_off_command_is_a_no_op(): void
    {
        config(['features.lesson_banners' => false]);
        [$course, $group] = $this->world();
        $this->template($course);
        $this->lesson($group, $course, '2026-09-24 19:00:00', 5);

        $this->artisan('lesson-banners:render')->assertSuccessful();

        $this->assertSame(0, LessonBanner::query()->count());
    }

    public function test_renders_jpeg_with_number_from_title_tag_and_utc_drive_filename(): void
    {
        [$course, $group] = $this->world();
        $template = $this->template($course);
        $lesson = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);

        $this->artisan('lesson-banners:render')->assertSuccessful();

        $banner = LessonBanner::query()->where('schedule_id', $lesson->id)->firstOrFail();
        $this->assertSame(LessonBanner::RENDERED, $banner->render_status);
        $this->assertSame(5, $banner->lesson_number);
        $this->assertSame('2026-09-24.jpg', $banner->drive_filename);
        $this->assertSame($template->id, $banner->template_id);
        Storage::disk('public')->assertExists($banner->image_path);

        $info = getimagesizefromstring(Storage::disk('public')->get($banner->image_path));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([800, 450], [$info[0], $info[1]]);

        // Текст реально нарисован: в прямоугольниках полей появились светлые
        // пиксели на тёмно-синем фоне.
        $this->assertGreaterThan(30, $this->lightPixels($banner, $template->field('number')));
        $this->assertGreaterThan(30, $this->lightPixels($banner, $template->field('date')));

        $texts = app(LessonBannerService::class)->texts($template, $lesson, 5);
        $this->assertSame(['date' => '24 сентября', 'number' => 'Занятие 5'], $texts);
    }

    public function test_lesson_after_midnight_moscow_gets_previous_utc_date_as_filename(): void
    {
        [$course, $group] = $this->world();
        $template = $this->template($course);
        $lesson = $this->lesson($group, $course, '2026-09-24 01:00:00', 7);

        $this->artisan('lesson-banners:render')->assertSuccessful();

        $banner = LessonBanner::query()->where('schedule_id', $lesson->id)->firstOrFail();
        // ZOOM 1.4 ищет start_time.split('T')[0] — UTC: 2026-09-23T22:00Z.
        $this->assertSame('2026-09-23.jpg', $banner->drive_filename);
        // А на самой плашке — московская дата.
        $this->assertSame('24 сентября', app(LessonBannerService::class)->texts($template, $lesson, 7)['date']);
    }

    public function test_second_run_is_idempotent_and_date_move_rerenders_and_resets_delivery(): void
    {
        [$course, $group] = $this->world();
        $this->template($course);
        $lesson = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);
        $service = app(LessonBannerService::class);

        $this->assertSame('rendered', $service->syncSchedule($lesson));
        $first = LessonBanner::query()->where('schedule_id', $lesson->id)->firstOrFail();
        $first->forceFill(['delivery_status' => LessonBanner::DELIVERED, 'delivered_at' => now(), 'drive_file_id' => 'drv-1'])->save();

        $this->assertSame('unchanged', $service->syncSchedule($lesson->fresh()));

        $lesson->update(['start' => '2026-09-25 19:00:00', 'end' => '2026-09-25 20:30:00']);
        $this->assertSame('rendered', $service->syncSchedule($lesson->fresh()));

        $moved = $first->fresh();
        $this->assertSame('2026-09-25.jpg', $moved->drive_filename);
        $this->assertNull($moved->delivery_status);
        $this->assertNull($moved->delivered_at);
        $this->assertNotSame($first->render_hash, $moved->render_hash);
        Storage::disk('public')->assertMissing($first->image_path);
        Storage::disk('public')->assertExists($moved->image_path);
    }

    public function test_new_template_version_rerenders(): void
    {
        [$course, $group] = $this->world();
        $template = $this->template($course);
        $lesson = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);
        $service = app(LessonBannerService::class);

        $service->syncSchedule($lesson);
        $template->update(['version' => 2]);

        $this->assertSame('rendered', $service->syncSchedule($lesson->fresh()));
    }

    public function test_skip_statuses_without_template_or_number(): void
    {
        [$course, $group] = $this->world();
        $noTemplate = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);

        $this->artisan('lesson-banners:render')->assertSuccessful();
        $this->assertSame(LessonBanner::NO_TEMPLATE, LessonBanner::query()->where('schedule_id', $noTemplate->id)->value('render_status'));

        $this->template($course);
        $noNumber = Schedule::create([
            'title' => 'Занятие без тега',
            'start' => '2026-09-26 19:00:00',
            'end' => '2026-09-26 20:30:00',
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);

        $this->artisan('lesson-banners:render')->assertSuccessful();
        $this->assertSame(LessonBanner::NO_NUMBER, LessonBanner::query()->where('schedule_id', $noNumber->id)->value('render_status'));
        $this->assertSame(LessonBanner::RENDERED, LessonBanner::query()->where('schedule_id', $noTemplate->id)->value('render_status'));
    }

    public function test_overview_lesson_gets_overview_text_instead_of_number(): void
    {
        [$course, $group] = $this->world();
        $template = $this->template($course);
        $overview = Schedule::create([
            'title' => 'Обзорное занятие',
            'start' => '2026-09-24 19:00:00',
            'end' => '2026-09-24 20:30:00',
            'group_id' => $group->id,
            'course_id' => $course->id,
            'is_overview' => true,
        ]);

        $this->assertSame('rendered', app(LessonBannerService::class)->syncSchedule($overview));
        $this->assertNull(LessonBanner::query()->where('schedule_id', $overview->id)->value('lesson_number'));
        $this->assertSame('Обзорное', app(LessonBannerService::class)->texts($template, $overview, null)['number']);
    }

    public function test_group_template_beats_course_template(): void
    {
        [$course, $group] = $this->world();
        $this->template($course);
        $forGroup = $this->template($course, $group);
        $lesson = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);

        app(LessonBannerService::class)->syncSchedule($lesson);

        $this->assertSame($forGroup->id, LessonBanner::query()->where('schedule_id', $lesson->id)->value('template_id'));
    }

    public function test_window_and_group_status_limit_what_is_rendered(): void
    {
        [$course, $group] = $this->world();
        $this->template($course);
        $this->lesson($group, $course, '2026-09-24 19:00:00', 5);
        $this->lesson($group, $course, '2026-10-10 19:00:00', 9);   // за окном 7 дней
        $this->lesson($group, $course, '2026-09-20 19:00:00', 4);   // прошло

        $archived = Group::create(['name' => 'Архив', 'status' => 'archived']);
        $course->groups()->attach($archived->id);
        $this->lesson($archived, $course, '2026-09-24 19:00:00', 5);

        $this->artisan('lesson-banners:render')->assertSuccessful();

        $this->assertSame(1, LessonBanner::query()->count());
    }

    public function test_api_is_404_with_flag_off_and_403_with_wrong_secret(): void
    {
        config(['features.lesson_banners' => false]);
        $this->getJson('/api/lesson-banners/due', ['X-Webhook-Secret' => self::SECRET])->assertNotFound();

        config(['features.lesson_banners' => true]);
        $this->getJson('/api/lesson-banners/due', ['X-Webhook-Secret' => 'wrong'])->assertForbidden();
        $this->getJson('/api/lesson-banners/due')->assertForbidden();
    }

    public function test_api_due_and_delivered_roundtrip(): void
    {
        [$course, $group] = $this->world();
        $this->template($course);
        $lesson = $this->lesson($group, $course, '2026-09-24 19:00:00', 5);
        $lesson->update(['zoom_meeting_id' => '81234567890']);
        app(LessonBannerService::class)->syncSchedule($lesson->fresh());
        $banner = LessonBanner::query()->where('schedule_id', $lesson->id)->firstOrFail();

        $due = $this->getJson('/api/lesson-banners/due', ['X-Webhook-Secret' => self::SECRET])->assertOk()->json('items');
        $this->assertCount(1, $due);
        $this->assertSame('81234567890', $due[0]['meeting_id']);
        $this->assertSame('2026-09-24.jpg', $due[0]['drive_filename']);
        $this->assertSame($banner->render_hash, $due[0]['render_hash']);
        $this->assertNotEmpty($due[0]['image_url']);

        // Отчёт по устаревшему хешу — отказ, статус не меняется.
        $this->postJson("/api/lesson-banners/{$banner->id}/delivered", [
            'status' => 'delivered', 'render_hash' => 'old', 'drive_file_id' => 'x',
        ], ['X-Webhook-Secret' => self::SECRET])->assertStatus(409);
        $this->assertNull($banner->fresh()->delivery_status);

        // Нет папки — остаётся в due на повтор.
        $this->postJson("/api/lesson-banners/{$banner->id}/delivered", [
            'status' => 'no_folder', 'render_hash' => $banner->render_hash,
        ], ['X-Webhook-Secret' => self::SECRET])->assertOk();
        $this->assertCount(1, $this->getJson('/api/lesson-banners/due', ['X-Webhook-Secret' => self::SECRET])->json('items'));

        $this->postJson("/api/lesson-banners/{$banner->id}/delivered", [
            'status' => 'delivered', 'render_hash' => $banner->render_hash, 'drive_file_id' => 'drv-42',
        ], ['X-Webhook-Secret' => self::SECRET])->assertOk();

        $fresh = $banner->fresh();
        $this->assertSame(LessonBanner::DELIVERED, $fresh->delivery_status);
        $this->assertSame('drv-42', $fresh->drive_file_id);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertCount(0, $this->getJson('/api/lesson-banners/due', ['X-Webhook-Secret' => self::SECRET])->json('items'));
    }

    /** @return array{0: Course, 1: Group} */
    private function world(): array
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::create(['title' => 'Грамматика', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа 60', 'status' => 'active']);
        $course->groups()->attach($group->id);

        return [$course, $group];
    }

    private function template(Course $course, ?Group $group = null): LessonBannerTemplate
    {
        $image = imagecreatetruecolor(800, 450);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 30, 90));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'lesson-banner-templates/test-'.uniqid().'.png';
        Storage::disk('public')->put($path, $png);

        $field = ['w' => 360, 'h' => 80, 'align' => 'left', 'font' => 'Missing-Font.ttf', 'size_px' => 48, 'color' => '#FFFFFF', 'fit' => true];

        return LessonBannerTemplate::create([
            'course_id' => $course->id,
            'group_id' => $group?->id,
            'background_disk' => 'public',
            'background_path' => $path,
            'width' => 800,
            'height' => 450,
            'spec' => ['fields' => [
                'date' => $field + ['x' => 40, 'y' => 300, 'format' => 'D MMMM'],
                'number' => $field + ['x' => 40, 'y' => 60, 'format' => 'Занятие {N}', 'overview_text' => 'Обзорное'],
            ]],
            'version' => 1,
        ]);
    }

    private function lesson(Group $group, Course $course, string $start, int $number): Schedule
    {
        $at = Carbon::parse($start);

        return Schedule::create([
            'title' => "Грамматика (#{$number}, {$at->format('d.m.y')})",
            'start' => $at->format('Y-m-d H:i:s'),
            'end' => $at->copy()->addMinutes(90)->format('Y-m-d H:i:s'),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);
    }

    /** @param  array<string, mixed>  $box */
    private function lightPixels(LessonBanner $banner, array $box): int
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($banner->image_path));
        $light = 0;
        for ($x = (int) $box['x']; $x < (int) $box['x'] + (int) $box['w']; $x += 2) {
            for ($y = (int) $box['y']; $y < (int) $box['y'] + (int) $box['h']; $y += 2) {
                $rgb = imagecolorat($image, $x, $y);
                if ((($rgb >> 16) & 0xFF) > 180 && (($rgb >> 8) & 0xFF) > 180 && ($rgb & 0xFF) > 180) {
                    $light++;
                }
            }
        }
        imagedestroy($image);

        return $light;
    }
}
