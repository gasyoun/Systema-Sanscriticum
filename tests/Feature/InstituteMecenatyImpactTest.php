<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DonationGratitude;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4522 — donor impact scroll «Куда идут 500 ₽» on /mecenaty.
 *
 * Flag: config('institute.mecenaty_scrolly') — default FALSE (money contour:
 * dark deploy). QA override: ?impact=1. ст. 582 ГК wording must survive in
 * BOTH states; the scrolly gratitude beat renders names only, never amounts.
 */
class InstituteMecenatyImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_impact_scroll_hidden_by_default(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertDontSee('impact-scroll')
            ->assertDontSee('Куда идут 500 ₽');
    }

    public function test_impact_scroll_shows_with_impact_query(): void
    {
        $this->get('/mecenaty?impact=1')
            ->assertOk()
            ->assertSee('impact-scroll')
            ->assertSee('Куда идут 500 ₽')
            ->assertSee('Кто уже с нами')
            ->assertSee('Список открыт')
            ->assertSee('Стать меценатом');
    }

    public function test_impact_scroll_shows_when_flag_enabled(): void
    {
        config(['institute.mecenaty_scrolly' => true]);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('impact-scroll')
            ->assertSee('Куда идут 500 ₽');
    }

    public function test_art582_wording_present_in_both_states(): void
    {
        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('ст. 582 Гражданского кодекса РФ')
            ->assertSee('не возврату и не обмену не подлежит');

        $this->get('/mecenaty?impact=1')
            ->assertOk()
            ->assertSee('ст. 582 Гражданского кодекса РФ')
            ->assertSee('не возврату и не обмену не подлежит');
    }

    public function test_scrolly_gratitude_beat_shows_consenting_name_without_amount(): void
    {
        $this->donateWithGratitude('Меценат с суммой', withAmount: true);
        $payment = Payment::query()->where('tariff', 'donation')->latest()->firstOrFail();
        $payment->update(['status' => 'paid']);
        DonationGratitude::firstOrFail()->update(['show_amount' => true]);

        $html = $this->get('/mecenaty?impact=1')->assertOk()->getContent();
        $slice = $this->impactSlice($html);

        $this->assertStringContainsString('Меценат с суммой', $slice);
        // The consented amount may appear on the page (bottom gratitude list,
        // form presets) but never inside the scrolly section.
        $this->assertStringNotContainsString('2 500', $slice);
    }

    public function test_scrolly_gratitude_beat_respects_consent_flag(): void
    {
        DonationGratitude::create(['name_display' => 'Скрытый меценат', 'is_public' => false]);
        DonationGratitude::create(['name_display' => 'Публичный меценат', 'is_public' => true]);

        $html = $this->get('/mecenaty?impact=1')->assertOk()->getContent();
        $slice = $this->impactSlice($html);

        $this->assertStringContainsString('Публичный меценат', $slice);
        $this->assertStringNotContainsString('Скрытый меценат', $slice);
    }

    /**
     * @return string Markup of the impact-scroll section only (no nested
     *                <section> inside, so the first closing tag bounds it).
     */
    private function impactSlice(string $html): string
    {
        $start = strpos($html, 'id="impact-scroll"');
        $this->assertNotFalse($start, 'impact-scroll section missing');
        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function donateWithGratitude(string $name, bool $withAmount = false): void
    {
        config(['institute.donations_enabled' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/h2', 'paymentLinkId' => 'h4522'],
            ], 200),
        ]);

        $payload = [
            'amount' => 2500,
            'name' => 'Донор',
            'email' => 'h4522@example.test',
            'gratitude_consent' => '1',
            'gratitude_name' => $name,
        ];
        if ($withAmount) {
            $payload['gratitude_amount'] = '1';
        }

        $this->post(route('institute.donate'), $payload)->assertRedirect();
    }
}
