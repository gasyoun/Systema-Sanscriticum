<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTelegramMessageJob;
use App\Mail\GiftCertificateIssuedMail;
use App\Models\GiftCertificate;
use App\Models\Payment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Подарочные сертификаты (H3334): выпуск одноразового кода при оплате и
 * активация получателем через СУЩЕСТВУЮЩУЮ тарифную модель.
 *
 * Инварианты:
 *  - код одноразовый: активация под row-lock, второй проход по тому же коду
 *    отвергается; в БД живёт только sha256-хэш — сырой код не персистится
 *    нигде (БД, логи) и уходит покупателю ровно один раз письмом;
 *  - доступ открывается НЕ мимо тарифной модели: активация создаёт получателю
 *    обычный оплаченный Payment с ключом Tariff::accessKey(), и дальше работает
 *    стандартный контур PaymentObserver::grantAccess() → группы → уроки;
 *  - возврат оплаты покупателем отзывает сертификат (revoked) до активации.
 */
class GiftCertificateService
{
    /** Без визуально двусмысленных знаков (0/O, 1/I/L): 29 символов. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Длина случайной части кода: 29^16 ≈ 8e23 (~79 бит) — перебор исключён. */
    private const CODE_LENGTH = 16;

    /**
     * Шов для репетиции/тестов: детерминированный код вместо случайного.
     * Статический намеренно: Payment::booted() резолвит сервис через app()
     * в обход любого инстанса, у которого шов выставлен; в проде шов всегда
     * null (никто его не трогает) — работает random_int.
     */
    public static ?\Closure $codeFactory = null;

    public function generateCode(): string
    {
        if (self::$codeFactory !== null) {
            return (self::$codeFactory)();
        }

        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return 'GIFT-'.implode('-', str_split($raw, 4));
    }

    /**
     * Нормализация ввода: регистр/пробелы/дефисы не влияют на валидность.
     * Один и тот же алгоритм для выпуска и активации.
     */
    public function normalizeCode(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    public function hashCode(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }

    /**
     * Выпуск сертификата по ОПЛАЧЕННОМУ платежу-покупке (payments.tariff='gift').
     * Идемпотентно по payment_id: повторный paid-переход/вебхук не плодит строк
     * и не перегенерирует код (сырой код вообще существует только при первом выпуске).
     */
    public function issueForPayment(Payment $payment): ?GiftCertificate
    {
        $existing = GiftCertificate::query()->where('payment_id', $payment->id)->first();
        if ($existing instanceof GiftCertificate) {
            return $existing;
        }

        // Снимок «что подарено» пишется в claim_meta при чекауте; платежи,
        // созданные вручную мимо чекаута (rehearsal/staff), несут его там же.
        $meta = is_array($payment->claim_meta) ? $payment->claim_meta : [];
        $tariffKey = (string) ($meta['gift_tariff_key'] ?? '');
        $tariffTitle = (string) ($meta['gift_tariff_title'] ?? '');

        if ($tariffKey === '') {
            Log::error('Gift certificate issuance refused: payment has no gift snapshot', [
                'payment_id' => $payment->id,
            ]);

            throw new RuntimeException('Платёж #'.$payment->id.' помечен как подарок, но не содержит снимка тарифа.');
        }

        $code = $this->generateCode();
        $number = $this->generateNumber();

        $certificate = DB::transaction(function () use ($payment, $meta, $tariffKey, $tariffTitle, $code, $number): GiftCertificate {
            $created = GiftCertificate::query()->create([
                'payment_id' => $payment->id,
                'course_id' => $payment->course_id,
                'tariff_key' => $tariffKey,
                'tariff_title' => $tariffTitle !== '' ? $tariffTitle : 'Подарочный сертификат',
                'price' => (float) $payment->amount,
                'start_block' => isset($meta['gift_start_block']) ? (int) $meta['gift_start_block'] : null,
                'end_block' => isset($meta['gift_end_block']) ? (int) $meta['gift_end_block'] : null,
                // Хэш нормализованного кода: активация сравнивает хэш-в-хэш,
                // сырой код в таблице не появляется никогда.
                'code_hash' => $this->hashCode($this->normalizeCode($code)),
                'code_hint' => substr($this->normalizeCode($code), -4),
                'number' => $number,
                'status' => GiftCertificate::STATUS_ACTIVE,
            ]);

            return $created;
        });

        // Сырой код уходит ТОЛЬКО сюда: письмо покупателю с PDF и инструкцией.
        // Ни логов, ни Telegram-текста с кодом, ни второго места хранения.
        if ($payment->user && filled($payment->user->email)) {
            Mail::to($payment->user->email)
                ->send(new GiftCertificateIssuedMail($payment->user, $certificate, $code));
        }

        if ($payment->user_id) {
            // Telegram покупателю — только указатель (без сырого кода): код
            // канонично живёт в письме, TG-история не становится вторым хранилищем.
            SendTelegramMessageJob::dispatch(
                $payment->user_id,
                "🎁 <b>Подарочный сертификат оформлен</b>\n\n"
                ."Намасте! Сертификат «{$certificate->grantsLabel()}» готов. "
                .'Код активации и красивый PDF отправлены на вашу почту — '
                .'передайте их получателю в удобный момент.'
            );
        }

        return $certificate;
    }

    /**
     * Активация кода получателем. Атомарная одноразовость: строка лочится
     * FOR UPDATE, статус перепроверяется уже под замком; конкурентный второй
     * проход ждёт замка и видит activated → отказ.
     *
     * Возвращает сертификат; бросает DomainException с человекочитаемой причиной.
     */
    public function redeem(string $rawCode, User $recipient): GiftCertificate
    {
        $normalized = $this->normalizeCode($rawCode);

        if (mb_strlen($normalized) !== self::CODE_LENGTH + 4 || ! str_starts_with($normalized, 'GIFT')) {
            throw new \DomainException('Неверный формат кода. Код выглядит так: GIFT-XXXX-XXXX-XXXX-XXXX.');
        }

        return DB::transaction(function () use ($normalized, $recipient): GiftCertificate {
            /** @var GiftCertificate|null $certificate */
            $certificate = GiftCertificate::query()
                ->where('code_hash', $this->hashCode($normalized))
                ->lockForUpdate()
                ->first();

            if (! $certificate instanceof GiftCertificate) {
                throw new \DomainException('Сертификат с таким кодом не найден. Проверьте код — без пробелов и с верными символами.');
            }

            if ($certificate->status === GiftCertificate::STATUS_ACTIVATED) {
                throw new \DomainException('Этот сертификат уже активирован. Код одноразовый.');
            }

            if ($certificate->status === GiftCertificate::STATUS_REVOKED) {
                throw new \DomainException('Сертификат отозван (оплата возвращена). Обратитесь к тому, кто вам его подарил.');
            }

            // Доступ — строго через тарифную модель: получателю создаётся обычный
            // оплаченный платёж с ключом доступа подаренного тарифа; группы, запись
            // на курс, членство и письма выдаёт штатный PaymentObserver-контур.
            // deposit_credit_applied=0 (не null!): null означал бы легаси-ветку
            // «погасить ВСЕ депозиты получателя» — за подарок чужую бронь не трогаем.
            $recipientPayment = Payment::create([
                'user_id' => $recipient->id,
                'course_id' => $certificate->course_id,
                'amount' => 0,
                'deposit_credit_applied' => 0.0,
                'tariff' => $certificate->tariff_key,
                'status' => 'paid',
                'start_block' => $certificate->start_block,
                'end_block' => $certificate->end_block,
                'claim_meta' => ['gift_certificate_id' => $certificate->id],
            ]);

            $certificate->update([
                'status' => GiftCertificate::STATUS_ACTIVATED,
                'activated_by_user_id' => $recipient->id,
                'activated_at' => now(),
                'recipient_payment_id' => $recipientPayment->id,
            ]);

            return $certificate->refresh();
        });
    }

    /**
     * Возврат/отмена оплаты покупателя → сертификат отзывается ДО активации.
     * Уже активированный НЕ отзываем задним числом: доступ получателя выдан
     * отдельным платежом, его судьба решается обычным money-core контуром.
     */
    public function revokeForPayment(Payment $payment): void
    {
        GiftCertificate::query()
            ->where('payment_id', $payment->id)
            ->where('status', GiftCertificate::STATUS_ACTIVE)
            ->update(['status' => GiftCertificate::STATUS_REVOKED]);
    }

    /** PDF-артефакт через готовый DomPDF-контур (тот же стек, что CertificateService). */
    public function renderPdf(GiftCertificate $certificate): \Barryvdh\DomPDF\PDF
    {
        $verifyUrl = route('gift.verify', ['number' => $certificate->number]);
        $qrImage = null;

        try {
            // TLS-верификацию не отключаем: QR кодирует verify-URL, подменённый
            // по MITM QR увёл бы получателя на чужой адрес (см. CertificateService).
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.urlencode($verifyUrl));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $imgData = curl_exec($ch);
            curl_close($ch);
            if ($imgData) {
                $qrImage = 'data:image/png;base64,'.base64_encode($imgData);
            }
        } catch (\Exception $e) {
            // Без QR сертификат остаётся валидным — номер и ссылка напечатаны текстом.
        }

        $pdf = Pdf::loadView('certificates.gift', [
            'certificate' => $certificate,
            'buyerName' => $certificate->payment?->user?->name ?? '',
            'verifyUrl' => $verifyUrl,
            'qr_image' => $qrImage,
            'date' => $certificate->created_at?->format('d.m.Y') ?? now()->format('d.m.Y'),
        ]);

        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Serif',
            'dpi' => 96,
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf;
    }

    /**
     * Публичный номер для PDF/верификации: GC- + 12 случайных знаков.
     * Не последовательный — чтобы страницу верификации нельзя было
     * перебирать инкрементом id. Коллизии гасим повтором.
     */
    private function generateNumber(): string
    {
        do {
            $number = 'GC-'.strtoupper(Str::random(12));
        } while (GiftCertificate::query()->where('number', $number)->exists());

        return $number;
    }
}
