<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6198 — анкета «перевёл рублями Гасунсу»: провайдер gasuns_transfer, счёт
 * школы (не teacher_personal!), дубль до сверки — отказ, флаг OFF = 404.
 * Рулинг MG 06-10 «сверка сразу проходит»: устоявшийся ученик — сразу paid
 * (зеркало paypal 22-08); свежий аккаунт/гость — pending; курс без групп —
 * fail-closed pending даже для устоявшегося.
 */
class GasunsPayClaimTest extends TestCase
{
    use RefreshDatabase;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gasuns_pay.enabled', true);

        $course = Course::factory()->create(['title' => 'Хинди, вторник']);
        $this->tariff = Tariff::create([
            'course_id' => $course->id,
            'title' => 'Блок 10',
            'type' => 'block',
            'block_number' => 10,
            'price' => 8000,
            'is_active' => true,
        ]);
    }

    public function test_flag_off_hides_the_form(): void
    {
        config()->set('services.gasuns_pay.enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/gasuns-pay/'.$this->tariff->id)
            ->assertNotFound();
    }

    public function test_form_shows_ruble_price(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/gasuns-pay/'.$this->tariff->id)
            ->assertSuccessful()
            ->assertSee('8 000')
            ->assertSee('Гасунсу');
    }

    public function test_submit_creates_pending_school_payment(): void
    {
        // Свежий аккаунт (0 дней, без платежей) — НЕ устоявшийся → pending
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/gasuns-pay/'.$this->tariff->id, [
                'paid_on' => '2026-10-05',
                'sender_name' => 'Мухасанова Хадижа',
                'reference' => 'перевод СБП',
                'comment' => 'за второй блок',
            ]);

        $response->assertRedirect();

        $payment = Payment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($payment, 'платёж не создан');
        $this->assertSame('pending', $payment->status);
        $this->assertSame(Payment::PROVIDER_GASUNS_TRANSFER, $payment->provider);
        // Деньги школы — гонорар преподавателя НЕ урезается (анти-кейс teacher_personal)
        $this->assertSame(Payment::RECEIVED_SCHOOL, $payment->received_account);
        $this->assertNull($payment->received_by_teacher_id);
        $this->assertEquals(8000.0, (float) $payment->amount);
        $this->assertSame(10, (int) $payment->start_block);
        $this->assertSame($this->tariff->course_id, $payment->course_id);
        $this->assertSame('Мухасанова Хадижа', (string) $payment->claimMeta('sender_name'));
    }

    public function test_duplicate_pending_claim_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = ['paid_on' => '2026-10-05', 'sender_name' => 'Тот же отправитель'];

        $this->actingAs($user)->post('/gasuns-pay/'.$this->tariff->id, $payload)->assertRedirect();
        $this->actingAs($user)->post('/gasuns-pay/'.$this->tariff->id, $payload);

        $this->assertSame(
            1,
            Payment::query()->where('user_id', $user->id)->where('provider', Payment::PROVIDER_GASUNS_TRANSFER)->count(),
            'дубль заявки создал второй платёж'
        );
    }

    public function test_guest_with_existing_email_is_rejected(): void
    {
        $existing = User::factory()->create();

        $this->post('/gasuns-pay/'.$this->tariff->id, [
            'name' => 'Гость',
            'email' => $existing->email,
            'paid_on' => '2026-10-05',
            'sender_name' => 'Отправитель',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            0,
            Payment::query()->where('provider', Payment::PROVIDER_GASUNS_TRANSFER)->count()
        );
    }

    public function test_established_student_is_paid_immediately(): void
    {
        // Возраст ≥ 7 дней → устоявшийся: рулинг MG 06-10 «сверка сразу проходит»
        $user = User::factory()->create(['created_at' => now()->subDays(8)]);
        $course = $this->tariff->course;
        $course->groups()->create(['name' => 'Группа вторника']);

        $this->actingAs($user)
            ->post('/gasuns-pay/'.$this->tariff->id, [
                'paid_on' => '2026-10-06',
                'sender_name' => 'Мухасанова Хадижа',
            ])->assertRedirect();

        $payment = Payment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(Payment::RECEIVED_SCHOOL, $payment->received_account);
        $this->assertTrue((bool) $payment->claimMeta('auto_trusted'));
    }

    public function test_trusted_falls_back_to_pending_without_access_groups(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(8)]);

        $this->actingAs($user)
            ->post('/gasuns-pay/'.$this->tariff->id, [
                'paid_on' => '2026-10-06',
                'sender_name' => 'Отправитель',
            ])->assertRedirect();

        $payment = Payment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status, 'курс без групп доступа — fail-closed pending');
    }

    public function test_trust_kill_switch_forces_pending(): void
    {
        config()->set('services.gasuns_pay.trust_existing_students', false);
        $user = User::factory()->create(['created_at' => now()->subDays(8)]);
        $this->tariff->course->groups()->create(['name' => 'Группа вторника']);

        $this->actingAs($user)
            ->post('/gasuns-pay/'.$this->tariff->id, [
                'paid_on' => '2026-10-06',
                'sender_name' => 'Отправитель',
            ])->assertRedirect();

        $this->assertSame(
            'pending',
            Payment::query()->where('user_id', $user->id)->value('status'),
            'kill-switch газанул авто-доверие'
        );
    }
}
