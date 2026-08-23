<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Pages\Debtors;
use App\Filament\Pages\MarathonMantraReviews;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\GroupResource;
use App\Filament\Resources\UserResource;
use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Информативные уведомления кураторам в общий Telegram-чат по всем
 * денежным/договорным событиям: оплаты, брони, доступ под обещание,
 * создание/закрытие/отмена обещаний и рассрочек.
 *
 * Получатель — config('services.telegram.curators_chat_id'). Если он не задан,
 * все методы — no-op (фича выключена). Отправляет основной бот
 * (config('services.telegram.bot_token')) через SendTelegramChatMessageJob.
 */
class CuratorNotifier
{
    public function paymentPaid(Payment $payment): void
    {
        $isPromise = str_starts_with((string) $payment->transaction_id, 'promise_#');
        $head = $isPromise ? '💰 <b>Оплата по обещанию</b>' : '💰 <b>Оплата получена</b>';

        $lines = [
            $head,
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            $this->tariffLine($payment),
        ];

        if (! empty($payment->transaction_id)) {
            $lines[] = 'Транзакция: <code>'.e($payment->transaction_id).'</code>';
        }
        $lines[] = $this->dateLine($payment->created_at);
        $lines[] = $this->adminLink($payment->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Новая заявка на оплату через PayPal (оплата из-за рубежа). Для гостей —
     * сигнал ручной сверки (доступ откроется после перевода в paid в админке);
     * для авто-доверенных заявок своих учеников (ruling 22-08-2026) доступ уже
     * открыт, уведомление = вход выборочной сверки.
     */
    public function paypalClaimReceived(Payment $payment): void
    {
        $headline = $payment->isAutoTrustedPaypal()
            ? '🌍 <b>PayPal-оплата ученика</b> — доступ открыт сразу, сверка выборочная'
            : '🌍 <b>Заявка на оплату через PayPal</b> — нужна сверка';
        $lines = [
            $headline,
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            $this->tariffLine($payment),
            'Заявлено: <b>'.($payment->foreignAmountLabel() ?: '—').'</b>',
            'Номинал: <b>'.$this->money((float) $payment->amount).'</b>',
        ];
        if ($from = $payment->claimMeta('paypal_payer')) {
            $lines[] = 'С аккаунта: <code>'.e((string) $from).'</code>';
        }
        if ($paidOn = $payment->claimMeta('paid_on')) {
            $lines[] = 'Дата оплаты: <b>'.e((string) $paidOn).'</b>';
        }
        if (! empty($payment->payer_note)) {
            $lines[] = 'Примечание: '.e($payment->payer_note);
        }
        if (! empty($payment->proof_path)) {
            $lines[] = '📎 Приложен файл подтверждения';
        }
        $lines[] = $this->adminLink($payment->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Новый счёт юрлицу — ждёт банковского поступления, затем «Подтвердить счёт».
     */
    public function companyInvoiceReceived(Payment $payment): void
    {
        $lines = [
            '🏢 <b>Счет для юрлица</b> — ждем поступление',
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            $this->tariffLine($payment),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            'Номер: <code>'.e($payment->invoiceNumber()).'</code>',
        ];
        if ($company = $payment->claimMeta('company_name')) {
            $lines[] = 'Организация: <b>'.e((string) $company).'</b>';
        }
        if ($inn = $payment->claimMeta('inn')) {
            $lines[] = 'ИНН: <code>'.e((string) $inn).'</code>';
        }
        if (! empty($payment->payer_note)) {
            $lines[] = 'Примечание: '.e($payment->payer_note);
        }
        $lines[] = $this->adminLink($payment->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function depositReceived(Payment $payment): void
    {
        $lines = [
            '📌 <b>Бронь курса (депозит)</b>',
            '',
            $this->studentLine($payment->user),
            $this->courseLine($payment->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            $this->dateLine($payment->created_at),
            $this->adminLink($payment->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Бронь перенесена админом с одного курса на другой (PaymentResource action
     * «Перенести бронь»). Депозит не менял суммы/статуса — только курс, в который
     * он зачтётся при оплате.
     */
    public function depositTransferred(Payment $payment, Course $from, Course $to): void
    {
        $lines = [
            '🔄 <b>Бронь перенесена на другой курс</b>',
            '',
            $this->studentLine($payment->user),
            'С курса: <b>'.e($from->title).'</b>',
            'На курс: <b>'.e($to->title).'</b>',
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            $this->adminLink($payment->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * @param  list<int>  $blocks
     */
    public function conditionalAccessGranted(PaymentPromise $promise, string $mode, array $blocks): void
    {
        $scope = $mode === ConditionalAccessGranter::MODE_FULL
            ? 'весь курс'
            : 'блоки: '.($blocks ? implode(', ', $blocks) : '—');

        $lines = [
            '📅 <b>Открыт доступ под обещание</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Открыто: <b>'.$scope.'</b>',
            'Оплатить до: <b>'.$this->date($promise->promised_at).'</b>',
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма обещания: <b>'.$this->money((float) $promise->amount).'</b>';
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseCreated(PaymentPromise $promise): void
    {
        $lines = [
            '🤝 <b>Новое обещание оплатить</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Оплатить до: <b>'.$this->date($promise->promised_at).'</b>',
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма: <b>'.$this->money((float) $promise->amount).'</b>';
        }
        if (! empty($promise->note)) {
            $lines[] = 'Комментарий: '.e($promise->note);
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * @param  iterable<PaymentPromise>  $promises
     */
    public function installmentCreated(User $user, Course $course, iterable $promises, ?string $note): void
    {
        $rows = [];
        $total = 0.0;
        foreach ($promises as $p) {
            $amount = (float) ($p->amount ?? 0);
            $total += $amount;
            $rows[] = '• '.$this->date($p->promised_at).' — <b>'.$this->money($amount).'</b>';
        }

        $lines = [
            '🧩 <b>Создана рассрочка</b>',
            '',
            $this->studentLine($user),
            $this->courseLine($course),
            'Платежей: <b>'.count($rows).'</b>',
            'График:',
            ...$rows,
            'Итого по графику: <b>'.$this->money($total).'</b>',
        ];
        if (! empty($note)) {
            $lines[] = 'Комментарий: '.e($note);
        }
        $lines[] = $this->adminLink($user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseFulfilled(PaymentPromise $promise, Payment $payment): void
    {
        $lines = [
            '✅ <b>Обещание закрыто оплатой</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Сумма: <b>'.$this->money((float) $payment->amount).'</b>',
            'Блоки: <b>'.$this->blocksLabel($payment->start_block, $payment->end_block).'</b>',
            $this->adminLink($promise->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseRescheduledByStudent(PaymentPromise $promise): void
    {
        $lines = [
            '📅 <b>Студент перенёс дату оплаты</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Новая дата: <b>'.$this->date($promise->promised_at).'</b>',
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма: '.$this->money((float) $promise->amount);
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    public function promiseCancelled(PaymentPromise $promise): void
    {
        $lines = [
            '❌ <b>Обещание отменено</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Было обещано до: '.$this->date($promise->promised_at),
        ];
        if ($promise->amount !== null) {
            $lines[] = 'Сумма: '.$this->money((float) $promise->amount);
        }
        $lines[] = $this->adminLink($promise->user);

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Утренний дайджест по истёкшим за ночь обещаниям: общее число + худшие
     * по сумме. Раньше promises:expire молчал (retention audit gap #4).
     *
     * @param  Collection<int, PaymentPromise>  $promises
     */
    public function promisesExpiredDigest(Collection $promises): void
    {
        if ($promises->isEmpty()) {
            return;
        }

        $total = $promises->sum(fn (PaymentPromise $p): float => (float) ($p->amount ?? 0));

        $lines = [
            '⏳ <b>Истекли обещания оплаты</b>',
            '',
            'Просрочено за ночь: <b>'.$promises->count().'</b>',
            'Сумма долга: <b>'.$this->money($total).'</b>',
            '',
            'Крупнейшие:',
        ];

        // Худшие по сумме (уже отсортированы в команде, но подстрахуемся).
        foreach ($promises->sortByDesc(fn (PaymentPromise $p): float => (float) ($p->amount ?? 0))->take(5) as $promise) {
            $name = e((string) ($promise->user->name ?? ('#'.$promise->user_id)));
            $amount = $promise->amount !== null ? $this->money((float) $promise->amount) : '—';
            $lines[] = '• <b>'.$name.'</b> — '.$amount.' · до '.$this->date($promise->promised_at);
        }

        try {
            $url = Debtors::getUrl();
        } catch (\Throwable) {
            $url = url('/admin');
        }
        $lines[] = '';
        $lines[] = '👉 <a href="'.$url.'">Открыть должников</a>';

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Симметрично студенческой рассылке (GroupRecruitmentNotifier): автоматика
     * обнаружила недобор группы за N дней до старта — куратор видит проблему
     * одновременно со студентами, не узнаёт из входящего вопроса (H162).
     */
    public function groupUnderEnrolled(Group $group): void
    {
        $course = $group->courses()->first()?->title ?? $group->name;
        $date = $group->effectiveStartDate()?->format('d.m.Y') ?? '—';

        $lines = [
            '⚠️ <b>Группа недобрана</b>',
            '',
            'Группа: <b>'.e($group->name).'</b>',
            'Курс: <b>'.e($course).'</b>',
            'Плановый старт: <b>'.$date.'</b>',
            // H3118: в порог идут платные места и льготники; полностью бесплатные
            // участники в знаменателе не участвуют, иначе «набрано» завышено.
            'Набрано: <b>'.$group->membersTowardMinSize().'</b> из <b>'.($group->min_size ?? '—').'</b>',
        ];

        try {
            $url = GroupResource::getUrl('index');
        } catch (\Throwable) {
            $url = url('/admin');
        }
        $lines[] = '';
        $lines[] = '👉 <a href="'.$url.'">Открыть группы</a>';

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Детектор «забытых» курсов (courses:detect-missing-milestones): курс идёт
     * уже N занятий, а вехи сертификатов не настроены — студенты рискуют
     * остаться без документов. Дедуп — courses.milestones_nudge_sent_at.
     */
    public function courseMissingMilestones(Course $course, int $pastLessons): void
    {
        $lines = [
            '⚠️ <b>Курс без вех сертификатов</b>',
            '',
            $this->courseLine($course),
            'Занятий состоялось: <b>'.$pastLessons.'</b>',
            'Вехи автовыдачи не настроены — студенты не получат сертификаты/справки.',
        ];

        try {
            $url = CourseResource::getUrl('edit', ['record' => $course->id]);
        } catch (\Throwable) {
            $url = url('/admin');
        }
        $lines[] = '';
        $lines[] = '👉 <a href="'.$url.'">Открыть курс (вкладка «Вехи сертификатов»)</a>';

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * H445 Phase 4 (H546) — paid-track marathon enrollee sent their Day-2
     * mantra-reading voice note; queue it for a curator to listen + note.
     * Free track is self-assessed and never reaches this notifier.
     */
    public function marathonMantraVoiceReceived(MarathonEnrollment $enrollment): void
    {
        $lead = $enrollment->lead;

        $lines = [
            '🎙️ <b>Голосовое — марафон, День 2 (мантра)</b>',
            '',
            'Студент: <b>'.e((string) ($lead?->name ?? '#'.$enrollment->lead_id)).'</b>',
        ];

        try {
            $url = MarathonMantraReviews::getUrl();
        } catch (\Throwable) {
            $url = url('/admin');
        }
        $lines[] = '';
        $lines[] = '👉 <a href="'.$url.'">Открыть очередь на проверку</a>';

        $this->dispatchToCurators($this->join($lines));
    }

    public function installmentCancelled(PaymentPromise $promise, int $count): void
    {
        $lines = [
            '❌ <b>Рассрочка отменена</b>',
            '',
            $this->studentLine($promise->user),
            $this->courseLine($promise->course),
            'Отменено обещаний: <b>'.$count.'</b>',
            $this->adminLink($promise->user),
        ];

        $this->dispatchToCurators($this->join($lines));
    }

    /**
     * Студент попросил разбить оплату на части прямо с чекаута (H1290).
     * Запрос НЕ создаёт ни PaymentPromise, ни рассрочку — только зовёт
     * куратора; график и условия согласуются вручную в рамках лимитов
     * config/receivables.php (ruling D6).
     */
    public function installmentAskReceived(
        Tariff $tariff,
        ?User $user,
        ?string $name,
        ?string $contact,
        ?string $comment,
        float $finalPrice,
    ): void {
        $lines = [
            '🙋 <b>Запрос оплаты по частям</b> — с чекаута',
            '',
            $user
                ? $this->studentLine($user)
                : 'Студент: <b>'.($name !== null && $name !== '' ? e($name) : 'гость').'</b> (не авторизован)',
            $this->courseLine($tariff->course),
            'Тариф: <b>'.e((string) $tariff->title).'</b>',
            'Цена для студента: <b>'.$this->money($finalPrice).'</b>',
        ];
        if ($contact !== null && $contact !== '') {
            $lines[] = 'Связаться: '.e($contact);
        }
        if ($comment !== null && $comment !== '') {
            $lines[] = 'Комментарий: '.e($comment);
        }
        $lines[] = '';
        $lines[] = 'План не создан — свяжитесь со студентом и согласуйте график.';
        if ($user) {
            $lines[] = $this->adminLink($user);
        }

        $this->dispatchToCurators($this->join($lines));
    }

    // ==========================================================
    // Внутреннее
    // ==========================================================

    private function dispatchToCurators(string $text): void
    {
        $chatId = (string) (config('services.telegram.curators_chat_id') ?? '');
        if ($chatId === '') {
            return; // фича выключена — чат не настроен
        }

        SendTelegramChatMessageJob::dispatch($chatId, $text);
    }

    /** @param  list<string>  $lines */
    private function join(array $lines): string
    {
        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function studentLine(?User $user): string
    {
        if (! $user) {
            return 'Студент: —';
        }

        $contacts = array_filter([
            $user->phone ?? null,
            $user->email ?? null,
            $user->telegram_id ? 'tg:'.$user->telegram_id : null,
        ]);

        $line = 'Студент: <b>'.e((string) ($user->name ?? ('#'.$user->id))).'</b>';
        if ($contacts) {
            $line .= ' ('.e(implode(', ', $contacts)).')';
        }

        return $line;
    }

    private function courseLine(?Course $course): string
    {
        return 'Курс: <b>'.e((string) ($course->title ?? '—')).'</b>';
    }

    private function tariffLine(Payment $payment): string
    {
        $tariff = $payment->tariff ? e($payment->tariff) : '—';
        $blocks = $this->blocksLabel($payment->start_block, $payment->end_block);

        return 'Тариф: <b>'.$tariff.'</b>'.($blocks !== '—' ? ' · блоки: '.$blocks : '');
    }

    private function blocksLabel(?int $start, ?int $end): string
    {
        if ($start === null && $end === null) {
            return '—';
        }
        if ($start !== null && $end !== null) {
            return $start === $end ? (string) $start : $start.'–'.$end;
        }

        return (string) ($start ?? $end);
    }

    private function dateLine(mixed $date): string
    {
        return 'Дата: '.$this->date($date);
    }

    private function date(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('d.m.Y H:i');
        }

        return $date ? e((string) $date) : '—';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0, '.', ' ').' ₽';
    }

    private function adminLink(?User $user): string
    {
        if (! $user) {
            return '';
        }

        try {
            $url = UserResource::getUrl('edit', ['record' => $user->id]);
        } catch (\Throwable) {
            $url = url('/admin');
        }

        return "👉 <a href=\"{$url}\">Карточка студента</a>";
    }
}
