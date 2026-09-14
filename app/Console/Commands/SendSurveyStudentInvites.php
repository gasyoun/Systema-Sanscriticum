<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\SurveyInvitation;
use App\Models\User;
use App\Support\TelegramSendGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Однократное ЛИЧНОЕ приглашение текущих учеников в волну опроса через бота
 * кабинета (H4297; одобрение человека 07-09-2026: кабинетный бот, персональные
 * сообщения, без групп и запасных каналов).
 *
 * Кого берём (текущие ученики, а не «любые платившие когда-то»):
 *   активное членство в живой группе (left_at IS NULL, группа forming/active)
 *   ИЛИ активный доступ к курсу (course_user.status не терминальный)
 *   ИЛИ оплата за последние 6 месяцев (Payment::PAID_STATUSES) — свежий платёж
 *     как самостоятельный сигнал «сейчас учится», не «платил когда-то».
 *
 * Кого исключаем (окно охлаждения — три месяца, и ответы, и приглашения):
 *   - персонал (is_admin, админоподобные роли);
 *   - отписавшиеся (wants_messenger_announcements = false);
 *   - без telegram_id (недостижимы личным сообщением);
 *   - ответившие на любой опрос за 3 месяца (survey_responses);
 *   - приглашённые в кабинет за 3 месяца (users.cabinet_invite_sent_at);
 *   - приглашённые в саппорт-боте за 3 месяца (link_invited_at) — консервативная
 *     прокси-замена отсутствующей машинной истории опросных приглашений;
 *   - курсы с авто-триггером exit-price за 3 месяца (exit_survey_triggered_at);
 *   - уже приглашённые в ЛЮБУЮ волну опроса (survey_invitations: queued/sent/
 *     unknown; failed — разрешён повтор, отправка точно не случилась).
 *
 * REPORT-ONLY по умолчанию; отправка — только с --send, батчами --limit.
 * Перед --send проверяется личность бота через getMe (ожидание — конфиг
 * student_bot_username), при расхождении отправка запрещена.
 *
 * Отправка: строка queued (резерв) → TelegramSendGuard claim → sendMessage →
 * в строку пишется message_id и статус. Таймаут без ответа = unknown, повтор
 * вслепую не делается; детерминированный отказ 4xx = failed + release клейма;
 * 5xx Telegram = unknown (исход отправки не известен, вслепую не повторяем).
 *
 *   php artisan surveys:send-student-invites                    # сухой прогон
 *   php artisan surveys:send-student-invites --send --limit=100
 */
class SendSurveyStudentInvites extends Command
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'teacher', 'manager', 'accountant'];

    private const TERMINAL_COURSE_STATUSES = ['Выпускник', 'Покинул', 'Исключен'];

    private const RECENT_PAYMENT_MONTHS = 6;

    protected $signature = 'surveys:send-student-invites
        {--slug=student-purchase-2026-09 : Ключ волны из config/surveys.php}
        {--send : Реально отправить (без флага — сухой прогон)}
        {--limit=200 : Максимум приглашений за один прогон}';

    protected $description = 'Однократное личное приглашение текущих учеников в опрос через бота кабинета';

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

        $text = $this->invitationText($slug);
        $counts = $this->exclusionCounts();

        $this->info('Кандидаты (текущие ученики): '.$counts['candidates'].'.');
        $this->line('  + расширение свежими плательщиками: '.$counts['recent_payer_widening']);
        $this->line('  персонал: '.$counts['staff']);
        $this->line('  отписались от сообщений: '.$counts['opted_out']);
        $this->line('  без telegram_id: '.$counts['no_telegram']);
        $this->line('  отвечали на опрос за 3 мес: '.$counts['recent_responders']);
        $this->line('  приглашение в кабинет за 3 мес: '.$counts['cabinet_invited']);
        $this->line('  приглашение в саппорт-боте за 3 мес: '.$counts['support_invited']);
        $this->line('  exit-price триггер за 3 мес: '.$counts['exit_surveyed']);
        $this->line('  уже приглашены в эту волну: '.$counts['already_invited']);
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

        if (! $this->verifyBotIdentity()) {
            return self::FAILURE;
        }

        $eligible = $this->eligible()->orderBy('id')->limit($limit)->get();
        $text = $this->invitationText($slug);

        $results = [];
        $sent = $failed = $unknown = $skipped = 0;

        foreach ($eligible as $user) {
            $invitation = $this->reserve($slug, $user);
            if (! $invitation instanceof SurveyInvitation) {
                $skipped++;
                $results[] = ['user_id' => $user->id, 'chat_id' => (string) $user->telegram_id, 'status' => 'skipped_reserved'];

                continue;
            }

            if (! TelegramSendGuard::claim((string) $user->telegram_id, $text)) {
                $invitation->forceFill([
                    'status' => SurveyInvitation::STATUS_UNKNOWN,
                    'error' => 'dedup: идентичное сообщение уже отправлялось за окно TTL',
                ])->save();
                $unknown++;
                $results[] = ['user_id' => $user->id, 'chat_id' => (string) $user->telegram_id, 'status' => SurveyInvitation::STATUS_UNKNOWN];

                continue;
            }

            [$status, $messageId, $error] = $this->sendTelegram($user, $text);

            if ($status === SurveyInvitation::STATUS_FAILED) {
                TelegramSendGuard::release((string) $user->telegram_id, $text);
            }

            $invitation->forceFill([
                'status' => $status,
                'telegram_message_id' => $messageId,
                'error' => $error,
                'sent_at' => $status === SurveyInvitation::STATUS_SENT ? now() : null,
            ])->save();

            match ($status) {
                SurveyInvitation::STATUS_SENT => $sent++,
                SurveyInvitation::STATUS_FAILED => $failed++,
                default => $unknown++,
            };

            $results[] = [
                'user_id' => $user->id,
                'chat_id' => (string) $user->telegram_id,
                'status' => $status,
                'message_id' => $messageId,
                'error' => $error,
            ];

            usleep(300_000);
        }

        $this->info("Отправлено: {$sent}. Отказов: {$failed}. Неопределённых: {$unknown}. Пропущено (резерв существовал): {$skipped}.");

        Log::info('surveys:send-student-invites batch complete', [
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
     * Кумулятивный конвейер исключений: каждый следующий этап применяется
     * поверх предыдущего, счётчик этапа — дельта с предыдущей ступенью.
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

        $reached = (clone $optedIn)->whereNotNull('telegram_id')->where('telegram_id', '<>', 0);
        $counts['no_telegram'] = (clone $optedIn)->count() - (clone $reached)->count();

        $step = (clone $reached)
            ->whereNotExists(fn ($q) => $q->from('survey_responses as sr')
                ->whereColumn('sr.user_id', 'users.id')
                ->where('sr.created_at', '>=', $cutoff));
        $counts['recent_responders'] = (clone $reached)->count() - (clone $step)->count();

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
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '<>', 0)
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
                ->whereIn('si.status', [SurveyInvitation::STATUS_QUEUED, SurveyInvitation::STATUS_SENT, SurveyInvitation::STATUS_UNKNOWN]));
    }

    /**
     * Эксклюзивный атомарный захват адресата ДО отправки; гонка перезапусков
     * гасится уникальным индексом (slug, user_id) + firstOrCreate.
     *
     * Переиспользуется ТОЛЬКО failed-строка (отказ 4xx — отправка точно не
     * случилась). Строки queued (живой резерв конкурирующего запуска), sent и
     * unknown никогда не перезаписываются: updateOrCreate здесь запрещён —
     * он затирал бы message_id/статус реально отправленных приглашений.
     */
    private function reserve(string $slug, User $user): ?SurveyInvitation
    {
        try {
            $invitation = SurveyInvitation::firstOrCreate(
                ['survey_slug' => $slug, 'user_id' => $user->id],
                [
                    'telegram_chat_id' => (int) $user->telegram_id,
                    'channel' => 'telegram',
                    'status' => SurveyInvitation::STATUS_QUEUED,
                ],
            );
        } catch (QueryException $e) {
            Log::info('surveys:send-student-invites: reservation lost the race, skipping', ['user_id' => $user->id]);

            return null;
        }

        if (! $invitation->wasRecentlyCreated && $invitation->status !== SurveyInvitation::STATUS_FAILED) {
            return null;
        }

        return $invitation;
    }

    /**
     * Отправка через студенческого бота с захватом message_id.
     *
     * @return array{0: string, 1: ?int, 2: ?string} [status, message_id, sanitized error]
     */
    private function sendTelegram(User $user, string $text): array
    {
        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        try {
            $response = Http::timeout(15)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $user->telegram_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (ConnectionException $e) {
            return [SurveyInvitation::STATUS_UNKNOWN, null, $this->sanitize($e->getMessage())];
        }

        if (! $response->successful() || ! $response->json('ok')) {
            $description = (string) ($response->json('description') ?? 'http '.$response->status());

            // 5xx: Telegram не подтвердил ни успех, ни отказ — исход неизвестен,
            // повтор вслепую мог бы задвоить сообщение. Только 4xx детерминирован.
            if ($response->serverError()) {
                return [SurveyInvitation::STATUS_UNKNOWN, null, $this->sanitize('telegram 5xx: '.$description)];
            }

            return [SurveyInvitation::STATUS_FAILED, null, $this->sanitize($description)];
        }

        return [SurveyInvitation::STATUS_SENT, (int) $response->json('result.message_id'), null];
    }

    /** Токен бота не должен попасть в лог/БД через тексты ошибок Guzzle. */
    private function sanitize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $clean = preg_replace('#/bot[^/\s]+/#', '/bot[redacted]/', $message) ?? $message;

        return mb_substr($clean, 0, 480);
    }

    /** Личность бота подтверждается перед --send; расхождение останавливает рассылку. */
    private function verifyBotIdentity(): bool
    {
        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");
        } catch (ConnectionException $e) {
            $this->error('getMe недоступен: '.$this->sanitize($e->getMessage()));

            return false;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            $this->error('getMe отказал: '.$this->sanitize((string) ($response->json('description') ?? 'http '.$response->status())));

            return false;
        }

        $username = (string) $response->json('result.username');
        $expected = (string) config('services.telegram.student_bot_username');

        if ($expected !== '' && strcasecmp($username, $expected) !== 0) {
            $this->error("Личность бота не совпала: ожид @{$expected}, получен @{$username}. Отправка запрещена.");

            return false;
        }

        $this->info("Бот подтверждён: @{$username} (id ".$response->json('result.id').').');

        return true;
    }

    private function invitationText(string $slug): string
    {
        $url = rtrim((string) config('app.url'), '/').'/anketa/'.$slug;

        return 'Вы уже учитесь у нас — помогите понять, что действительно повлияло на ваш выбор '
            .'и что стоит улучшить. Подробная анкета о вашем пути, покупках и результатах займёт около '
            .'15–20 минут. Нам одинаково полезны хорошие и критические ответы. Участие '
            .'добровольное, на обучение не влияет. Если уже заполняли нашу анкету в последние '
            ."три месяца, эту можно пропустить.\n\n"
            ."<a href=\"{$url}\">Заполнить опрос</a>";
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
                $dir.'/'.$slug.'-'.now()->format('Ymd-His').($sent ? '-send' : '-dry').'.json',
                json_encode([
                    'generated_at' => now()->toIso8601String(),
                    'slug' => $slug,
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
