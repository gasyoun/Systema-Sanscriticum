<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VK-видео в плеере урока (мини-курсы): парсер ссылок VK, серверные ворота
 * отдают 302 на video_ext-embed, страница показывает переключатель «VK Видео».
 */
class VkVideoGateTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function parser_accepts_watch_and_embed_vk_urls(): void
    {
        $cases = [
            'https://vk.com/video-88831040_456239808' => '-88831040_456239808',
            'https://vkvideo.ru/video-89658969_456240350' => '-89658969_456240350',
            'https://vk.com/video1116419_456241944' => '1116419_456241944',
            'https://vk.com/video_ext.php?oid=-88831040&id=456239808&hd=2' => '-88831040_456239808',
            'https://youtube.com/watch?v=kBSpzX9SoSo' => null,
            null => null,
        ];

        foreach ($cases as $url => $expected) {
            $this->assertSame($expected, StudentController::parseVideoId($url, 'vk'), $url ?? 'null');
        }
    }

    /** @test */
    public function vk_gate_redirects_to_video_ext_embed(): void
    {
        [$user, $course, $lesson] = $this->freeLesson(
            'https://vkvideo.ru/video-88831040_456239808',
        );

        // is_free-урок открыт даже гостю (как youtube-ворота).
        $this->get(route('student.recording.gate', [$course->slug, $lesson->id, 'vk']))
            ->assertRedirect('https://vk.com/video_ext.php?oid=-88831040&id=456239808&hd=2&js=1');
    }

    /** @test */
    public function vk_gate_404s_without_video_url(): void
    {
        [$user, $course, $lesson] = $this->freeLesson(null);

        $this->get(route('student.recording.gate', [$course->slug, $lesson->id, 'vk']))
            ->assertNotFound();
    }

    /** @test */
    public function lesson_page_shows_vk_switcher_for_vk_video(): void
    {
        [$user, $course, $lesson] = $this->freeLesson(
            'https://vkvideo.ru/video-89658969_456240350',
        );

        // Переключатель появляется при 2+ источниках: добавим к VK ещё и YouTube.
        $lesson->update(['youtube_url' => 'https://youtu.be/_83y7fIYZls']);

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('VK Видео')
            ->assertSee("player === 'vk'", false);
    }

    /** @test */
    public function lesson_page_hides_player_without_any_video(): void
    {
        [$user, $course, $lesson] = $this->freeLesson(null);

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertDontSee('Источник видео')
            ->assertDontSee('Видео недоступно');
    }

    /**
     * Бесплатный урок курса с группой: is_free = плеер открыт каждому.
     *
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function freeLesson(?string $videoUrl): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-vk-'.uniqid()]);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true]);
        $course->groups()->attach($group);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'is_free' => true,
            'video_url' => $videoUrl,
        ]);

        return [$user, $course, $lesson];
    }
}
