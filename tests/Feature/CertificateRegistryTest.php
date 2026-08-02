<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create(['title' => 'Санскрит с нуля']);
    }

    private function cert(string $name, array $attrs = []): Certificate
    {
        $user = User::factory()->create(['name' => $name]);

        return Certificate::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'student_name' => $name,
            'issued_at' => now(),
        ], $attrs));
    }

    /** @test */
    public function it_lists_certificates_publicly_with_verify_links(): void
    {
        $cert = $this->cert('Иванова Мария');

        $this->get('/sertifikaty')
            ->assertOk()
            ->assertSee('Иванова Мария')
            ->assertSee('Санскрит с нуля')
            ->assertSee($cert->number)
            ->assertSee(route('certificate.verify', $cert->number), false);
    }

    /** @test */
    public function name_sort_orders_alphabetically_and_date_sort_reverse_chronologically(): void
    {
        $this->cert('Яковлева Анна', ['issued_at' => now()->subDays(2)]);
        $this->cert('Абрамов Пётр', ['issued_at' => now()->subDay()]);

        $byName = $this->get('/sertifikaty?sort=name')->assertOk()->getContent();
        $this->assertLessThan(
            mb_strpos($byName, 'Яковлева Анна'),
            mb_strpos($byName, 'Абрамов Пётр'),
        );

        $byDate = $this->get('/sertifikaty?sort=date')->assertOk()->getContent();
        $this->assertLessThan(
            mb_strpos($byDate, 'Яковлева Анна'),
            mb_strpos($byDate, 'Абрамов Пётр'), // выдан позже → выше
        );
    }

    /** @test */
    public function group_sort_shows_group_names_and_dash_for_manual_rows(): void
    {
        $group = Group::create(['name' => 'Поток 42', 'status' => 'archived']);
        $this->cert('С группой', ['group_id' => $group->id]);
        $this->cert('Без группы');

        $this->get('/sertifikaty?sort=group')
            ->assertOk()
            ->assertSee('Поток 42')
            ->assertSee('—');
    }

    /** @test */
    public function course_and_year_filters_narrow_the_list(): void
    {
        $this->cert('Наш студент', ['issued_at' => now()]);

        $otherCourse = Course::factory()->create(['title' => 'Другой курс']);
        $stranger = User::factory()->create(['name' => 'Чужой студент']);
        Certificate::create([
            'user_id' => $stranger->id,
            'course_id' => $otherCourse->id,
            'student_name' => 'Чужой студент',
            'issued_at' => now()->subYears(2),
        ]);

        $this->get('/sertifikaty?course='.$this->course->slug)
            ->assertOk()
            ->assertSee('Наш студент')
            ->assertDontSee('Чужой студент');

        $this->get('/sertifikaty?year='.now()->year)
            ->assertOk()
            ->assertSee('Наш студент')
            ->assertDontSee('Чужой студент');
    }

    /** @test */
    public function exam_scores_are_not_exposed_in_the_registry(): void
    {
        $this->cert('Экзаменуемая', [
            'template' => 'sanka',
            'score_clarity' => 17.5,
            'score_letters' => 4.5,
            'score_flow' => 3.5,
        ]);

        $this->get('/sertifikaty')
            ->assertOk()
            ->assertSee('Экзаменуемая')
            ->assertDontSee('17.5')
            ->assertDontSee('Результаты экзамена');
    }

    /** @test */
    public function spravka_rows_are_labeled(): void
    {
        $this->cert('Со справкой', ['document_type' => CertificateMilestone::DOC_SPRAVKA]);

        $this->get('/sertifikaty')->assertOk()->assertSee('Справка');
    }

    /** @test */
    public function the_list_is_paginated(): void
    {
        foreach (range(1, 51) as $i) {
            $this->cert('Студент '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $first = $this->get('/sertifikaty')->assertOk();
        $first->assertSee('page=2');

        $this->get('/sertifikaty?page=2')->assertOk();
    }
}
