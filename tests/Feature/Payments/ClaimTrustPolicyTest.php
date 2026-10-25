<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\ClaimTrustPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * H6395 lever 2 (metric M10, GTD row WHE) — claim auto-trust policy.
 *
 * `ClaimTrustPolicy` держал 0/12 statements (0 %) на money-матрице: единственный
 * полностью непокрытый класс на границе доверия денежного контура. Политика
 * отвечает на один вопрос — можно ли заявку (PayPal/bank claim) считать
 * auto-trusted-paid сразу, или она обязана идти в ручную сверку.
 *
 * Зафиксированный контракт:
 *  - хотя бы один PAID-платеж (Payment::PAID_STATUSES) ⇒ доверие, независимо от возраста;
 *  - иначе нужен возраст аккаунта ≥ порога (по умолчанию 24 ч, включительно);
 *  - НЕ-paid статусы (pending/failed/cancelled) доверия не дают;
 *  - порог берётся из конфиг-неймспейса канала и зажимается снизу в 0;
 *  - created_at = null ⇒ доверия нет.
 *
 * ВАЖНО (см. Delivery отчёта H6395-lever-2): на origin/main класс НЕ вызывается
 * ни из одного контроллера — живые каналы используют
 * User::isEstablishedClaimStudent(). Эти тесты фиксируют поведение политики как
 * библиотечного класса и НЕ доказывают, что прод ходит именно через неё.
 *
 * Отдельно зафиксирована ловушка неймспейса: docblock класса называет
 * 'paypal'/'bank_claim', а реальные ключи конфига — 'services.paypal' и
 * 'services.bank_claim'. Голое имя молча падает в дефолт 24 ч, поэтому
 * вызывающий обязан передавать полный путь.
 */
final class ClaimTrustPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL_NS = 'services.paypal';

    private const BANK_NS = 'services.bank_claim';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set(self::PAYPAL_NS.'.trust_min_account_age_hours', ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);
        Config::set(self::BANK_NS.'.trust_min_account_age_hours', ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);
    }

    private function policy(): ClaimTrustPolicy
    {
        return new ClaimTrustPolicy();
    }

    /** Аккаунт заданного возраста; null ⇒ created_at снят. */
    private function agedUser(?int $hoursAgo): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'created_at' => $hoursAgo === null ? null : now()->subHours($hoursAgo),
        ])->saveQuietly();

        return $user->refresh();
    }

    private function payment(User $user, string $status): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->getKey(),
            'course_id' => Course::factory()->create()->getKey(),
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => $status,
        ]));
    }

    // ------------------------------------------------------------ money proof

    public function test_paid_payment_grants_trust_to_a_brand_new_account(): void
    {
        $user = $this->agedUser(0);
        $this->payment($user, 'paid');

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_success_status_also_counts_as_a_paid_payment(): void
    {
        // Payment::PAID_STATUSES = ['paid', 'success'] — оба статуса означают
        // проверенный денежный контур, скипать нельзя.
        $user = $this->agedUser(0);
        $this->payment($user, 'success');

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::BANK_NS));
    }

    public function test_non_paid_statuses_never_grant_trust(): void
    {
        $user = $this->agedUser(0);

        foreach (['pending', 'failed', 'cancelled', 'refunded'] as $status) {
            $this->payment($user, $status);
        }

        $this->assertFalse(
            $this->policy()->isPreexistingVerified($user, self::PAYPAL_NS),
            'pending/failed/cancelled заявка не должна открывать auto-trust',
        );
    }

    public function test_paid_payment_of_another_user_does_not_launder_trust(): void
    {
        $other = $this->agedUser(0);
        $this->payment($other, 'paid');

        $fresh = $this->agedUser(0);

        $this->assertFalse($this->policy()->isPreexistingVerified($fresh, self::PAYPAL_NS));
    }

    // --------------------------------------------------------------- age gate

    public function test_account_older_than_the_default_window_is_trusted(): void
    {
        $user = $this->agedUser(ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS + 1);

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_account_younger_than_the_default_window_is_not_trusted(): void
    {
        $user = $this->agedUser(ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS - 1);

        $this->assertFalse($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_window_boundary_is_inclusive(): void
    {
        // lte(), не lt(): ровно на пороге доверие уже даётся.
        $user = $this->agedUser(ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_null_created_at_is_never_trusted(): void
    {
        $user = $this->agedUser(null);

        $this->assertFalse($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    // ------------------------------------------------------ channel namespace

    public function test_configured_threshold_per_channel_is_respected(): void
    {
        Config::set(self::PAYPAL_NS.'.trust_min_account_age_hours', 48);
        Config::set(self::BANK_NS.'.trust_min_account_age_hours', 2);

        $user = $this->agedUser(3);

        $this->assertFalse(
            $this->policy()->isPreexistingVerified($user, self::PAYPAL_NS),
            'paypal: 3 ч < 48 ч ⇒ не доверяем',
        );
        $this->assertTrue(
            $this->policy()->isPreexistingVerified($user, self::BANK_NS),
            'bank_claim: 3 ч ≥ 2 ч ⇒ доверяем',
        );
    }

    public function test_zero_threshold_trusts_any_persisted_account(): void
    {
        Config::set(self::PAYPAL_NS.'.trust_min_account_age_hours', 0);

        $user = $this->agedUser(0);

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_negative_threshold_is_clamped_to_zero(): void
    {
        // max(0, …) — отрицательный env не должен ломать сравнение.
        Config::set(self::PAYPAL_NS.'.trust_min_account_age_hours', -5);

        $user = $this->agedUser(0);

        $this->assertTrue($this->policy()->isPreexistingVerified($user, self::PAYPAL_NS));
    }

    public function test_bare_namespace_documented_in_the_class_falls_back_to_default(): void
    {
        // Ловушка: docblock класса обещает 'paypal'/'bank_claim', а ключи лежат
        // под 'services.*'. Голое имя не находит конфиг и молча берёт дефолт 24 ч
        // — то есть заданный в env порог был бы проигнорирован.
        Config::set(self::PAYPAL_NS.'.trust_min_account_age_hours', 48);

        $user = $this->agedUser(ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS + 1);

        $this->assertTrue(
            $this->policy()->isPreexistingVerified($user, 'paypal'),
            'голое имя "paypal" читает несуществующий ключ ⇒ дефолт 24 ч, а не настроенные 48 ч',
        );
    }
}
