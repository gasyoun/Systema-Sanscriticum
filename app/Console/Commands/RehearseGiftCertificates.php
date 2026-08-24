<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\GiftCertificate;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\GiftCertificateService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Сквозная репетиция подарочных сертификатов (H3334, приёмка «e2e-лог:
 * покупка → код → активация → доступ» + «одноразовость кода»).
 *
 * Зачем командой. Цепочка покупки размазана по чекауту, вебхуку банка и
 * странице активации — руками её не воспроизвести одинаково дважды, а на
 * проде нельзя дёргать реальные письма/Telegram и оставлять тестовые строки
 * в бухгалтерии. Команда прогоняет ВСЮ цепочку сервисного слоя (выпуск
 * сертификата по paid-платежу → активация вторым юзером → доступ через
 * группы → отказ повторной активации) внутри ОДНОЙ транзакции с откатом.
 *
 * Что НЕ делает: не шлёт письма (mail driver подменяется на log), не
 * диспатчит очереди (queue.default = null — Telegram-джобы отбрасываются);
 * живой HTTP-чекоут с банком репетицией не покрывается — он проверяется
 * человеком один раз после включения флага (см. GIFT_CERTIFICATES_PACKET).
 */
class RehearseGiftCertificates extends Command
{
    /** Детерминированные коды репетиции (формат реального, символы из алфавита). */
    private const REHEARSAL_CODES = [
        0 => 'GIFT-AAAA-BBBB-CCCC-DDDD',
        1 => 'GIFT-EEEE-FFFF-GGGG-HHHH',
    ];

    protected $signature = 'gift-certificates:rehearse';

    protected $description = 'E2e-репетиция подарка: покупка → сертификат+код → активация → доступ → одноразовость (H3334). Откат в конце.';

    /** @var list<array{step: string, verdict: string, detail: string}> */
    private array $results = [];

    private ?Payment $payment = null;

    private ?GiftCertificate $certificate = null;

    public function handle(GiftCertificateService $service): int
    {
        // Никаких побочных эффектов наружу: письма — в лог, джобы — в /dev/null.
        config(['mail.default' => 'log']);
        config(['queue.default' => 'null']);

        // Детерминированные коды: первый сертификат получает REHEARSAL_CODES[0],
        // второй — [1]; сырой код известен репетиции, как покупателю из письма.
        $codes = self::REHEARSAL_CODES;
        $next = 0;
        GiftCertificateService::$codeFactory = function () use (&$next, $codes): string {
            return $codes[$next++ % count($codes)];
        };

        $this->info('Флаг features.gift_certificates = '.(config('features.gift_certificates') ? 'ON' : 'OFF')
            .' (репетиция идёт по сервисному слою; для живых /gift/* маршрутов флаг включается отдельно).');

        DB::beginTransaction();

        try {
            $this->stepPurchaseAndPay();
            $this->stepIssue();
            $this->stepBuyerHasNoAccess();
            $this->stepActivate($service);
            $this->stepRecipientAccess();
            $this->stepSingleUse($service);
            $this->stepNormalization($service);
            $this->stepPdf($service);
            $this->stepRevokeOnRefund($service);
        } catch (Throwable $e) {
            $this->record('0. непредвиденная ошибка', 'FAIL', $e::class.': '.$e->getMessage());
        }

        DB::rollBack();

        return $this->finish();
    }

    private function stepPurchaseAndPay(): void
    {
        // Прямые create(), БЕЗ Eloquent-фабрик: прод живёт на composer --no-dev
        // (fakerphp/faker отсутствует), а репетиция обязана работать именно там.
        $course = Course::create([
            'title' => 'REHEARSE gift course',
            'slug' => 'rehearse-gift-'.$this->stamp(),
            'is_visible' => true,
            'is_active' => true,
            'format' => 'recorded',
        ]);
        $group = Group::create([
            'name' => 'Rehearse Gift Group '.$this->stamp(),
            'slug' => 'rehearse-gift-group-'.$this->stamp(),
        ]);
        $course->groups()->attach($group->id);
        Tariff::create([
            'course_id' => $course->id,
            'title' => 'Весь курс (репетиция)',
            'type' => 'full',
            'block_number' => null,
            'price' => 6000,
            'is_active' => true,
        ]);

        // Платёж-покупка ровно той формы, которую пишет чекаут в режиме
        // «подарить» (PaymentController::createPayment): tariff='gift' + снимок.
        $buyer = $this->rehearseUser('buyer');
        $this->payment = Payment::create([
            'user_id' => $buyer->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'deposit_credit_applied' => 0.0,
            'tariff' => 'gift',
            'status' => 'pending',
            'claim_meta' => [
                'gift_tariff_key' => 'full',
                'gift_tariff_title' => 'Весь курс (репетиция)',
                'gift_start_block' => null,
                'gift_end_block' => null,
            ],
        ]);

        $this->record('1. покупка (pending gift-платёж)', $this->payment->exists ? 'PASS' : 'FAIL',
            'payment #'.$this->payment->id.', tariff=gift, claim_meta со снимком тарифа');

        // Вебхук банка переводит заказ в paid — дальше всё делает Payment::booted().
        $this->payment->update(['status' => 'paid']);
    }

    private function stepIssue(): void
    {
        $certificate = GiftCertificate::query()
            ->where('payment_id', $this->payment?->id ?? 0)
            ->first();

        if (! $certificate instanceof GiftCertificate) {
            $this->record('2. выпуск сертификата', 'FAIL', 'после paid сертификат не выпущен');

            return;
        }

        $this->certificate = $certificate;

        $hashOk = preg_match('/^[a-f0-9]{64}$/', (string) $certificate->code_hash) === 1;
        $hintOk = strlen((string) $certificate->code_hint) === 4;
        $numberOk = str_starts_with((string) $certificate->number, 'GC-');
        $expectedHash = hash('sha256', str_replace('-', '', self::REHEARSAL_CODES[0]));
        $hashMatchesCode = $certificate->code_hash === $expectedHash;

        $this->record('2. выпуск сертификата',
            $hashOk && $hintOk && $numberOk && $hashMatchesCode ? 'PASS' : 'FAIL',
            "number={$certificate->number}, hash=".($hashMatchesCode ? 'sha256(код) ok' : ($hashOk ? 'формат ok, НЕ совпадает с кодом' : 'BAD'))
            .', hint='.($hintOk ? $certificate->code_hint : 'BAD'));
    }

    private function stepBuyerHasNoAccess(): void
    {
        $buyer = $this->payment?->user;
        $groupCount = $buyer ? $buyer->groups()->count() : -1;

        $this->record('3. покупателю доступ НЕ открыт',
            $groupCount === 0 ? 'PASS' : 'FAIL',
            'групп у покупателя: '.var_export($groupCount, true).' (ожидание 0)');
    }

    private function stepActivate(GiftCertificateService $service): void
    {
        if (! $this->certificate instanceof GiftCertificate) {
            $this->record('4. активация получателем', 'FAIL', 'сертификат не выпущен — шаг пропущен');

            return;
        }

        // «Грязная» форма кода из письма: строчный регистр и пробелы вместо
        // дефисов — нормализация обязана съесть разницу.
        $dirty = strtolower(str_replace('-', ' ', self::REHEARSAL_CODES[0]));
        $recipient = $this->rehearseUser('recipient');

        try {
            $activated = $service->redeem($dirty, $recipient);
        } catch (DomainException $e) {
            $this->record('4. активация получателем', 'FAIL', 'отказано: '.$e->getMessage());

            return;
        }

        $this->record('4. активация получателем',
            $activated->status === GiftCertificate::STATUS_ACTIVATED
            && $activated->activated_by_user_id === $recipient->id ? 'PASS' : 'FAIL',
            'статус='.$activated->status.', получатель #'.$recipient->id);
    }

    private function stepRecipientAccess(): void
    {
        if (! $this->certificate instanceof GiftCertificate || ! $this->payment instanceof Payment) {
            $this->record('5. доступ получателя через тарифную модель', 'FAIL', 'шаг пропущен');

            return;
        }

        // Активация меняла строку через другой инстанс — перечитываем.
        $certificate = $this->certificate->fresh();
        $recipient = User::find((int) $certificate->activated_by_user_id);

        if (! $recipient instanceof User) {
            $this->record('5. доступ получателя через тарифную модель', 'FAIL', 'получатель не найден');

            return;
        }

        // Доступ открыт РОВНО как при обычной покупке: платёж получателя несёт
        // ключ доступа ('full'), группы выданы штатным grantAccess().
        $expectedGroups = $this->payment->course?->groups()->pluck('groups.id')->all() ?? [];
        $actualGroups = $recipient->groups()->pluck('groups.id')->all();
        $enrolled = $recipient->courses()->whereKey($this->payment->course_id)->exists();

        $sameGroups = $expectedGroups !== [] && count(array_diff($expectedGroups, $actualGroups)) === 0;

        $this->record('5. доступ получателя через тарифную модель',
            $sameGroups && $enrolled ? 'PASS' : 'FAIL',
            'группы: '.count($actualGroups).'/'.count($expectedGroups).', записан на курс: '.($enrolled ? 'да' : 'нет'));
    }

    private function stepSingleUse(GiftCertificateService $service): void
    {
        if (! $this->certificate instanceof GiftCertificate) {
            $this->record('6. одноразовость кода', 'FAIL', 'шаг пропущен');

            return;
        }

        $secondUser = $this->rehearseUser('second');

        try {
            $service->redeem(self::REHEARSAL_CODES[0], $secondUser);
        } catch (DomainException $e) {
            $stillOne = $secondUser->groups()->count() === 0;

            $this->record('6. одноразовость кода',
                $stillOne ? 'PASS' : 'FAIL',
                'повторная активация отклонена («'.$e->getMessage().'»); второй юзер групп не получил: '.($stillOne ? 'да' : 'НЕТ'));

            return;
        }

        $this->record('6. одноразовость кода', 'FAIL', 'повторная активация ПРОШЛА — код многоразовый!');
    }

    private function stepNormalization(GiftCertificateService $service): void
    {
        $cases = [
            'gift-aaaa-bbbb-cccc-dddd' => true,   // нижний регистр + дефисы
            'GIFTAAAABBBBCCCCDDDD' => true,       // без разделителей
            ' gift AaAa bBbB cCcC dDdD ' => true, // мусорные пробелы
            'GIFT-AAAA-BBBB-CCCC-DDDX' => false,  // другой код
            'XXXX-AAAA-BBBB-CCCC-DDDD' => false,  // чужой префикс
        ];

        $failures = [];

        foreach ($cases as $input => $shouldMatch) {
            $normalized = $service->normalizeCode($input);
            $matches = $normalized === str_replace('-', '', self::REHEARSAL_CODES[0]);

            if ($matches !== $shouldMatch) {
                $failures[] = "'{$input}'";
            }
        }

        $this->record('7. нормализация ввода', $failures === [] ? 'PASS' : 'FAIL',
            $failures === [] ? 'регистр/дефисы/пробелы не влияют; разные коды не совпадают'
                : 'проблемные случаи: '.implode(', ', $failures));
    }

    private function stepPdf(GiftCertificateService $service): void
    {
        if (! $this->certificate instanceof GiftCertificate) {
            $this->record('8. PDF-артефакт', 'FAIL', 'шаг пропущен');

            return;
        }

        try {
            $bytes = $service->renderPdf($this->certificate)->output();
        } catch (Throwable $e) {
            $this->record('8. PDF-артефакт', 'FAIL', $e::class.': '.$e->getMessage());

            return;
        }

        $ok = is_string($bytes) && str_starts_with($bytes, '%PDF') && strlen($bytes) > 1000;

        $this->record('8. PDF-артефакт', $ok ? 'PASS' : 'FAIL',
            'DomPDF отрендерил '.strlen($bytes).' байт, номер на бланке: '.$this->certificate->number);
    }

    /**
     * Возврат оплаты покупателем → неактивированный сертификат отзывается,
     * и его код больше не принимается. Проводится НАСТОЯЩИЙ переход
     * paid → canceled, чтобы проверить боевую обвязку в Payment::booted().
     */
    private function stepRevokeOnRefund(GiftCertificateService $service): void
    {
        // Вторая покупка-репетиция на том же курсе (транзакция общая, откат общий).
        config(['queue.default' => 'null', 'mail.default' => 'log']);

        $refundBuyer = $this->rehearseUser('refund-buyer');
        $refundPayment = Payment::create([
            'user_id' => $refundBuyer->id,
            'course_id' => $this->payment?->course_id,
            'amount' => 6000,
            'deposit_credit_applied' => 0.0,
            'tariff' => 'gift',
            'status' => 'paid',
            'claim_meta' => [
                'gift_tariff_key' => 'full',
                'gift_tariff_title' => 'Весь курс (репетиция)',
            ],
        ]);

        $cert = GiftCertificate::query()->where('payment_id', $refundPayment->id)->first();

        if (! $cert instanceof GiftCertificate) {
            $this->record('9. отзыв при возврате оплаты', 'FAIL', 'второй сертификат не выпущен');

            return;
        }

        // Настоящий возврат: paid → canceled (как вебхук возврата).
        $refundPayment->update(['status' => 'canceled']);
        $cert->refresh();

        if ($cert->status !== GiftCertificate::STATUS_REVOKED) {
            $this->record('9. отзыв при возврате оплаты', 'FAIL', 'после canceled статус: '.$cert->status);

            return;
        }

        try {
            $service->redeem(self::REHEARSAL_CODES[1], $this->rehearseUser('extra'));
            $this->record('9. отзыв при возврате оплаты', 'FAIL', 'отозванный сертификат активировался');

            return;
        } catch (DomainException $e) {
            if (! str_contains($e->getMessage(), 'отозван')) {
                $this->record('9. отзыв при возврате оплаты', 'FAIL', 'не тот отказ: '.$e->getMessage());

                return;
            }
        }

        $this->record('9. отзыв при возврате оплаты', 'PASS',
            "canceled → статус={$cert->status}; активация отозванного отвергнута с верной причиной");
    }

    private function record(string $step, string $verdict, string $detail): void
    {
        $this->results[] = compact('step', 'verdict', 'detail');
    }

    private function finish(): int
    {
        $this->info('');
        foreach ($this->results as $r) {
            $color = match ($r['verdict']) {
                'PASS' => 'info',
                'FAIL' => 'error',
                default => 'warn',
            };
            $this->$color(sprintf('[%s] %s — %s', $r['verdict'], $r['step'], $r['detail']));
        }

        $failed = count(array_filter($this->results, fn (array $r): bool => $r['verdict'] === 'FAIL'));
        $this->newLine();
        $this->info(sprintf('Итого: %d PASS, %d FAIL. Все изменения репетиции откатены.',
            count($this->results) - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function rehearseUser(string $role): User
    {
        return User::create([
            'name' => 'Rehearse '.$role.' '.$this->stamp(),
            'email' => sprintf('rehearse-%s-%s@rehearse.invalid', $role, $this->stamp()),
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(16)),
            'remember_token' => Str::random(10),
        ]);
    }

    private function stamp(): string
    {
        return now()->format('Ymd-His-v');
    }
}
