<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Волна 1 списка ожидания (MG 31-08-2026): фид /api/public/waitlist — границы
 * allowlist, пороги, кэш; голосование — auth + флаг, идемпотентность.
 */
class PublicWaitlistFeedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/waitlist';

    private function makeItem(array $attrs = []): CourseWaitlistItem
    {
        return CourseWaitlistItem::create(array_merge([
            'slug' => 'buer-grammatika-potok-2',
            'course_title' => 'Руководство по Бюлеру',
            'teacher_name' => 'Марцис Гасунс',
            'slot' => 'пн 18:00',
            'earliest_start_at' => '2027-10-01',
            'min_payers' => 10,
            'block_price_rub' => 8000,
            'kind' => 'grammar',
        ], $attrs));
    }

    /** @param  array<int, string>  $keys */
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

    public function test_feed_lists_items_with_progress_and_no_ids(): void
    {
        $item = $this->makeItem();
        $item->votes()->createMany(
            collect(range(1, 3))->map(fn ($i) => ['user_id' => User::factory()->create()->id])->all()
        );

        $response = $this->getJson(self::URL)->assertOk();

        $row = collect($response->json('data'))->firstWhere('slug', 'buer-grammatika-potok-2');
        $this->assertNotNull($row);
        $this->assertSame('Руководство по Бюлеру', $row['title']);
        $this->assertSame('Марцис Гасунс', $row['teacher']);
        $this->assertSame('2027-10-01', $row['earliest_start']);
        $this->assertSame(8000, $row['price']);
        $this->assertSame(3, $row['votes']);
        $this->assertSame(10, $row['min_payers']);
        $this->assertSame('collecting', $row['status']);

        // ГРАНИЦА: никаких числовых id и PII в дереве ответа.
        $keys = [];
        $this->collectKeys($response->json(), $keys);
        foreach (['id', 'course_id', 'item_id', 'user_id', 'course_waitlist_item_id', 'historical_notes'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Фид не должен отдавать ключ `{$forbidden}`.");
        }
    }

    public function test_unlisted_items_are_hidden_and_order_is_sort_order(): void
    {
        $hidden = $this->makeItem(['slug' => 'hidden-one', 'is_listed' => false]);
        $first = $this->makeItem(['slug' => 'first-shown', 'sort_order' => 1]);
        $second = $this->makeItem(['slug' => 'second-shown', 'sort_order' => 2]);

        $data = $this->getJson(self::URL)->assertOk()->json('data');
        $slugs = collect($data)->pluck('slug')->all();

        $this->assertNotContains('hidden-one', $slugs);
        $this->assertTrue(array_search('first-shown', $slugs) < array_search('second-shown', $slugs));
    }

    public function test_linked_hidden_course_is_not_exposed(): void
    {
        $course = Course::factory()->create(['is_visible' => false]);
        $this->makeItem(['course_id' => $course->id]);

        $row = $this->getJson(self::URL)->assertOk()->json('data.0');
        $this->assertNull($row['course']);
    }

    public function test_second_identical_request_is_cached(): void
    {
        $this->makeItem();

        $this->getJson(self::URL)->assertOk();
        $payload = serialize(Cache::get('public_waitlist:v1'));
        $this->assertNotEmpty($payload, 'Первый запрос должен прогреть кэш с ключом public_waitlist:v1');
    }

    public function test_vote_requires_auth_and_flag(): void
    {
        $this->makeItem();

        // Флаг ON, гость (actingAs ещё НЕ вызван — порядок важен) → 401.
        config(['features.waitlist_voting' => true]);
        $this->postJson(self::URL.'/vote', ['slug' => 'buer-grammatika-potok-2'])
            ->assertStatus(401);

        // Флаг OFF → 404, даже с аутентификацией.
        config(['features.waitlist_voting' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(self::URL.'/vote', ['slug' => 'buer-grammatika-potok-2'])
            ->assertStatus(404);
    }

    public function test_authenticated_user_can_vote_once(): void
    {
        config(['features.waitlist_voting' => true]);
        $item = $this->makeItem(['min_payers' => 10]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::URL.'/vote', ['slug' => 'buer-grammatika-potok-2'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('votes', 1)
            ->assertJsonPath('min_payers', 10)
            ->assertJsonPath('threshold_met', false);

        // Повторный клик идемпотентен.
        $this->actingAs($user)
            ->postJson(self::URL.'/vote', ['slug' => 'buer-grammatika-potok-2'])
            ->assertOk()
            ->assertJsonPath('votes', 1);

        // Второй пользователь — голос растёт, порог ещё не достигнут (2 голосов при 10).
        $other = User::factory()->create();
        Cache::flush();
        $this->actingAs($other)
            ->postJson(self::URL.'/vote', ['slug' => 'buer-grammatika-potok-2'])
            ->assertOk()
            ->assertJsonPath('votes', 2)
            ->assertJsonPath('threshold_met', false);

        $this->assertSame(2, $item->votes()->count());
    }

    public function test_vote_unknown_slug_is_404(): void
    {
        config(['features.waitlist_voting' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(self::URL.'/vote', ['slug' => 'no-such-slug'])
            ->assertStatus(404);
    }
}
