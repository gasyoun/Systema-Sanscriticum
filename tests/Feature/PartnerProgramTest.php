<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CapturePartnerReferral;
use App\Models\Course;
use App\Models\Partner;
use App\Models\PartnerConversion;
use App\Models\Payment;
use App\Models\User;
use App\Services\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PartnerProgramTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_SECRET = 'partner-bot-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        // Vite is disabled globally in Tests\TestCase::setUp().
        // Программа по умолчанию ВЫКЛ — включаем для большинства тестов явно.
        config([
            'partner.enabled' => true,
            'partner.reward_amount' => 1000,
            'partner.bot_secret' => self::BOT_SECRET,
        ]);
    }

    private function activePartner(array $attrs = []): Partner
    {
        return Partner::create(array_merge([
            'name' => 'Иван Партнёров',
            'telegram_username' => '@ivan',
            'code' => Partner::generateCode(),
            'status' => Partner::STATUS_ACTIVE,
        ], $attrs));
    }

    private function clientOf(Partner $partner): User
    {
        $client = User::factory()->create();
        $client->forceFill(['partner_id' => $partner->id])->save();

        return $client->fresh();
    }

    private function paidPayment(User $user, string $status = 'paid'): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => $status,
        ]);
    }

    /** @test */
    public function partner_code_is_unique_and_prefixed(): void
    {
        $a = Partner::generateCode();
        $b = Partner::generateCode();

        $this->assertStringStartsWith('P', $a);
        $this->assertSame(8, strlen($a));
        $this->assertNotSame($a, $b);
    }

    /** @test */
    public function attach_partner_links_only_for_active_partner_and_guards_edges(): void
    {
        $service = app(PartnerService::class);
        $partner = $this->activePartner();

        // валидный код активного партнёра привязывает
        $client = User::factory()->create();
        $service->attachPartner($client, $partner->code);
        $this->assertSame($partner->id, $client->fresh()->partner_id);

        // повторно не перезаписывает
        $other = $this->activePartner(['telegram_username' => '@other']);
        $service->attachPartner($client->fresh(), $other->code);
        $this->assertSame($partner->id, $client->fresh()->partner_id);

        // несуществующий код игнорируется
        $c2 = User::factory()->create();
        $service->attachPartner($c2, 'PNOPE99');
        $this->assertNull($c2->fresh()->partner_id);

        // код неактивного (pending) партнёра игнорируется
        $pending = Partner::create([
            'name' => 'Ещё не активен', 'code' => Partner::generateCode(), 'status' => Partner::STATUS_PENDING,
        ]);
        $c3 = User::factory()->create();
        $service->attachPartner($c3, $pending->code);
        $this->assertNull($c3->fresh()->partner_id);
    }

    /** @test */
    public function partner_cannot_refer_their_own_account(): void
    {
        $self = User::factory()->create();
        $partner = $this->activePartner(['user_id' => $self->id]);

        app(PartnerService::class)->attachPartner($self, $partner->code);

        $this->assertNull($self->fresh()->partner_id);
    }

    /** @test */
    public function partner_is_rewarded_on_referred_client_first_payment(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $this->paidPayment($client); // observer.created() → reward

        $this->assertDatabaseHas('partner_conversions', [
            'partner_id' => $partner->id,
            'user_id' => $client->id,
            'reward_amount' => '1000.00',
            'status' => PartnerConversion::STATUS_ACCRUED,
        ]);
        $this->assertSame(1000.0, $partner->fresh()->amountOwed());
    }

    /** @test */
    public function per_partner_override_beats_global_rate(): void
    {
        $partner = $this->activePartner(['reward_amount_override' => 2500]);
        $client = $this->clientOf($partner);

        $this->paidPayment($client);

        $this->assertSame(2500.0, $partner->fresh()->amountOwed());
    }

    /** @test */
    /** @test */
    public function ratified_percent_of_first_payment_beats_fixed_rates(): void
    {
        // Схема А (MG 23-08): 10 % первого платежа приведённого ученика.
        $partner = $this->activePartner(['reward_percent' => 10]);
        $client = $this->clientOf($partner);

        // paidPayment создаёт платёж на 4800 -> 10 % = 480.
        $this->paidPayment($client);

        $this->assertDatabaseHas('partner_conversions', [
            'partner_id' => $partner->id,
            'user_id' => $client->id,
            'reward_amount' => '480.00',
            'status' => PartnerConversion::STATUS_ACCRUED,
        ]);
    }

    /** @test */
    public function percent_wins_over_override_when_both_filled(): void
    {
        $partner = $this->activePartner(['reward_percent' => 10, 'reward_amount_override' => 9999]);
        $client = $this->clientOf($partner);

        $this->paidPayment($client);

        $this->assertSame(480.0, $partner->fresh()->amountOwed());
    }

    public function reward_is_granted_only_once_per_client(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $this->paidPayment($client);
        $this->paidPayment($client); // вторая оплата того же клиента

        $this->assertSame(1, PartnerConversion::where('user_id', $client->id)->count());
        $this->assertSame(1000.0, $partner->fresh()->amountOwed());
    }

    /** @test */
    public function reward_fires_on_pending_to_paid_transition(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $payment = $this->paidPayment($client, 'pending');
        $this->assertSame(0.0, $partner->fresh()->amountOwed());

        $payment->update(['status' => 'paid']); // observer.updated() → reward

        $this->assertSame(1000.0, $partner->fresh()->amountOwed());
    }

    /** @test */
    public function no_reward_when_program_disabled(): void
    {
        config(['partner.enabled' => false]);
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $this->paidPayment($client);

        $this->assertSame(0, PartnerConversion::count());
    }

    /** @test */
    public function no_reward_when_partner_not_active(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);
        $partner->update(['status' => Partner::STATUS_SUSPENDED]);

        $this->paidPayment($client);

        $this->assertSame(0, PartnerConversion::count());
    }

    /** @test */
    public function no_reward_when_client_has_no_partner(): void
    {
        $client = User::factory()->create();
        $this->paidPayment($client);

        $this->assertSame(0, PartnerConversion::count());
    }

    /**
     * @test
     *
     * @dataProvider nonQualifyingPayments
     */
    public function no_reward_on_non_course_payments(array $attributes): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        Payment::create(array_merge([
            'user_id' => $client->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ], $attributes));

        $this->assertSame(0, PartnerConversion::where('user_id', $client->id)->count());
    }

    public static function nonQualifyingPayments(): array
    {
        return [
            'deposit' => [['tariff' => 'deposit']],
            'trial' => [['tariff' => 'trial']],
            'expense' => [['tariff' => 'Расход', 'amount' => -1000]],
            'salary_payout' => [['tariff' => 'salary_payout', 'amount' => -5000]],
            'conditional promise (0₽)' => [['amount' => 0, 'is_conditional' => true]],
            'zero-amount order' => [['amount' => 0]],
            'no course_id' => [['course_id' => null]],
        ];
    }

    /** @test */
    public function accrued_reward_is_clawed_back_when_payment_reversed(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $payment = $this->paidPayment($client);
        $this->assertSame(1000.0, $partner->fresh()->amountOwed());

        $payment->update(['status' => 'failed']);

        $this->assertSame(0.0, $partner->fresh()->amountOwed());
        $this->assertSame(0, PartnerConversion::where('user_id', $client->id)->count());

        // Слот свободен — новая оплата снова награждает.
        $this->paidPayment($client);
        $this->assertSame(1000.0, $partner->fresh()->amountOwed());
    }

    /** @test */
    public function already_paid_out_reward_is_not_clawed_back_on_reversal(): void
    {
        $partner = $this->activePartner();
        $client = $this->clientOf($partner);

        $payment = $this->paidPayment($client);
        // Партнёру уже выплатили.
        PartnerConversion::where('user_id', $client->id)->update([
            'status' => PartnerConversion::STATUS_PAID_OUT,
            'paid_out_at' => now(),
        ]);

        $payment->update(['status' => 'canceled']);

        // Факт выплаты не откатываем автоматически — строка остаётся.
        $this->assertSame(1, PartnerConversion::where('user_id', $client->id)
            ->where('status', PartnerConversion::STATUS_PAID_OUT)->count());
    }

    /** @test */
    public function middleware_stores_pref_and_attaches_authenticated_client(): void
    {
        $partner = $this->activePartner();
        $client = User::factory()->create();

        $request = Request::create('/?pref='.$partner->code, 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => $client);

        (new CapturePartnerReferral)->handle($request, fn ($r) => response('ok'));

        $this->assertSame($partner->code, $request->session()->get('pref'));
        $this->assertSame($partner->id, $client->fresh()->partner_id);
    }

    /** @test */
    public function clean_partner_link_sets_pref_and_redirects_home(): void
    {
        $partner = $this->activePartner();

        $this->get('/mitram/'.$partner->code)
            ->assertRedirect('/');

        $this->assertSame($partner->code, session('pref'));
    }

    /** @test */
    public function landing_is_hidden_when_disabled_and_visible_when_enabled(): void
    {
        config(['partner.enabled' => false]);
        $this->get('/partners')->assertNotFound();

        config(['partner.enabled' => true]);
        $this->get('/partners')->assertOk()->assertSee('Партнерская программа');
    }

    /** @test */
    public function public_registration_creates_pending_partner(): void
    {
        $response = $this->post('/partners/register', [
            'name' => 'Новый Партнёр',
            'telegram_username' => 'newpartner',
        ]);

        $partner = Partner::where('name', 'Новый Партнёр')->first();
        $this->assertNotNull($partner);
        $this->assertSame(Partner::STATUS_PENDING, $partner->status);
        $this->assertSame('@newpartner', $partner->telegram_username);
        $response->assertRedirect(route('partners.registered', ['code' => $partner->code]));
    }

    /** @test */
    public function registration_requires_at_least_one_contact(): void
    {
        $this->post('/partners/register', ['name' => 'Без контактов'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Partner::where('name', 'Без контактов')->count());
    }

    /** @test */
    public function bot_register_is_idempotent_by_telegram(): void
    {
        $first = $this->withHeader('X-Partner-Bot-Secret', self::BOT_SECRET)
            ->postJson('/api/partner-bot/register', [
                'name' => 'Бот Партнёр',
                'telegram_username' => 'botpartner',
            ]);
        $first->assertOk()->assertJsonPath('created', true);
        $code = $first->json('partner.code');

        $second = $this->withHeader('X-Partner-Bot-Secret', self::BOT_SECRET)
            ->postJson('/api/partner-bot/register', [
                'name' => 'Бот Партнёр',
                'telegram_username' => '@botpartner',
            ]);
        $second->assertOk()->assertJsonPath('created', false)->assertJsonPath('partner.code', $code);

        $this->assertSame(1, Partner::where('telegram_username', '@botpartner')->count());
    }
}
