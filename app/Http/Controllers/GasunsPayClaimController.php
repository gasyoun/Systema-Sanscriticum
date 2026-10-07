<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGasunsPayRequest;
use App\Mail\GasunsPayReceivedMail;
use App\Mail\GasunsPayStudentAckMail;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\CuratorNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * H6198 — анкета-уведомление «я перевёл рубли Гасунсу лично» (мимо Точки):
 * зеркало /teacher-pay (H4627) и /paypal (pending-заявка), для канала «деньги
 * пришли на личный счёт владельца школы». Учётная семантика — деньги ШКОЛЫ:
 * amount = рублёвый номинал тарифа, received_account = school, провайдер
 * gasuns_transfer. В отличие от /teacher-pay НИЧЕГО не вычитается из гонорара
 * преподавателя — доля начисляется движком как с обычной школьной оплаты
 * (кейс Хадиджи из расчёта Костиной H6141: 8 000 ₽ лично Гасунсу выпали из
 * ведомости именно из-за отсутствия канала внесения).
 *
 * Авто-доверие — рулинг MG 06-10-2026 «по умолчанию сверка сразу проходит»,
 * зеркало PayPal-рулинга 22-08-2026: вошедший УСТОЯВШИЙСЯ ученик
 * (isEstablishedClaimStudent: возраст ≥ 7 дней ИЛИ проведённый платёж) —
 * сразу paid, доступ/учёт открывает штатный Payment::booted(), сверка
 * выборочная пост-фактум по выписке получателя. Гость с новым email и
 * курс без групп доступа — pending, ручная сверка в «Финансах».
 * Флаг services.gasuns_pay.enabled (GASUNS_PAY_ENABLED) default OFF;
 * kill-switch авто-доверия — gasuns_pay.trust_existing_students.
 */
final class GasunsPayClaimController extends Controller
{
    public function show(Tariff $tariff): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        return view('gasuns.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'price' => (float) $tariff->price,
        ]);
    }

    public function store(StoreGasunsPayRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        // Зеркало PaypalClaimController (H5083): флаг читаем ДО resolveUser —
        // resolveUser логинит только что созданного гостя, и после него
        // auth()->check() уже не отличит устоявшегося ученика от новой сессии.
        $trusted = auth()->check()
            && auth()->user()->isEstablishedClaimStudent()
            && (bool) config('services.gasuns_pay.trust_existing_students', true);

        $user = $this->resolveUser($request);

        // H5442-зеркало, fail-closed: курс без групп доступа нельзя провести
        // с доступом — trusted откатываем, заявка ложится pending
        // (с типизированной причиной для сверки — P3 ревью #3047).
        $accessDemoted = false;
        if ($trusted && $tariff->course !== null && ! $tariff->course->groups()->exists()) {
            $trusted = false;
            $accessDemoted = true;
        }

        $this->rejectDuplicateClaim($user, $tariff);

        // P2 ревью #3047: сериальный пре-чек не держит гонку двух параллельных
        // POST (устоявшийся ученик → двойной paid → двойной fireOnPaid).
        // DB-гарда — unique-индекс payments.claim_replay_key (миграция
        // 2026_09_24 money-P0): бизнес-правило «одна заявка на тариф» и есть
        // ключ повтора. Повторная легитимная оплата того же блока — через
        // администратора, как и до этого PR.
        $replayKey = 'gasuns:'.(int) $user->id.':'.(int) $tariff->id;

        // Приватное хранение чека (disk 'local', НЕ public) — зеркало teacher-pay-proofs.
        $proofPath = $request->file('proof')?->store('gasuns-pay-proofs', 'local') ?: null;

        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $claimMeta = [
            'sender_name' => (string) $request->validated('sender_name'),
            'paid_on' => (string) $request->validated('paid_on'),
        ];
        if ($ref = $request->validated('reference')) {
            $claimMeta['reference'] = (string) $ref;
        }
        if ($trusted) {
            $claimMeta['auto_trusted'] = true;
            $claimMeta['trusted_at'] = now()->toIso8601String();
        }
        if ($accessDemoted) {
            $claimMeta['reconciliation_exception'] = 'no_access_groups';
        }

        try {
            $payment = $this->createClaimPayment($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted, $replayKey);
        } catch (UniqueConstraintViolationException) {
            // Гонка двух одновременных submit'ов с одним ключом — unique-индекс
            // отбил второй; отказ, не 500 (зеркало paypal H5442).
            throw ValidationException::withMessages([
                'tariff' => 'Уведомление по этому тарифу уже подано и ждёт сверки. Если нужно сообщить о втором переводе — напишите куратору.',
            ]);
        }

        $curators->gasunsPayReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new GasunsPayReceivedMail($payment));
        }

        Mail::to($user)->send(new GasunsPayStudentAckMail($payment, $trusted));

        $success = $trusted
            ? 'Оплата зачтена — доступ к занятиям уже открыт в личном кабинете, подтверждение уходит на ваш email.'
            : 'Спасибо, уведомление получено — подтверждение уже уходит на ваш email. Мы сверим поступление перевода, обычно в течение одного рабочего дня, и откроем доступ; для нового аккаунта пароль придёт на email.';

        return redirect()
            ->route('gasunspay.claim.show', $tariff)
            ->with('success', $success);
    }

    private function abortUnlessEnabled(Tariff $tariff): void
    {
        abort_unless((bool) config('services.gasuns_pay.enabled'), 404);
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');
    }

    /**
     * @param  array<string, mixed>  $claimMeta
     */
    private function createClaimPayment(User $user, Tariff $tariff, StoreGasunsPayRequest $request, ?string $proofPath, ?int $startBlock, ?int $endBlock, array $claimMeta, bool $trusted, string $replayKey): Payment
    {
        return DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta, $trusted, $replayKey): Payment {
            return Payment::create([
                'claim_replay_key' => $replayKey,
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                // Рублёвый номинал тарифа — учётная сумма школы; из неё
                // начисляется и гонорар преподавателя курса (движок payout:run).
                'amount' => (float) $tariff->price,
                'tariff' => $tariff->accessKey(),
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                // Рулинг MG 06-10 «сверка сразу проходит»: устоявшийся ученик —
                // сразу paid (доступ и учёт открывает Payment::booted()), сверка
                // выборочная пост-фактум; гость/курс без групп — pending.
                'status' => $trusted ? 'paid' : 'pending',
                'provider' => Payment::PROVIDER_GASUNS_TRANSFER,
                // Деньги школы: гонорар НЕ урезается (в отличие от teacher_personal).
                'received_account' => Payment::RECEIVED_SCHOOL,
                'proof_path' => $proofPath,
                'claim_meta' => $claimMeta,
                'payer_note' => $this->buildNote($request),
            ]);
        });
    }

    /** Одна незакрытая заявка на тариф: повторная отправка до сверки — отказ. */
    private function rejectDuplicateClaim(User $user, Tariff $tariff): void
    {
        $exists = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $tariff->course_id)
            ->where('tariff', $tariff->accessKey())
            ->where('provider', Payment::PROVIDER_GASUNS_TRANSFER)
            ->whereIn('status', ['pending', 'paid'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'tariff' => 'Уведомление по этому тарифу уже подано и ждёт сверки. Если нужно сообщить о втором переводе — напишите куратору.',
            ]);
        }
    }

    /**
     * Диапазон блоков для поблочного тарифа. Не-блочный ('full') — без диапазона.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function blocksFor(Tariff $tariff): array
    {
        if ($tariff->type === 'block' && $tariff->block_number) {
            return [(int) $tariff->block_number, (int) $tariff->block_number];
        }

        return [null, null];
    }

    /** Свободнотекстовое примечание для админки: канал + sender + date + ref. */
    private function buildNote(StoreGasunsPayRequest $request): string
    {
        $parts = [
            'Перевод рублями лично Гасунсу',
            'from: '.$request->validated('sender_name'),
            'paid_on: '.$request->validated('paid_on'),
        ];
        if ($ref = $request->validated('reference')) {
            $parts[] = 'ref: '.$ref;
        }
        if ($comment = $request->validated('comment')) {
            $parts[] = $comment;
        }

        // payer_note — string(255); режем с запасом.
        return Str::limit(implode(' · ', $parts), 250, '');
    }

    /**
     * Зеркало TeacherPayController::resolveUser — гость с НОВЫМ email получает
     * аккаунт и логинится, гость с СУЩЕСТВУЮЩИМ email отклоняется.
     */
    private function resolveUser(StoreGasunsPayRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->validated('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и подайте уведомление оттуда.',
            ]);
        }

        $user = User::create([
            'email' => $request->validated('email'),
            'name' => $request->validated('name'),
            'password' => Hash::make(Str::random(12)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        auth()->login($user);

        return $user;
    }
}
