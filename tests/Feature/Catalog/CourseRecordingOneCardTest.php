<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tariff;
use App\Services\CatalogFamilyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Одна карточка на программу» — рулинг MG 31-08-2026 (H3807, природа B H3773).
 *
 * Три семьи продавали живой поток и его же ЗАПИСЬ отдельными строками каталога
 * под одним номером потока: `astronomiia-dlia-astrologov` 279/418 (35 оплат у
 * записи), `ioga-sutry-patandzali` 396/327 (**129 оплат**),
 * `likbez-po-lingvistike` 344/394. Удалять там нечего — у записи своя выручка;
 * дефект в витрине и SEO, где одна программа показана дважды.
 *
 * Поэтому большая часть проверок здесь — про то, что запись НЕ теряется:
 * страница жива, покупаема, названа на карточке живого курса. Из ленты уходит
 * только вторая карточка.
 */
class CourseRecordingOneCardTest extends TestCase
{
    use RefreshDatabase;

    private function live(array $attributes = []): Course
    {
        $course = Course::factory()->create(array_merge([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025)',
            'slug' => 'ioga-sutry-patandzali-1-potok-2025-test-only',
            'course_family' => 'ioga-sutry-patandzali',
            'is_visible' => true,
            'format' => 'live',
        ], $attributes));

        Lesson::factory()->for($course)->create();
        Tariff::factory()->for($course)->create();

        return $course;
    }

    private function recording(array $attributes = []): Course
    {
        $course = Course::factory()->create(array_merge([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи',
            'slug' => 'ioga-sutry-patandzali-v-zapisi-1-potok-2025-test-only',
            'course_family' => 'ioga-sutry-patandzali',
            'is_visible' => true,
            'format' => 'recorded',
        ], $attributes));

        // Живой курс-запись, как на проде: у курса 327 «Йога-сутры Патанджали
        // в записи» свои блоки, тарифы и 129 оплат — роль `live`, не оболочка.
        Lesson::factory()->for($course)->create();
        Tariff::factory()->for($course)->create();

        return $course;
    }

    /** @test */
    public function the_catalogue_shows_one_card_per_programme(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        Livewire::test(CourseCatalog::class)
            ->assertSee($live->title)
            ->assertDontSee($recording->title);
    }

    /** @test */
    public function an_unlinked_recording_keeps_its_own_card(): void
    {
        $live = $this->live();
        $recording = $this->recording();

        Livewire::test(CourseCatalog::class)
            ->assertSee($live->title)
            ->assertSee($recording->title);
    }

    /** @test */
    public function the_recording_page_stays_alive_and_buyable(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        $this->get('/k/'.$recording->slug)
            ->assertOk()
            ->assertSee($recording->title, false);
    }

    /** @test */
    public function the_recording_page_points_its_canonical_at_the_live_course(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        $this->get('/k/'.$recording->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('shop.course.show', $live->slug).'">', false);
    }

    /** @test */
    public function a_standalone_course_canonicalises_to_itself(): void
    {
        $live = $this->live();

        $this->get('/k/'.$live->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('shop.course.show', $live->slug).'">', false);
    }

    /** @test */
    public function the_live_card_names_the_recording_as_a_purchase_option(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        $this->get('/k/'.$live->slug)
            ->assertOk()
            ->assertSee('data-testid="recording-offers"', false)
            ->assertSee($recording->title, false)
            ->assertSee(route('shop.course.show', $recording->slug), false);
    }

    /** @test */
    public function a_live_course_without_recordings_shows_no_such_block(): void
    {
        $live = $this->live();

        $this->get('/k/'.$live->slug)
            ->assertOk()
            ->assertDontSee('data-testid="recording-offers"', false);
    }

    /** @test */
    public function the_sitemap_lists_one_url_per_programme(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        $response = $this->get('/sitemap.xml')->assertOk();
        $response->assertSee('/k/'.$live->slug, false);
        $response->assertDontSee('/k/'.$recording->slug, false);
    }

    /** @test */
    public function an_explicitly_linked_recording_is_no_longer_a_duplicate_family(): void
    {
        $live = $this->live();
        $this->recording(['recording_of_course_id' => $live->id]);

        $row = $this->familyRow('ioga-sutry-patandzali');

        $this->assertNotNull($row);
        $this->assertNotSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict'], (string) json_encode($row['reasons']));
    }

    /** @test */
    public function the_same_pair_without_the_link_is_still_a_duplicate(): void
    {
        $this->live();
        $this->recording();

        $row = $this->familyRow('ioga-sutry-patandzali');

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict']);
    }

    /** @test */
    public function a_half_wired_collision_is_still_a_duplicate(): void
    {
        // Три строки одного потока, связана только одна: карточек по-прежнему
        // две, и гасить вердикт здесь значило бы прятать настоящий дубль.
        $live = $this->live();
        $this->recording(['recording_of_course_id' => $live->id]);
        $this->recording([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи, повтор',
            'slug' => 'ioga-sutry-patandzali-v-zapisi-povtor-test-only',
        ]);

        $row = $this->familyRow('ioga-sutry-patandzali');

        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict']);
    }

    /** @test */
    public function the_command_links_and_unlinks(): void
    {
        $live = $this->live();
        $recording = $this->recording();

        $this->artisan('catalog:link-recording', [
            'recording' => $recording->slug,
            '--into' => $live->slug,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($live->id, (int) $recording->fresh()->recording_of_course_id);

        $this->artisan('catalog:link-recording', [
            'recording' => $recording->id,
            '--unlink' => true,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertNull($recording->fresh()->recording_of_course_id);
    }

    /** @test */
    public function the_command_writes_nothing_without_apply(): void
    {
        $live = $this->live();
        $recording = $this->recording();

        $this->artisan('catalog:link-recording', [
            'recording' => $recording->id,
            '--into' => $live->id,
        ])->assertSuccessful();

        $this->assertNull($recording->fresh()->recording_of_course_id);
    }

    /** @test */
    public function the_command_refuses_a_target_from_another_family(): void
    {
        $stranger = $this->live([
            'title' => 'Ликбез по лингвистике (2 поток, 2025-2026)',
            'slug' => 'likbez-2-potok-test-only',
            'course_family' => 'likbez-po-lingvistike',
        ]);
        $recording = $this->recording();

        $this->artisan('catalog:link-recording', [
            'recording' => $recording->id,
            '--into' => $stranger->id,
            '--apply' => true,
        ])->assertFailed();

        $this->assertNull($recording->fresh()->recording_of_course_id);
    }

    /** @test */
    public function the_command_refuses_a_chain_of_recordings(): void
    {
        $live = $this->live();
        $middle = $this->recording(['recording_of_course_id' => $live->id]);
        $tail = $this->recording([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи, повтор',
            'slug' => 'ioga-sutry-patandzali-v-zapisi-povtor-test-only',
        ]);

        $this->artisan('catalog:link-recording', [
            'recording' => $tail->id,
            '--into' => $middle->id,
            '--apply' => true,
        ])->assertFailed();

        $this->assertNull($tail->fresh()->recording_of_course_id);
    }

    /** @test */
    public function deleting_the_live_course_leaves_the_paid_recording_standing(): void
    {
        $live = $this->live();
        $recording = $this->recording(['recording_of_course_id' => $live->id]);

        Course::query()->whereKey($live->id)->delete();

        $fresh = $recording->fresh();
        $this->assertNotNull($fresh, 'оплаченная запись переживает удаление живого курса');
        $this->assertNull($fresh->recording_of_course_id, 'и снова становится самостоятельным товаром');
    }

    /** @return array<string, mixed>|null */
    private function familyRow(string $family): ?array
    {
        foreach (app(CatalogFamilyAudit::class)->report() as $row) {
            if ($row['family'] === $family) {
                return $row;
            }
        }

        return null;
    }
}
