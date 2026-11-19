<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H6531 — HDYHAU self-report «откуда узнали» в форме заявки (пункт 4
 * H6046, MG 12-10-2026 «Внедрять»): канонический ключ сохраняется в
 * leads.reported_source, ветка «Другое» — слова заявителя дословно,
 * пустой выбор и отсутствие поля валидны (старые формы не ломаются).
 * Колонка — зеркало reported_source журнала лида, четвёртая нога
 * атрибуции рядом с UTM; curatorskiy leads.source (H5021) не трогаем.
 */
class LeadReportedSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function postLead(array $overrides = [])
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        $landing = LandingPage::create(['title' => 'L', 'slug' => 'hdyhau-'.uniqid()]);

        return $this->post(route('leads.store'), array_merge([
            'contact' => '+79990000000',
            'landing_page_id' => $landing->id,
        ], $overrides));
    }

    /** @test */
    public function canonical_choice_persists_as_stable_key(): void
    {
        $this->postLead(['reported_source' => 'friends'])->assertRedirect();

        $lead = Lead::latest('id')->firstOrFail();
        $this->assertSame('friends', $lead->reported_source);
        $this->assertSame('Посоветовали друзья или знакомые', $lead->reportedSourceLabel());
    }

    /** @test */
    public function other_branch_saves_the_persons_own_words(): void
    {
        $this->postLead([
            'reported_source' => 'other',
            'reported_source_other' => '  Увидел плакат в библиотеке ',
        ])->assertRedirect();

        $lead = Lead::latest('id')->firstOrFail();
        // Слова заявителя дословно (trim) — в журнал лида, UTM не выдумываем.
        $this->assertSame('Увидел плакат в библиотеке', $lead->reported_source);
        $this->assertSame('Увидел плакат в библиотеке', $lead->reportedSourceLabel());
    }

    /** @test */
    public function other_branch_without_text_persists_null(): void
    {
        $this->postLead(['reported_source' => 'other'])->assertRedirect();

        $lead = Lead::latest('id')->firstOrFail();
        $this->assertNull($lead->reported_source);
        $this->assertNull($lead->reportedSourceLabel());
    }

    /** @test */
    public function empty_choice_and_legacy_payload_without_the_field_stay_valid(): void
    {
        // Пустой выбор (пустая опция селекта).
        $this->postLead(['reported_source' => ''])->assertRedirect();
        $this->assertSame(null, Lead::latest('id')->first()->reported_source);

        // Старая форма/интеграция: поля нет вообще — заявка валидна.
        $this->postLead()->assertRedirect();
        $this->assertNull(Lead::latest('id')->first()->reported_source);
    }

    /** @test */
    public function unknown_choice_is_rejected(): void
    {
        $this->postLead(['reported_source' => 'carrier-pigeon'])->assertInvalid(['reported_source']);

        $this->assertSame(0, Lead::count());
    }
}
