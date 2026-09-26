<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /dvaram/otzyv: студент прикладывает к отзыву своё фото и видео (файлом до 100 МБ
 * или ссылкой). Файлы — на диск public; на сайте загруженное видео важнее ссылки
 * (Testimonial::mediaLink). До «Одобрить» ничего не публикуется — как и текст.
 */
class StudentTestimonialMediaTest extends TestCase
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
        Storage::fake('public');
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

    private function submit(array $overrides = [])
    {
        return $this->actingAs(User::factory()->create())
            ->post(route('student.testimonial.store'), $this->payload($overrides));
    }

    public function test_form_offers_photo_video_and_link_fields(): void
    {
        $this->actingAs(User::factory()->create())->get('/dvaram/otzyv')
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="avatar"', false)
            ->assertSee('name="video"', false)
            ->assertSee('name="media_url"', false);
    }

    public function test_photo_and_video_are_stored_and_kept_hidden_until_approval(): void
    {
        $this->submit([
            'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
            'video' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
        ])->assertRedirect(route('student.testimonial.create'))->assertSessionHasNoErrors();

        $t = Testimonial::sole();
        $this->assertNotNull($t->avatar_path);
        $this->assertStringStartsWith('testimonials/', $t->avatar_path);
        $this->assertStringStartsWith('testimonials/videos/', $t->video_path);
        Storage::disk('public')->assertExists([$t->avatar_path, $t->video_path]);
        $this->assertFalse($t->is_visible);
        $this->assertTrue($t->isPending());
    }

    public function test_mov_from_iphone_is_accepted(): void
    {
        $this->submit(['video' => UploadedFile::fake()->create('IMG_0001.MOV', 4096, 'video/quicktime')])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Testimonial::sole()->video_path);
    }

    public function test_video_over_100_mb_is_rejected_in_russian(): void
    {
        $this->submit(['video' => UploadedFile::fake()->create('long.mp4', 102401, 'video/mp4')])
            ->assertSessionHasErrors(['video' => 'Видео больше 100 МБ — снимите покороче или пришлите ссылку на ролик.']);

        $this->assertSame(0, Testimonial::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_non_video_file_is_rejected(): void
    {
        $this->submit(['video' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('video');

        $this->assertSame(0, Testimonial::count());
    }

    public function test_photo_must_be_an_image_up_to_5_mb(): void
    {
        $this->submit(['avatar' => UploadedFile::fake()->image('big.jpg')->size(5121)])
            ->assertSessionHasErrors(['avatar' => 'Фото больше 5 МБ — уменьшите, пожалуйста.']);

        $this->submit(['avatar' => UploadedFile::fake()->create('me.txt', 10, 'text/plain')])
            ->assertSessionHasErrors('avatar');

        $this->assertSame(0, Testimonial::count());
    }

    public function test_video_link_must_be_https(): void
    {
        $this->submit(['media_url' => 'http://vk.com/video-1_2'])
            ->assertSessionHasErrors(['media_url' => 'Ссылка на видео должна начинаться с https://']);

        $this->submit(['media_url' => 'https://vk.com/video-1_2'])->assertSessionHasNoErrors();
        $this->assertSame('https://vk.com/video-1_2', Testimonial::sole()->media_url);
    }

    public function test_uploaded_video_wins_over_link_and_shows_after_approval(): void
    {
        $this->submit([
            'video' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'),
            'media_url' => 'https://vk.com/video-1_2',
        ])->assertSessionHasNoErrors();

        $t = Testimonial::sole();
        $this->assertSame(Storage::disk('public')->url($t->video_path), $t->mediaLink());

        $this->get('/otzyvy')->assertDontSee($t->video_path);

        $t->approve();

        $this->get('/otzyvy')->assertOk()
            ->assertSee($t->video_path, false)
            ->assertDontSee('https://vk.com/video-1_2', false);
    }

    public function test_link_only_is_used_when_there_is_no_file(): void
    {
        $t = Testimonial::create([
            'author_name' => 'Автор Ссылки',
            'body' => 'Отзыв со ссылкой на ролик.',
            'media_url' => 'https://rutube.ru/video/abc/',
        ]);

        $this->assertNull($t->videoUrl());
        $this->assertSame('https://rutube.ru/video/abc/', $t->mediaLink());
    }
}
