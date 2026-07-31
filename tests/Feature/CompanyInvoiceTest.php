<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CompanyInvoiceReceivedMail;
use App\Mail\CompanyInvoiceStudentAckMail;
use App\Mail\StudentWelcomeMail;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompanyInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        config([
            'billing.company_invoice.enabled' => true,
            'billing.legal' => [
                'name' => 'ИП Тестов',
                'inn' => '123456789012',
                'kpp' => '',
                'ogrn' => '',
                'ogrnip' => '123456789012345',
                'address' => 'г. Москва',
                'bank_name' => 'АО Тестбанк',
                'bik' => '044525225',
                'account' => '40802810100000000001',
                'corr_account' => '30101810400000000225',
                'email' => 'billing@example.test',
                'phone' => '+7 900 000-00-00',
            ],
            'services.admin.email' => 'admin@example.test',
        ]);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
    }

    /** @test */
    public function disabled_feature_returns_404(): void
    {
        config(['billing.company_invoice.enabled' => false]);
        $tariff = $this->blockTariff();

        $this->get(route('invoice.claim.show', $tariff))->assertNotFound();
    }

    /** @test */
    public function enabled_form_renders(): void
    {
        $tariff = $this->blockTariff();

        $this->get(route('invoice.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Счет для компании')
            ->assertSee('ИНН');
    }

    /** @test */
    public function guest_claim_creates_pending_invoice_without_access(): void
    {
        $tariff = $this->blockTariff();

        $response = $this->post(route('invoice.claim.store', $tariff), [
            'name' => 'Анна',
            'email' => 'anna@example.test',
            'company_name' => 'ООО Ромашка',
            'inn' => '7707083893',
            'kpp' => '770701001',
            'legal_address' => 'г. Москва, ул. Тестовая, 1',
            'contact_phone' => '+7 900 111-22-33',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_INVOICE)->latest()->firstOrFail();

        $response->assertRedirect(route('invoice.print', $payment));
        $response->assertSessionHas('success');

        $this->assertSame('pending', $payment->status);
        $this->assertSame(4800.0, (float) $payment->amount);
        $this->assertSame('ООО Ромашка', $payment->claimMeta('company_name'));
        $this->assertSame('7707083893', $payment->claimMeta('inn'));
        $this->assertSame(0, $payment->user->groups()->count());
        $this->assertFalse($payment->user->courses()->where('courses.id', $tariff->course_id)->exists());

        Mail::assertQueued(CompanyInvoiceReceivedMail::class);
        Mail::assertQueued(CompanyInvoiceStudentAckMail::class, fn ($mail) => $mail->hasTo('anna@example.test'));
    }

    /** @test */
    public function print_page_is_owner_only(): void
    {
        $tariff = $this->blockTariff();
        $this->post(route('invoice.claim.store', $tariff), [
            'name' => 'Анна',
            'email' => 'anna@example.test',
            'company_name' => 'ООО Ромашка',
            'inn' => '7707083893',
            'legal_address' => 'Москва',
        ]);
        $payment = Payment::query()->where('provider', Payment::PROVIDER_INVOICE)->latest()->firstOrFail();

        // Guest after post is logged in as owner — print ok.
        $this->get(route('invoice.print', $payment))
            ->assertOk()
            ->assertSee($payment->invoiceNumber())
            ->assertSee('ООО Ромашка')
            ->assertSee('ИП Тестов');

        auth()->logout();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('invoice.print', $payment))->assertForbidden();
    }

    /** @test */
    public function admin_confirmation_grants_access(): void
    {
        $tariff = $this->blockTariff();
        $this->post(route('invoice.claim.store', $tariff), [
            'name' => 'Анна',
            'email' => 'anna@example.test',
            'company_name' => 'ООО Ромашка',
            'inn' => '7707083893',
            'legal_address' => 'Москва',
        ]);
        $payment = Payment::query()->where('provider', Payment::PROVIDER_INVOICE)->latest()->firstOrFail();

        $payment->update(['status' => 'paid']);

        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists()
        );
        Mail::assertQueued(StudentWelcomeMail::class, fn ($mail) => $mail->hasTo('anna@example.test'));
    }

    /** @test */
    public function invalid_inn_is_rejected(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('invoice.claim.store', $tariff), [
            'name' => 'Анна',
            'email' => 'anna@example.test',
            'company_name' => 'ООО Ромашка',
            'inn' => '123',
            'legal_address' => 'Москва',
        ])->assertSessionHasErrors('inn');

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_INVOICE)->count());
    }

    /** @test */
    public function checkout_cta_hidden_when_disabled(): void
    {
        config(['billing.company_invoice.enabled' => false]);
        $tariff = $this->blockTariff();
        $html = view('partials.company-invoice-cta', ['tariff' => $tariff])->render();
        $this->assertStringNotContainsString('Счет для компании', $html);
    }
}
