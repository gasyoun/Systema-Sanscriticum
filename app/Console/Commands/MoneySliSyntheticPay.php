<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\MoneySli\MoneySliAlerter;
use App\Support\MoneySli\MoneySliFixture;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H4672 — daily money-axis SLI: mocked (never live-bank) synthetic Tochka
 * webhook against a dedicated hidden user/course, proving the real
 * webhook→grantAccess code path end to end without moving real money
 * (amount is always 0; signed with a dedicated SLI keypair, never the real
 * Tochka bank key — see WebhookController::tryDecodeWithSliKey). Ruling
 * MG 14-09-2026: a live bank charge/refund loop stays a human-triggered
 * ops action, out of scope for an autonomous agent.
 *
 * Flap-tolerant (mirrors probeOutboundPaymentTls, CHANGELOG 02-09-2026):
 * retries money_sli.attempts times with a short pause before declaring the
 * run failed and paging — a single transient failure must not wake anyone.
 */
class MoneySliSyntheticPay extends Command
{
    protected $signature = 'money:sli-synthetic-pay
        {--dry : Прогнать без реальных POST к webhook и без TG/heartbeat}
        {--force-alert : Игнорировать TG-cooldown}';

    protected $description = 'H4672: ежесуточный мок-платёж (webhook→grantAccess), без живого банка';

    public function handle(MoneySliFixture $fixture, MoneySliAlerter $alerter): int
    {
        if (! config('features.money_sli_synthetic_pay')) {
            $this->comment('features.money_sli_synthetic_pay OFF — команда no-op до MONEY_SLI_SYNTHETIC_PAY=true (см. DEPLOY_QUEUE H4672).');

            return self::SUCCESS;
        }

        $privateKey = trim((string) config('money_sli.webhook_private_key', ''));
        if ($privateKey === '') {
            $this->error('money_sli.webhook_private_key пуст — не могу подписать синтетический вебхук.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $force = (bool) $this->option('force-alert');

        $user = $fixture->ensureUser();
        $course = $fixture->ensureCourseWithGroup();
        $group = $course->groups->first();

        if (! $group) {
            $this->error('H4672 fixture: у пробной группы нет курса — grantAccess не будет проверен.');

            return self::FAILURE;
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $attempts = max(1, (int) config('money_sli.attempts', 3));
        $pause = max(0, (int) config('money_sli.attempt_pause_seconds', 2));
        $webhookUrl = (string) config('money_sli.webhook_url');

        $startedAt = microtime(true);
        $success = false;
        $lastError = null;
        $httpStatus = null;
        $attemptsUsed = 0;

        try {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $attemptsUsed = $attempt;
                $jwt = $this->sign($privateKey, $payment->id);

                try {
                    $response = Http::timeout((int) config('money_sli.http_timeout_seconds', 15))
                        ->withBody($jwt, 'application/jwt')
                        ->post($webhookUrl);
                    $httpStatus = $response->status();

                    if ($response->successful()) {
                        $payment->refresh();
                        $granted = $payment->status === 'paid'
                            && $user->groups()->where('groups.id', $group->id)->exists();

                        if ($granted) {
                            $success = true;
                            break;
                        }

                        $lastError = "webhook 200, но grant не применился (payment.status={$payment->status})";
                    } else {
                        $lastError = "webhook HTTP {$httpStatus}";
                    }
                } catch (Throwable $e) {
                    $httpStatus = null;
                    $lastError = $e->getMessage();
                }

                if ($attempt < $attempts) {
                    Log::info("money:sli-synthetic-pay: попытка {$attempt}/{$attempts} неудачна, пауза {$pause}с", [
                        'error' => $lastError,
                    ]);
                    if (! $dry && $pause > 0) {
                        sleep($pause);
                    }
                }
            }
        } finally {
            // Мок-эквивалент «авто-возврат + снятие доступа» (реального
            // возврата нет — сумма всегда 0, банк не участвовал).
            $fixture->revokeAccess($user, $group);
            $payment->refresh();
            if ($payment->status === 'paid') {
                $payment->update(['status' => 'canceled']);
            }
            $fixture->pruneOldPayments($user);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $alerter->appendTsvRow([
            'date' => now()->toDateString(),
            'time_utc' => now()->utc()->toTimeString(),
            'check' => 'synthetic_pay',
            'status' => $success ? 'ok' : 'fail',
            'attempts' => $attemptsUsed,
            'latency_ms' => $latencyMs,
            'http_status' => $httpStatus ?? '',
            'payment_id' => $payment->id,
            'notes' => $success ? '' : (string) $lastError,
        ]);

        $alerter->heartbeat(
            (string) config('money_sli.daily_ping_url', ''),
            $success,
            "money:sli-synthetic-pay failed after {$attemptsUsed} attempts: {$lastError}",
            $dry,
        );

        if ($success) {
            $alerter->recovered('synthetic_pay');
            $this->info("✅ synthetic-pay зелёный (попытка {$attemptsUsed}/{$attempts}, {$latencyMs} мс).");

            return self::SUCCESS;
        }

        $alerter->alert(
            'synthetic_pay',
            'Money-axis: ежесуточный synthetic-pay не прошёл',
            [
                "Заказ №{$payment->id}, {$attemptsUsed}/{$attempts} попыток исчерпаны",
                "Последняя ошибка: {$lastError}",
                'Проверить: /api/webhooks/tochka доступен, MONEY_SLI_* env на месте, money-access-core-manual.md',
            ],
            $force,
            $dry,
        );

        $this->error("❌ synthetic-pay провален после {$attemptsUsed} попыток: {$lastError}");

        return self::FAILURE;
    }

    private function sign(string $privateKeyPem, int $paymentId): string
    {
        return JWT::encode([
            'purpose' => "Заказ №{$paymentId}",
            'status' => 'APPROVED',
            'amount' => 0,
            'paymentType' => 'card',
            'iat' => time(),
            'jti' => bin2hex(random_bytes(8)),
        ], $privateKeyPem, 'RS256');
    }
}
