<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeacherPayRequest;
use App\Mail\TeacherPayReceivedMail;
use App\Mail\TeacherPayStudentAckMail;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\CuratorNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Заявка студента «я заплатил преподавателю напрямую» (H4627). Зеркало
 * BankClaimController (H3497), но деньги уходят на ЛИЧНЫЙ счёт преподавателя:
 * запись сразу несёт received_account='teacher_personal' +
 * received_by_teacher_id, поэтому после подтверждения куратором
 * (pending → paid из Filament) движок зарплаты сам вычтет номинал из
 * гонорара преподавателя (механизм H4597). Авто-доверия НЕТ — в отличие от
 * школьного SEPA-канала сверку по выписке преподавателя проходит каждая
 * заявка: прямой перевод на личный счёт — самая рискованная дорожка.
 * Не заполнил анкету — оплата не зачтена; Настя подстраховывает первый месяц.
 */
final class TeacherPayController extends Controller
{
    public function show(Tariff $tariff): View
    {
        $this->abortUnlessEnabled($tariff);

        $tariff->load('course');

        return view('teacher.claim', [
            'tariff' => $tariff,
            'course' => $tariff->course,
            'teachers' => Teacher::query()->orderBy('name')->get(['id', 'name']),
            'price' => (float) $tariff->price,
        ]);
    }

    public function store(StoreTeacherPayRequest $request, Tariff $tariff, CuratorNotifier $curators): RedirectResponse
    {
        $this->abortUnlessEnabled($tariff);

        $user = $this->resolveUser($request);

        // Приватное хранение чека (disk 'local', НЕ public) — зеркало bank-proofs.
        $proofPath = $request->file('proof')?->store('teacher-pay-proofs', 'local') ?: null;

        [$startBlock, $endBlock] = $this->blocksFor($tariff);

        $claimMeta = [
            'sender_name' => (string) $request->validated('sender_name'),
            'paid_on' => (string) $request->validated('paid_on'),
        ];
        if ($ref = $request->validated('reference')) {
            $claimMeta['reference'] = (string) $ref;
        }

        $payment = DB::transaction(function () use ($user, $tariff, $request, $proofPath, $startBlock, $endBlock, $claimMeta): Payment {
            return Payment::create([
                'user_id' => $user->id,
                'course_id' => $tariff->course_id,
                // Рублёвый номинал тарифа — учётная сумма; реально переведённая
                // валютная сумма — справочно в foreign_* (из неё же вычтется
                // гонорар преподавателя — см. directReceiptsForTeacher).
                'amount' => (float) $tariff->price,
                'foreign_amount' => (float) $request->validated('foreign_amount'),
                'foreign_currency' => $request->validated('foreign_currency'),
                'tariff' => $tariff->accessKey(),
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                // Всегда pending: доступ и зачёт в гонорар — только после
                // сверки куратором («Подтвердить перевод преподавателю»).
                'status' => 'pending',
                'provider' => Payment::PROVIDER_TEACHER_TRANSFER,
                // Сразу привязываем к личному счёту преподавателя — куратор
                // видит получателя ещё ДО подтверждения, а после paid движок
                // зарплаты вычтет номинал сам (H4597).
                'received_account' => Payment::RECEIVED_TEACHER,
                'received_by_teacher_id' => (int) $request->validated('teacher_id'),
                'proof_path' => $proofPath,
                'claim_meta' => $claimMeta,
                'payer_note' => $this->buildNote($request),
            ]);
        });

        $curators->teacherPayReceived($payment);

        $adminEmail = (string) config('services.admin.email');
        if ($adminEmail !== '') {
            Mail::to($adminEmail)->send(new TeacherPayReceivedMail($payment));
        }

        Mail::to($user)->send(new TeacherPayStudentAckMail($payment));

        return redirect()
            ->route('teacherpay.claim.show', $tariff)
            ->with('success', 'Спасибо, заявка получена — подтверждение уже уходит на ваш email. Преподаватель или куратор сверит поступление по выписке, обычно в течение одного рабочего дня, и откроет доступ; для нового аккаунта пароль придёт на email.');
    }

    private function abortUnlessEnabled(Tariff $tariff): void
    {
        abort_unless((bool) config('services.teacher_pay.enabled'), 404);
        abort_unless($tariff->is_active, 404, 'Тариф недоступен для покупки.');
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

    /** Свободнотекстовое примечание для админки: получатель + sender + date + ref. */
    private function buildNote(StoreTeacherPayRequest $request): string
    {
        $teacher = Teacher::query()->find((int) $request->validated('teacher_id'));
        $parts = ['Оплата напрямую преподавателю'];
        if ($teacher) {
            $parts[] = 'to: '.$teacher->name;
        }
        $parts[] = 'from: '.$request->validated('sender_name');
        $parts[] = 'paid_on: '.$request->validated('paid_on');
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
     * Зеркало BankClaimController::resolveUser — гость с НОВЫМ email получает
     * аккаунт и логинится, гость с СУЩЕСТВУЮЩИМ email отклоняется.
     */
    private function resolveUser(StoreTeacherPayRequest $request): User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $existing = User::where('email', User::normalizeEmail($request->validated('email')))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => 'У вас уже есть аккаунт с этим email. Войдите в личный кабинет — и подайте заявку оттуда.',
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
