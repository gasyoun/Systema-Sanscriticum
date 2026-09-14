<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\SurveyStudentInviteMail;
use App\Models\Payment;
use App\Models\SurveyInvitation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Email-волна приглашений в опрос для учеников, которых бот-канал достать
 * не может (H4431; рулинг MG 08-09-2026 «email да, но не сегодня» — отправка
 * не ранее 09-09-2026, отдельное «go» человека перед --send).
 *
 * Кого берём: тот же конвейер текущих учеников, что у surveys:send-student-invites
 * (живая группа ИЛИ нетерминальный course_user ИЛИ свежая оплата ≤6 мес), те же
 * исключения (персонал, отписавшиеся, недавние ответившие, кабинетные/саппорт-
 * приглашения, exit-триггер, уже приглашённые в любую волну) — НО аудитория
 * полностью без telegram_id (близнецовая ветка telegram_id IS NULL / 0) и с
 * непустым email. Кросс-канальные дубли исключает UNIQUE(survey_slug, user_id):
 * строка channel=email не может появиться, если адресат уже приглашён ботом.
 *
 * Транспорт — собственный мейлер платформы (config/mail.php, как
 * CourseWelcomeMail); новый SMTP-сервис не заводится. Отправка синхронная,
 * чтобы писать статус каждого адресата в журнал survey_invitations.
 *
 * Коды SMTP отличаются от HTTP-семантики Telegram-версии: 5xx (например
 * 550 mailbox unavailable) — ПОСТОЯННЫЙ жёсткий отскок → failed + адрес
 * выбывает из будущих волн; 4xx (450/451) — временный отказ → unknown
 * без слепого ретрая; обрыв связи/таймаут → unknown.
 *
 * REPORT-ONLY по умолчанию; отправка — только с --send, батчами --limit.
 *
 *   php artisan surveys:send-student-email-invites                    # сухой прогон
 *   php artisan surveys:send-student-email-invites --send --limit=100
 */
class SendSurveyStudentEmailInvites extends Command
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'teacher', 'manager', 'accountant'];

    private const TERMINAL_COURSE_STATUSES = ['Выпускник', 'Покинул', 'Исключен'];

    private const RECENT_PAYMENT_MONTHS = 6;

    /** Постоянные ошибки email (адрес выбывает из будущих волн). */
    private const PERMANENT_ERROR_PREFIXES = ['hard-bounce', 'invalid-email'];

    protected $signature = 'surveys:send-student-email-invites
        {--slug=student-purchase-2026-09 : Ключ волны из config/surveys.php}
        {--send : Реально отправить (без флага — сухой прогон)}
        {--limit=200 : Максимум приглашений за один прогон}';

    protected $description = 'Однократное личное email-приглашение в опрос ученикам без telegram_id';

    public function handle(): int
    {
        $slug = (string) $this->option('slug');
        $send = (bool) $this->option('send');
        $limit = max(1, (int) $this->option('limit'));

        if (! (bool) config('surveys.enabled')) {
            $this->error('Опросы выключены (surveys.enabled=false).');

            return self::FAILURE;
        }

        if (! array_key_exists($slug, (array) config('surveys.definitions'))) {
            $this->error("Неизвестная волна опроса: {$slug}");

            return self::FAILURE;
        }

        $counts = $this->exclusionCounts();

        $this->info('Кандидаты (текущие ученики): '.$counts['candidates'].'.');
        $this->line('  + расширение свежими плательщиками: '.$counts['recent_payer_widening']);
        $this->line('  персонал: '.$counts['staff']);
        $this->line('  отписались от сообщений: '.$counts['opted_out']);
        $this->line('  без email: '.$counts['no_email']);
        $this->line('  есть telegram_id (остаются ботовому каналу): '.$counts['with_telegram']);
        $this->line('  отвечали на опрос за 3 мес: '.$counts['recent_responders']);
        $this->line('  приглашение в кабинет за 3 мес: '.$counts['cabinet_invited']);
        $this->line('  приглашение в саппорт-боте за 3 мес: '.$counts['support_invited']);
        $this->line('  exit-price триггер за 3 мес: '.$counts['exit_surveyed']);
        $this->line('  уже приглашены в любую волну: '.$counts['already_invited']);
        $this->line('  email с постоянным отскоком/невалиден: '.$counts['bounced']);
        $this->info('К отправке: '.$counts['eligible'].'. Лимит батча: '.$limit.'.');

        if (! $send) {
            $this->comment('Сухой прогон, ничего не отправлено и не записано. Отправка: --send.');
            $this->writeManifest($slug, $counts, [], false);

            return self::SUCCESS;
        }

        if ($counts['eligible'] === 0) {
            $this->comment('Ни одного нового адресата — отправлять нечего.');
            $this->writeManifest($slug, $counts, [], true);

            return self::SUCCESS;
        }

        if (! $this->verifyMailerIdentity()) {
            return self::FAILURE;
        }

        $eligible = $this->eligible()->orderBy('id')->limit($limit)->get();

        $results = [];
        $sent = $failed = $unknown = $skipped = 0;

        foreach ($eligible as $user) {
            $invitation = $this->reserve($slug, $user);
            if (! $invitation instanceof SurveyInvitation) {
                $skipped++;
                $results[] = ['user_id' => $user->id, 'email' => $user->email, 'status' => 'skipped_reserved'];

                continue;
            }

            if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
                $invitation->forceFill([
                    'status' => SurveyInvitation::STATUS_FAILED,
                    'error' => 'invalid-email: адрес не проходит проверку',
                ])->save();
                $failed++;
                $results[] = ['user_id' => $user->id, 'email' => $user->email, 'status' => SurveyInvitation::STATUS_FAILED, 'error' => 'invalid-email'];

                continue;
            }

            [$status, $error] = $this->sendEmail($user, $slug);

            $invitation->forceFill([
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === SurveyInvitation::STATUS_SENT ? now() : null,
            ])->save();

            match ($status) {
                SurveyInvitation::STATUS_SENT => $sent++,
                SurveyInvitation::STATUS_FAILED => $failed++,
                default => $unknown++,
            };

            $results[] = ['user_id' => $user->id, 'email' => $user->email, 'status' => $status, 'error' => $error];

            usleep(300_000);
        }

        $this->info("Отправлено: {$sent}. Отказов: {$failed}. Неопределённых: {$unknown}. Пропущено (резерв существовал): {$skipped}.");

        Log::info('surveys:send-student-email-invites batch complete', [
            'slug' => $slug,
            'sent' => $sent,
            'failed' => $failed,
            'unknown' => $unknown,
            'skipped_reserved' => $skipped,
        ]);

        $this->writeManifest($slug, $counts, $results, true);

        return self::SUCCESS;
    }

    /** Кандидаты — текущие ученики: живая группа, активный доступ или свежая оплата. */
    private function candidates(): Builder
    {
        return User::query()->where(function ($q) {
            $q->whereHas('activeGroups', fn ($g) => $g->whereIn('groups.status', ['forming', 'active']))
                ->orWhereHas('courses', fn ($c) => $c->whereNotIn('course_user.status', self::TERMINAL_COURSE_STATUSES))
                ->orWhereHas('payments', fn ($p) => $p
                    ->whereIn('payments.status', Payment::PAID_STATUSES)
                    ->where('payments.created_at', '>=', now()->subMonths(self::RECENT_PAYMENT_MONTHS)));
        });
    }

    /**
     * Кумулятивный конвейер исключений — те же ступени, что у Telegram-версии,
     * с двумя ветками email-канала: требуется непустой валидируемый email и
     * ПОЛНОЕ отсутствие telegram_id (близнец ботовой аудитории).
     *
     * @return array<string, int>
     */
    private function exclusionCounts(): array
    {
        $cutoff = now()->subMonths(3);

        $base = User::query()->where(function ($q) {
            $q->whereHas('activeGroups', fn ($g) => $g->whereIn('groups.status', ['forming', 'active']))
                ->orWhereHas('courses', fn ($c) => $c->whereNotIn('course_user.status', self::TERMINAL_COURSE_STATUSES));
        });

        $counts = ['candidates' => (clone $this->candidates())->count()];
        $counts['recent_payer_widening'] = $counts['candidates'] - (clone $base)->count();

        $staffFree = (clone $this->candidates())
            ->where('is_admin', false)
            ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', self::STAFF_ROLES));
        $counts['staff'] = $counts['candidates'] - (clone $staffFree)->count();

        $optedIn = (clone $staffFree)->where('wants_messenger_announcements', true);
        $counts['opted_out'] = (clone $staffFree)->count() - (clone $optedIn)->count();

        $withEmail = (clone $optedIn)->whereNotNull('email')->where('email', '<>', '');
        $counts['no_email'] = (clone $optedIn)->count() - (clone $withEmail)->count();

        $withoutTelegram = (clone $withEmail)
            ->where(fn ($q) => $q->whereNull('telegram_id')->orWhere('telegram_id', 0));
        $counts['with_telegram'] = (clone $withEmail)->count() - (clone $withoutTelegram)->count();

        $step = (clone $withoutTelegram)
            ->whereNotExists(fn ($q) => $q->from('survey_responses as sr')
                ->whereColumn('sr.user_id', 'users.id')
                ->where('sr.created_at', '>=', $cutoff));
        $counts['recent_responders'] = (clone $withoutTelegram)->count() - (clone $step)->count();

        $previous = (clone $step)->count();
        $step->where(fn ($q) => $q->whereNull('cabinet_invite_sent_at')->orWhere('cabinet_invite_sent_at', '<', $cutoff));
        $counts['cabinet_invited'] = $previous - (clone $step)->count();

        $previous = (clone $step)->count();
        $step->whereNotExists(fn ($q) => $q->from('telegram_support_contacts as tsc')
            ->whereColumn('tsc.linked_user_id', 'users.id')
            ->whereNotNull('tsc.link_invited_at')
            ->where('tsc.link_invited_at', '>=', $cutoff));
        $counts['support_invited'] = $previous - (clone $step)->count();

        $previous = (clone $step)->count();
        $step->whereNotExists(fn ($q) => $q->from('course_user as cu2')
            ->join('courses as c2', 'c2.id', '=', 'cu2.course_id')
            ->whereColumn('cu2.user_id', 'users.id')
            ->whereNotNull('c2.exit_survey_triggered_at')
            ->where('c2.exit_survey_triggered_at', '>=', $cutoff));
        $counts['exit_surveyed'] = $previous - (clone $step)->count();

        $previous = (clone $step)->count();
        $step->whereNotExists(fn ($q) => $q->from('survey_invitations as si')
            ->whereColumn('si.user_id', 'users.id')
            ->whereIn('si.status', [SurveyInvitation::STATUS_QUEUED, SurveyInvitation::STATUS_SENT, SurveyInvitation::STATUS_UNKNOWN]));
        $counts['already_invited'] = $previous - (clone $step)->count();

        $previous = (clone $step)->count();
        $step->whereNotExists(fn ($q) => $q->from('survey_invitations as si2')
            ->whereColumn('si2.user_id', 'users.id')
            ->where('si2.channel', 'email')
            ->where('si2.status', SurveyInvitation::STATUS_FAILED)
            ->where(function ($e) {
                foreach (self::PERMANENT_ERROR_PREFIXES as $prefix) {
                    $e->orWhere('si2.error', 'like', $prefix.'%');
                }
            }));
        $counts['bounced'] = $previous - (clone $step)->count();

        $counts['eligible'] = (clone $step)->count();

        return $counts;
    }

    /** Полный конвейер исключений — те же ступени, что в exclusionCounts. */
    private function eligible(): Builder
    {
        $cutoff = now()->subMonths(3);

        return $this->candidates()
            ->where('is_admin', false)
            ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', self::STAFF_ROLES))
            ->where('wants_messenger_announcements', true)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->where(fn ($q) => $q->whereNull('telegram_id')->orWhere('telegram_id', 0))
            ->whereNotExists(fn ($q) => $q->from('survey_responses as sr')
                ->whereColumn('sr.user_id', 'users.id')
                ->where('sr.created_at', '>=', $cutoff))
            ->where(fn ($q) => $q->whereNull('cabinet_invite_sent_at')->orWhere('cabinet_invite_sent_at', '<', $cutoff))
            ->whereNotExists(fn ($q) => $q->from('telegram_support_contacts as tsc')
                ->whereColumn('tsc.linked_user_id', 'users.id')
                ->whereNotNull('tsc.link_invited_at')
                ->where('tsc.link_invited_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->from('course_user as cu2')
                ->join('courses as c2', 'c2.id', '=', 'cu2.course_id')
                ->whereColumn('cu2.user_id', 'users.id')
                ->whereNotNull('c2.exit_survey_triggered_at')
                ->where('c2.exit_survey_triggered_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->from('survey_invitations as si')
                ->whereColumn('si.user_id', 'users.id')
                ->whereIn('si.status', [SurveyInvitation::STATUS_QUEUED, SurveyInvitation::STATUS_SENT, SurveyInvitation::STATUS_UNKNOWN]))
            ->whereNotExists(fn ($q) => $q->from('survey_invitations as si2')
                ->whereColumn('si2.user_id', 'users.id')
                ->where('si2.channel', 'email')
                ->where('si2.status', SurveyInvitation::STATUS_FAILED)
                ->where(function ($e) {
                    foreach (self::PERMANENT_ERROR_PREFIXES as $prefix) {
                        $e->orWhere('si2.error', 'like', $prefix.'%');
                    }
                }));
    }

    /**
     * Эксклюзивный атомарный захват адресата ДО отправки; гонка перезапусков
     * гасится уникальным индексом (slug, user_id) + firstOrCreate — та же
     * механика, что у Telegram-версии, но channel=email и без chat_id.
     * Перезаписывается ТОЛЬКО failed-строка без постоянной ошибки.
     */
    private function reserve(string $slug, User $user): ?SurveyInvitation
    {
        try {
            $invitation = SurveyInvitation::firstOrCreate(
                ['survey_slug' => $slug, 'user_id' => $user->id],
                [
                    'telegram_chat_id' => null,
                    'channel' => 'email',
                    'status' => SurveyInvitation::STATUS_QUEUED,
                ],
            );
        } catch (QueryException $e) {
            Log::info('surveys:send-student-email-invites: reservation lost the race, skipping', ['user_id' => $user->id]);

            return null;
        }

        if (! $invitation->wasRecentlyCreated
            && ($invitation->status !== SurveyInvitation::STATUS_FAILED
                || $this->isPermanentError($invitation->error))) {
            return null;
        }

        return $invitation;
    }

    /**
     * Отправка через собственный мейлер платформы, синхронно.
     *
     * @return array{0: string, 1: ?string} [status, sanitized error]
     */
    private function sendEmail(User $user, string $slug): array
    {
        $url = rtrim((string) config('app.url'), '/').'/anketa/'.$slug;

        try {
            Mail::to((string) $user->email)->send(new SurveyStudentInviteMail($user, $url));
        } catch (TransportException $e) {
            $message = $this->sanitize($e->getMessage());
            $code = $this->smtpReplyCode($message);

            if ($code !== null && str_starts_with($code, '5')) {
                // SMTP 5xx (550 и т.п.) — постоянный жёсткий отскок: адрес
                // выбывает из будущих волн через пометку error=hard-bounce.
                return [SurveyInvitation::STATUS_FAILED, 'hard-bounce: smtp '.$code.': '.$message];
            }

            if ($code !== null && str_starts_with($code, '4')) {
                // SMTP 4xx — временный отказ, исход доставки не известен.
                return [SurveyInvitation::STATUS_UNKNOWN, 'smtp 4xx: '.$message];
            }

            return [SurveyInvitation::STATUS_UNKNOWN, 'transport: '.$message];
        } catch (\Throwable $e) {
            return [SurveyInvitation::STATUS_UNKNOWN, 'transport: '.$this->sanitize($e->getMessage())];
        }

        return [SurveyInvitation::STATUS_SENT, null];
    }

    private function smtpReplyCode(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return preg_match('/got code "(\d{3})"/', $message, $m) === 1 ? $m[1] : null;
    }

    private function isPermanentError(?string $error): bool
    {
        if ($error === null) {
            return false;
        }

        foreach (self::PERMANENT_ERROR_PREFIXES as $prefix) {
            if (str_starts_with($error, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Тексты ошибок транспорта не должны тащить учётные данные и расти без предела. */
    private function sanitize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return mb_substr($message, 0, 480);
    }

    /**
     * Предполётная проверка мейлера (аналог getMe у Telegram-версии):
     * адрес отправителя и транспорт должны быть настроены, иначе рассылка
     * запрещена.
     */
    private function verifyMailerIdentity(): bool
    {
        $from = (string) config('mail.from.address');
        if ($from === '') {
            $this->error('mail.from.address не настроен — отправка запрещена.');

            return false;
        }

        $mailer = (string) config('mail.default');
        if ($mailer === '' || config('mail.mailers.'.$mailer) === null) {
            $this->error("Мейлер «{$mailer}» не настроен (config/mail.php) — отправка запрещена.");

            return false;
        }

        $this->info("Мейлер подтверждён: {$mailer}, от {$from}.");

        return true;
    }

    /** Приватный манифест прогона (storage gitignored, в коммиты не попадает). */
    private function writeManifest(string $slug, array $counts, array $results, bool $sent): void
    {
        try {
            $dir = storage_path('app/private/survey-invites');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents(
                $dir.'/'.$slug.'-email-'.now()->format('Ymd-His').($sent ? '-send' : '-dry').'.json',
                json_encode([
                    'generated_at' => now()->toIso8601String(),
                    'slug' => $slug,
                    'channel' => 'email',
                    'send_mode' => $sent,
                    'counts' => $counts,
                    'results' => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            $this->warn('Манифест не записан: '.$e->getMessage());
        }
    }
}
