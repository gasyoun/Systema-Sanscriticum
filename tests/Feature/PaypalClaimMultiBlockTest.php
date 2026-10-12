<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Mail\PaypalClaimReceivedMail;
use App\Mail\PaypalClaimStudentAckMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CuratorNotifier;
use App\Services\Payments\PaypalClaimAmountCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H6534: один PayPal-перевод покрывает несколько блоков курса (кейс
 * 12-10-2026 — 250 € за 4 блока по 60 € легли одной заявкой на блок 1 с
 * вердиктом beyond_5). Ожидаемая сумма = сумма expectedPrice() выбранных
 * тарифов, вердикт — по общей сумме; создаётся один Payment на каждый
 * выбранный тариф; одиночная заявка ведёт себя как раньше.
 */
class PaypalClaimMultiBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        config([
            'services.paypal.enabled' => true,
            'services.admin.email' => 'admin@example.test',
            'features.payment_fix_wave1' => true,
        ]);
    }

    /** @return array{0: Course, 1: array<int, Tariff>} */
    private function courseWithBlocks(int $count = 3, float $price = 4800): array
    {
        $course = Course::factory()->create();
        $tariffs = [];
        foreach (range(1, $count) as $number) {
            $tariffs[$number] = Tariff::factory()->for($course)->block($number)->create(['price' => $price]);
        }

        // Legacy-путь прайса (флаг fixed price list выключен): курс → €/$.
        config(['services.paypal.foreign_block_prices' => [$course->id => ['eur' => 60, 'usd' => 65]]]);

        return [$course, $tariffs];
    }

    /** @param  array<int, Tariff>  $tariffs */
    private function postClaim(array $tariffs, array $overrides = []): TestResponse
    {
        $anchor = $tariffs[0];

        return $this->post(route('paypal.claim.store', $anchor), array_merge([
            'foreign_amount' => 60 * count($tariffs),
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-MULTI-1',
            'blocks' => array_map(fn (Tariff $t) => $t->id, $tariffs),
        ], $overrides));
    }

    /** @test */
    public function guest_multi_block_claim_creates_one_pending_payment_per_selected_tariff(): void
    {
        [, $tariffs] = $this->courseWithBlocks();

        $this->postClaim([$tariffs[1], $tariffs[2]], [
            'name' => 'Анна',
            'email' => 'anna@example.test',
        ])
            ->assertRedirect(route('paypal.claim.show', $tariffs[1]))
            ->assertSessionHas('success');

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->orderBy('id')->get();
        $this->assertSame(2, $payments->count(), 'Один Payment на каждый выбранный тариф.');

        $first = $payments[0];
        $second = $payments[1];

        $this->assertSame('pending', $first->status);
        $this->assertSame('block_1', $first->tariff);
        $this->assertSame(1, $first->start_block);
        $this->assertSame(1, $first->end_block);
        $this->assertSame(4800.0, (float) $first->amount, 'Рублёвый номинал СВОЕГО тарифа.');
        $this->assertSame(60.0, (float) $first->foreign_amount, 'Иностранная цена СВОЕГО тарифа, не общая сумма.');
        $this->assertSame('block_2', $second->tariff);
        $this->assertSame(2, $second->start_block);
        $this->assertSame(60.0, (float) $second->foreign_amount);

        foreach ([$first, $second] as $payment) {
            $this->assertSame('TX-MULTI-1', $payment->claimMeta('txn'), 'Общий txn у всех строк.');
            $this->assertTrue((bool) $payment->claimMeta('multi_block'));
            $this->assertSame([1, 2], array_column($payment->claimMeta('block_tariffs'), 'block'), 'Список блоков в claim_meta.');
            $this->assertSame(120.0, (float) $payment->claimMeta('foreign_total'));
            $this->assertSame('exact', $payment->claimMeta('amount_check')['verdict'], 'Сверка по общей сумме.');
        }

        // pending НЕ открывает доступ.
        $this->assertSame(0, $first->user->groups()->count());

        // replay-ключи различаются, первая строка держит канонический ключ перевода.
        $this->assertNotSame($first->claim_replay_key, $second->claim_replay_key);
        $this->assertSame(
            PaypalClaimAmountCheck::replayKey((int) $first->user_id, (int) $tariffs[1]->id, 'TX-MULTI-1', '2026-10-12', 'EUR', 60.0),
            $first->claim_replay_key,
        );

        // Один комплект уведомлений на один перевод.
        Mail::assertQueued(PaypalClaimStudentAckMail::class, 1);
        Mail::assertQueued(PaypalClaimReceivedMail::class, 1);
    }

    /** @test */
    public function established_student_multi_block_exact_claim_is_paid_with_access(): void
    {
        [$course, $tariffs] = $this->courseWithBlocks(2);
        $group = Group::factory()->create();
        $group->courses()->attach($course);
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(2, $payments->count());
        $payments->each(fn (Payment $p) => $this->assertSame('paid', $p->status, 'trusted → сразу paid.'));
        $this->assertNotNull($payments[0]->claimMeta('trusted_at'));
        $this->assertTrue(
            $user->fresh()->courses()->where('courses.id', $course->id)->exists(),
            'Доступ открыт штатным Payment::booted().'
        );
    }

    /** @test */
    public function multi_block_transfer_beyond_tolerance_stays_pending_even_for_established(): void
    {
        // Живой кейс H6534 в миниатюре: переведено больше суммы блоков + 5%.
        [$course, $tariffs] = $this->courseWithBlocks(2);
        $group = Group::factory()->create();
        $group->courses()->attach($course);
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]], ['foreign_amount' => 127])
            ->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(2, $payments->count());
        $payments->each(fn (Payment $p) => $this->assertSame('pending', $p->status, 'beyond_5 → ручная сверка.'));
        $this->assertSame('beyond_5', $payments[0]->claimMeta('amount_check')['verdict']);
        $this->assertNull($payments[0]->claimMeta('auto_trusted'));
        $this->assertFalse(
            $user->fresh()->courses()->where('courses.id', $course->id)->exists(),
            'Авто-доверие снято — доступ не открыт.'
        );
    }

    /** @test */
    public function trusted_multi_block_underpaid_within_tolerance_is_paid_with_notice(): void
    {
        [$course, $tariffs] = $this->courseWithBlocks(2);
        $group = Group::factory()->create();
        $group->courses()->attach($course);
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $response = $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]], ['foreign_amount' => 118]);
        $response->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $payments->each(fn (Payment $p) => $this->assertSame('paid', $p->status));
        $this->assertSame('underpaid_within_5', $payments[0]->claimMeta('amount_check')['verdict']);

        $success = $response->getSession()->get('success');
        $this->assertStringContainsString('меньше цены', (string) $success, 'D4: уведомление о недоплате в success.');
    }

    /** @test */
    public function duplicate_multi_block_claim_same_txn_is_rejected(): void
    {
        [, $tariffs] = $this->courseWithBlocks(2);
        $user = User::factory()->create();

        $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]])->assertSessionHasNoErrors();
        $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]])
            ->assertSessionHasErrors('paypal_txn');

        $this->assertSame(2, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function same_txn_single_claim_after_multi_block_claim_is_rejected(): void
    {
        // Канонический ключ перевода держит первая строка мульти-клейма:
        // тот же txn нельзя второй раз заявить одиночной формой по другому блоку.
        [, $tariffs] = $this->courseWithBlocks(3);
        $user = User::factory()->create();

        $this->actingAs($user)->postClaim([$tariffs[1], $tariffs[2]])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariffs[3]), [
            'foreign_amount' => 60,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-MULTI-1',
        ])->assertSessionHasErrors('paypal_txn');

        $this->assertSame(2, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function blocks_from_another_course_are_rejected(): void
    {
        [, $tariffs] = $this->courseWithBlocks(2);
        $foreign = Tariff::factory()->for(Course::factory())->block(1)->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postClaim([$tariffs[1], $foreign])
            ->assertSessionHasErrors('blocks');

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function claim_without_blocks_keeps_single_payment_shape(): void
    {
        // Back-compat: без blocks[] одиночный путь бит-в-бит, без multi-меток.
        [, $tariffs] = $this->courseWithBlocks(3);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariffs[1]), [
            'foreign_amount' => 60,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-SINGLE-1',
        ])->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(1, $payments->count());
        $this->assertNull($payments[0]->claimMeta('multi_block'));
        $this->assertNull($payments[0]->claimMeta('block_tariffs'));
        $this->assertSame(60.0, (float) $payments[0]->foreign_amount, 'Одиночная заявка хранит заявленную сумму.');
    }

    /** @test */
    public function single_selected_block_claims_that_block_not_the_anchor(): void
    {
        // Открыли страницу блока 1, отметили только блок 2 → заявка по блоку 2,
        // но в одиночной форме: без multi-меток, foreign_amount = заявленная сумма.
        [, $tariffs] = $this->courseWithBlocks(3);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariffs[1]), [
            'foreign_amount' => 60,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-SINGLE-2',
            'blocks' => [$tariffs[2]->id],
        ])->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(1, $payments->count());
        $this->assertSame('block_2', $payments[0]->tariff);
        $this->assertSame(2, $payments[0]->start_block);
        $this->assertNull($payments[0]->claimMeta('multi_block'), 'Один блок — обычная заявка без multi-меток.');
        $this->assertSame(60.0, (float) $payments[0]->foreign_amount);
    }

    /** @test */
    public function claim_page_shows_multi_block_selector_with_prices(): void
    {
        [, $tariffs] = $this->courseWithBlocks(3);

        $this->get(route('paypal.claim.show', $tariffs[1]))
            ->assertOk()
            ->assertSee('name="blocks[]"', false)
            ->assertSee('multi-block-total', false)
            ->assertSee('multi-block-checkbox', false)
            ->assertSee('60 €', false);
    }

    /** @test */
    public function single_block_course_and_supplement_page_have_no_selector(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(1)->create();
        config(['services.paypal.foreign_block_prices' => [$course->id => ['eur' => 60, 'usd' => 65]]]);

        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertDontSee('name="blocks[]"', false);

        [, $tariffs] = $this->courseWithBlocks(3);
        $this->get(route('paypal.claim.supplement', $tariffs[1]))
            ->assertOk()
            ->assertDontSee('name="blocks[]"', false);
    }

    /** @test */
    public function curator_card_shows_multi_block_breakdown(): void
    {
        config(['services.telegram.curators_chat_id' => '-100999', 'services.telegram.bot_token' => 'test-token']);
        [, $tariffs] = $this->courseWithBlocks(2);
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariffs[1]->course_id,
            'amount' => 4800,
            'foreign_amount' => 60,
            'foreign_currency' => 'EUR',
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => 'pending',
            'provider' => Payment::PROVIDER_PAYPAL,
            'claim_meta' => [
                'multi_block' => true,
                'txn' => 'TX-CARD',
                'foreign_total' => 120.0,
                'block_tariffs' => [
                    ['tariff_id' => $tariffs[1]->id, 'label' => 'Блок 1', 'block' => 1, 'amount' => 4800.0, 'foreign_amount' => 60.0],
                    ['tariff_id' => $tariffs[2]->id, 'label' => 'Блок 2', 'block' => 2, 'amount' => 4800.0, 'foreign_amount' => 60.0],
                ],
                'amount_check' => ['verdict' => 'exact'],
            ],
        ]);

        app(CuratorNotifier::class)->paypalClaimReceived($payment);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => str_contains($job->text, 'Один перевод на несколько блоков')
            && str_contains($job->text, 'Блок 2')
            && str_contains($job->text, '120.00 €')
            && str_contains($job->text, 'exact'));
    }
}
