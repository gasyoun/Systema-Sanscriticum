<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Возврат курса-записи в продажу (H3807, рулинг MG «вернуть в продажу»).
 *
 * Обратный ход к `catalog:deactivate-dormant-tariffs`, которая 31-08-2026
 * погасила пять тарифов курса 327 «Йога-сутры Патанджали в записи» — товара со
 * **129 оплатами**. Проверки здесь в основном про то, чего команда НЕ делает:
 * не угадывает тарифы, не работает вне записей, не создаёт вторую карточку.
 */
class RestoreRecordingSaleTest extends TestCase
{
    use RefreshDatabase;

    private function live(): Course
    {
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025)',
            'slug' => 'ioga-sutry-patandzali-1-potok-2025-test-only',
            'course_family' => 'ioga-sutry-patandzali',
            'is_visible' => true,
        ]);
        Lesson::factory()->for($course)->create();
        Tariff::factory()->for($course)->create();

        return $course;
    }

    private function hiddenRecording(Course $live): Course
    {
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи',
            'slug' => 'ioga-sutry-patandzali-v-zapisi-1-potok-2025-test-only',
            'course_family' => 'ioga-sutry-patandzali',
            'is_visible' => false,
            'recording_of_course_id' => $live->id,
        ]);
        Lesson::factory()->for($course)->create();

        return $course;
    }

    /** @test */
    public function it_reopens_the_page_and_activates_only_the_named_tariffs(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);

        $wanted = Tariff::factory()->for($recording)->create(['is_active' => false]);
        $leftAlone = Tariff::factory()->for($recording)->create(['is_active' => false]);

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$wanted->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertTrue((bool) $recording->fresh()->is_visible);
        $this->assertTrue((bool) $wanted->fresh()->is_active);
        $this->assertFalse(
            (bool) $leftAlone->fresh()->is_active,
            'неназванный тариф остаётся погашенным — команда не угадывает, что вернуть в продажу',
        );
    }

    /** @test */
    public function the_restored_recording_is_buyable_again(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);
        $tariff = Tariff::factory()->for($recording)->create(['is_active' => false]);

        $this->get('/k/'.$recording->slug)->assertNotFound();

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$tariff->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->get('/k/'.$recording->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('shop.course.show', $live->slug).'">', false);
    }

    /** @test */
    public function it_still_gets_no_second_card_in_the_catalogue(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);
        $tariff = Tariff::factory()->for($recording)->create(['is_active' => false]);

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$tariff->id],
            '--apply' => true,
        ])->assertSuccessful();

        Livewire::test(CourseCatalog::class)
            ->assertSee($live->title)
            ->assertDontSee($recording->title);
    }

    /** @test */
    public function the_live_card_starts_naming_it_as_a_purchase_option(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);
        $tariff = Tariff::factory()->for($recording)->create(['is_active' => false]);

        $this->get('/k/'.$live->slug)->assertDontSee('data-testid="recording-offers"', false);

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$tariff->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->get('/k/'.$live->slug)
            ->assertOk()
            ->assertSee('data-testid="recording-offers"', false)
            ->assertSee($recording->title, false);
    }

    /** @test */
    public function it_writes_nothing_without_apply(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);
        $tariff = Tariff::factory()->for($recording)->create(['is_active' => false]);

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$tariff->id],
        ])->assertSuccessful();

        $this->assertFalse((bool) $recording->fresh()->is_visible);
        $this->assertFalse((bool) $tariff->fresh()->is_active);
    }

    /** @test */
    public function it_refuses_a_course_that_is_not_a_linked_recording(): void
    {
        $live = $this->live();

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $live->id,
            '--apply' => true,
        ])->assertFailed();
    }

    /** @test */
    public function it_refuses_a_tariff_that_is_not_a_dormant_one_of_this_course(): void
    {
        $live = $this->live();
        $recording = $this->hiddenRecording($live);
        $stranger = Tariff::factory()->for($live)->create(['is_active' => false]);

        $this->artisan('catalog:restore-recording-sale', [
            'course' => $recording->id,
            '--tariff' => [$stranger->id],
            '--apply' => true,
        ])->assertFailed();

        $this->assertFalse((bool) $recording->fresh()->is_visible);
    }
}
