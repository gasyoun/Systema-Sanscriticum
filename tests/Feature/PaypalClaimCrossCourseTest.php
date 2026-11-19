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
use Tests\TestCase;

/**
 * H6554: один PayPal-перевод покрывает блоки НЕСКОЛЬКИХ курсов (перевод
 * «на год вперёд» — за блоки другого/будущего потока, MG 12-10-2026).
 * Селектор группирует блочные тарифы по курсам (только активные блочные),
 * expected = сумма expectedPrice() выбранных, вердикт по ОБЩЕЙ сумме,
 * ОДИН pending/paid Payment на каждый тариф в СВОЁМ курсе, claim_meta
 * общий: txn + разбивка по курсам/блокам + foreign_total. Дубли/replay
 * per user+tariff+txn сохранены: канонический ключ на первой строке,
 * остальные солёные. Одно-курсовые (H6534) и одиночные клеймы — бит-в-бит.
 */
class PaypalClaimCrossCourseTest extends TestCase
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

    /**
     * Два курса: A — 3 блока по 4800 ₽ (60 €/65 $), B — 2 блока по 9600 ₽
     * (120 €/130 $). Legacy-путь прайса для обоих.
     *
     * @return array{0: Course, 1: array<int, Tariff>, 2: Course, 3: array<int, Tariff>}
     */
    private function twoCoursesWithBlocks(): array
    {
        $courseA = Course::factory()->create();
        $a = [];
        foreach (range(1, 3) as $number) {
            $a[$number] = Tariff::factory()->for($courseA)->block($number)->create(['price' => 4800]);
        }

        $courseB = Course::factory()->create();
        $b = [];
        foreach (range(1, 2) as $number) {
            $b[$number] = Tariff::factory()->for($courseB)->block($number)->create(['price' => 9600]);
        }

        config(['services.paypal.foreign_block_prices' => [
            $courseA->id => ['eur' => 60, 'usd' => 65],
            $courseB->id => ['eur' => 120, 'usd' => 130],
        ]]);

        return [$courseA, $a, $courseB, $b];
    }

    private function postCrossClaim(array $tariffs, array $overrides = [])
    {
        $anchor = $tariffs[0];

        return $this->post(route('paypal.claim.store', $anchor), array_merge([
            'foreign_amount' => 60 * count($tariffs),
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-CROSS-1',
            'blocks' => array_map(fn (Tariff $t) => $t->id, $tariffs),
        ], $overrides));
    }

    /** @test */
    public function cross_course_exact_claim_creates_one_payment_per_tariff_in_its_own_course(): void
    {
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();
        foreach ([$courseA, $courseB] as $course) {
            $group = Group::factory()->create();
            $group->courses()->attach($course);
        }
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        // 60 + 60 + 120 = 240 € — точная общая сумма за A1+A2+B1.
        $this->actingAs($user)->postCrossClaim([$a[1], $a[2], $b[1]], ['foreign_amount' => 240])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->orderBy('id')->get();
        $this->assertSame(3, $payments->count(), 'Один Payment на каждый выбранный тариф.');

        // Канонический порядок: по курсу, затем по номеру блока (A1, A2, B1).
        $this->assertSame([$courseA->id, $courseA->id, $courseB->id], $payments->pluck('course_id')->all(), 'Каждый Payment — в СВОЁМ курсе.');
        $this->assertSame(['block_1', 'block_2', 'block_1'], $payments->pluck('tariff')->all());
        $this->assertSame([4800.0, 4800.0, 9600.0], $payments->map(fn ($p) => (float) $p->amount)->all(), 'Рублёвый номинал СВОЕГО тарифа.');
        $this->assertSame([60.0, 60.0, 120.0], $payments->map(fn ($p) => (float) $p->foreign_amount)->all(), 'Иностранная цена СВОЕГО тарифа.');
        $payments->each(fn (Payment $p) => $this->assertSame('paid', $p->status, 'trusted + exact → сразу paid.'));

        foreach ($payments as $payment) {
            $this->assertSame('TX-CROSS-1', $payment->claimMeta('txn'), 'Общий txn у всех строк.');
            $this->assertTrue((bool) $payment->claimMeta('multi_block'));
            $this->assertTrue((bool) $payment->claimMeta('cross_course'), 'Кросс-курсовая метка.');
            $this->assertSame(240.0, (float) $payment->claimMeta('foreign_total'), 'Общая сумма перевода.');
            $this->assertSame('exact', $payment->claimMeta('amount_check')['verdict'], 'Вердикт по ОБЩЕЙ сумме.');
            $rows = $payment->claimMeta('block_tariffs');
            $this->assertSame($courseB->title ?? null, $rows[2]['course_title'], 'Разбивка несёт курс блока.');
        }

        // Доступ открыт в ОБОИХ курсах штатным Payment::booted().
        $this->assertTrue($user->fresh()->courses()->where('courses.id', $courseA->id)->exists());
        $this->assertTrue($user->fresh()->courses()->where('courses.id', $courseB->id)->exists());

        // Replay-ключи: первая строка — канонический ключ перевода, остальные солёные и различны.
        $keys = $payments->pluck('claim_replay_key')->all();
        $this->assertSame(
            PaypalClaimAmountCheck::replayKey((int) $user->id, (int) $a[1]->id, 'TX-CROSS-1', '2026-10-12', 'EUR', 60.0),
            $keys[0],
            'Канонический txn-ключ на первой строке.',
        );
        $this->assertSame($keys[1], hash('sha256', $keys[0].'|multi|'.$a[2]->id), 'Солёный ключ второй строки.');
        $this->assertSame($keys[2], hash('sha256', $keys[0].'|multi|'.$b[1]->id), 'Солёный ключ третьей строки.');
        $this->assertCount(3, array_unique($keys), 'Все ключи различны — unique-индекс не роняет.');

        // Один комплект уведомлений на один перевод.
        Mail::assertQueued(PaypalClaimStudentAckMail::class, 1);
        Mail::assertQueued(PaypalClaimReceivedMail::class, 1);
    }

    /** @test */
    public function cross_course_beyond_tolerance_stays_pending_even_for_established(): void
    {
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        // 300 € сильно больше 60+120=180 € — beyond_5, ручная сверка.
        $this->actingAs($user)->postCrossClaim([$a[1], $b[1]], ['foreign_amount' => 300])
            ->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(2, $payments->count());
        $payments->each(fn (Payment $p) => $this->assertSame('pending', $p->status, 'beyond_5 → ручная сверка.'));
        $this->assertSame('beyond_5', $payments[0]->claimMeta('amount_check')['verdict']);
        $this->assertNull($payments[0]->claimMeta('auto_trusted'));
    }

    /** @test */
    public function cross_course_duplicate_same_txn_is_rejected(): void
    {
        [, $a, , $b] = $this->twoCoursesWithBlocks();
        $user = User::factory()->create();

        $this->actingAs($user)->postCrossClaim([$a[1], $b[1]])->assertSessionHasNoErrors();
        $this->actingAs($user)->postCrossClaim([$a[1], $b[1]])
            ->assertSessionHasErrors('paypal_txn');

        $this->assertSame(2, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count(), 'Дубль не создаёт строк.');
    }

    /** @test */
    public function single_course_multi_claim_meta_has_no_cross_course_markers(): void
    {
        // Бит-в-бит back-compat H6534: мульти-клейм в пределах одного курса
        // не получает ни cross_course, ни course_title в разбивке.
        [, $a] = $this->twoCoursesWithBlocks();
        $user = User::factory()->create();

        $this->actingAs($user)->postCrossClaim([$a[1], $a[2]])->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(2, $payments->count());
        $this->assertNull($payments[0]->claimMeta('cross_course'), 'Одно-курсовый клейм без новой метки.');
        $rows = $payments[0]->claimMeta('block_tariffs');
        $this->assertArrayNotHasKey('course_title', $rows[0], 'Строки разбивки без course_title.');
        $this->assertArrayNotHasKey('course_title', $rows[1]);
    }

    /** @test */
    public function single_foreign_block_keeps_single_claim_shape(): void
    {
        // Один чужой блок — обычная одиночная заявка по нему: без multi-меток,
        // foreign_amount = заявленная сумма; Payment в курсе ЧУЖОГО блока.
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();
        $group = Group::factory()->create();
        $group->courses()->attach($courseB);
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($user)->post(route('paypal.claim.store', $a[1]), [
            'foreign_amount' => 120,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-10-12',
            'paypal_txn' => 'TX-CROSS-SINGLE',
            'blocks' => [$b[1]->id],
        ])->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(1, $payments->count());
        $this->assertSame($courseB->id, $payments[0]->course_id);
        $this->assertNull($payments[0]->claimMeta('multi_block'), 'Один блок — заявка без multi-меток.');
        $this->assertNull($payments[0]->claimMeta('cross_course'));
        $this->assertSame('paid', $payments[0]->status, 'Сверка по цене самого блока (120 € = exact).');
    }

    /** @test */
    public function foreign_course_without_access_groups_blocks_auto_confirm(): void
    {
        // H5442 fail-closed, H6554: проверяется КАЖДЫЙ курс выбора — курс B
        // без групп доступа не даёт trusted-paid даже при точной сумме.
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();
        $group = Group::factory()->create();
        $group->courses()->attach($courseA); // только A имеет группы
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($user)->postCrossClaim([$a[1], $b[1]], ['foreign_amount' => 180])
            ->assertSessionHasNoErrors();

        $payments = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->get();
        $this->assertSame(2, $payments->count());
        $payments->each(function (Payment $p): void {
            $this->assertSame('pending', $p->status, 'Курс без групп → не paid.');
            $this->assertSame('no_access_groups', $p->claimMeta('reconciliation_exception'));
        });
    }

    /** @test */
    public function claim_page_groups_cross_course_options_unchecked_by_default(): void
    {
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();

        $html = (string) $this->get(route('paypal.claim.show', $a[1]))
            ->assertOk()
            ->getContent();

        preg_match_all('/<input[^>]*name="blocks\[\]"[^>]*>/', $html, $inputs);
        $this->assertSame(5, count($inputs[0]), 'Селектор показывает блоки обоих курсов.');

        $checkedIds = [];
        foreach ($inputs[0] as $tag) {
            preg_match('/value="(\d+)"/', $tag, $m);
            if (str_contains($tag, 'checked')) {
                $checkedIds[] = (int) $m[1];
            }
        }
        $this->assertSame([$a[1]->id, $a[2]->id, $a[3]->id], $checkedIds, 'По умолчанию отмечены только блоки курса якоря.');
        $this->assertStringContainsString($courseB->title, $html, 'Заголовок чужого курса в селекторе.');
        $this->assertStringContainsString('120 €', $html, 'Цены чужого курса показаны.');
    }

    /** @test */
    public function curator_card_shows_cross_course_breakdown_by_courses(): void
    {
        config(['services.telegram.curators_chat_id' => '-100999', 'services.telegram.bot_token' => 'test-token']);
        [$courseA, $a, $courseB, $b] = $this->twoCoursesWithBlocks();
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $a[1]->course_id,
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
                'cross_course' => true,
                'txn' => 'TX-CROSS-CARD',
                'foreign_total' => 180.0,
                'block_tariffs' => [
                    ['tariff_id' => $a[1]->id, 'label' => 'Блок 1', 'block' => 1, 'amount' => 4800.0, 'foreign_amount' => 60.0, 'course_title' => $courseA->title],
                    ['tariff_id' => $b[1]->id, 'label' => 'Блок 1', 'block' => 1, 'amount' => 9600.0, 'foreign_amount' => 120.0, 'course_title' => $courseB->title],
                ],
                'amount_check' => ['verdict' => 'exact'],
            ],
        ]);

        app(CuratorNotifier::class)->paypalClaimReceived($payment);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => str_contains($job->text, 'Один перевод на блоки разных курсов')
            && str_contains($job->text, (string) $courseB->title)
            && str_contains($job->text, '180.00 €'));
    }
}
