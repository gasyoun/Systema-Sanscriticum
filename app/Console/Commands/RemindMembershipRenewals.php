<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Mail\MembershipRenewalMail;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\CourseAccessWindow;
use App\Models\MembershipRenewalReminder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * H5823 — последовательность напоминаний о продлении (C3, протечка воронки
 * продлений). Продление РУЧНОЕ на обеих поверхностях, поэтому срок
 * кончается, ключи закрываются — и человек молчит. Здесь он НАПОМИНАЕТСЯ
 * до отсечки, пока продление ещё бесплатно и бесконфликтно.
 *
 * ДВЕ поверхности (обе — «окно с датой отсечки», обе продлеваются деньгами):
 *
 *  1. club_period — club_memberships (клуб/подписки, H2644). Периоды
 *     append-ятся (строка = один период), есть грейс → полный конвейер
 *     d7 → d3 → d0 → grace1 (первый день ПОСЛЕ ends_at, доступ ещё жив).
 *
 *  2. access_window — course_access_windows (окна доступа к курсам
 *     Парибка, H4456/H4468; прод-данные 04-10-2026: 348 окон, 0 продлений).
 *     Строка upsert-ится по паре (user, course), грейса нет — ключи
 *     закрываются в ends_at → конвейер d7 → d3 → d0 (grace1 не применяется).
 *     Вечные именные исключения (ends_at NULL) — не напоминаем: нечего
 *     продлевать. В {tier} подставляется название курса, {pay_link} ведёт
 *     на страницу курса (продление = купить заново).
 *
 * Правила отбора (общие): стадия ещё не уходила за ЭТОТ период
 * (журнал membership_renewal_reminders, в ключе — дата конца периода:
 * у upsert-поверхности продление двигает ends_at той же строки, и новый
 * срок — новая последовательность); на club_period дополнительно —
 * студент НЕ сказал «не продлевать».
 *
 * Каналы: Telegram/VK (SendMessengerAlerts) + email (MembershipRenewalMail).
 * Ушёл хотя бы один канал → строка журнала, иначе стадия повторится завтра.
 *
 * Сухой прогон по умолчанию; отправка — только --send. Флаг
 * features.membership_renewal_reminders (дефолт OFF) запрещает --send даже
 * вручную: включение — отдельный ops-шаг на проде.
 */
final class RemindMembershipRenewals extends Command
{
    protected $signature = 'membership:renewal-reminders
        {--send : реально отправить (без флага — сухой прогон)}
        {--only-user= : ограничить одним студентом (тест-отправка MG)}
        {--limit=0 : ограничить число отправок за прогон (0 = все)}';

    protected $description = 'H5823: напоминания о продлении (окна доступа + клубные периоды), d7 → d3 → d0 [→ grace1], dry-run по умолчанию';

    /**
     * Человекочитаемые метки стадий — в отчёты и тесты. Сами сроки и тексты
     * живут в config membership.renewal_stages / renewal_texts.
     *
     * @var array<string, string>
     */
    public const STAGE_LABELS = [
        'd7' => 'за 7 дней',
        'd3' => 'за 3 дня',
        'd0' => 'в день окончания',
        'grace1' => 'первый день грейса (после ends_at, club_period)',
    ];

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $onlyUserId = $this->option('only-user') !== null ? (int) $this->option('only-user') : null;
        $limit = max(0, (int) $this->option('limit'));

        if ($send && ! (bool) config('features.membership_renewal_reminders')) {
            $this->warn('Флаг features.membership_renewal_reminders ВЫКЛЮЧЕН — только отчёт, ничего не шлём. '
                .'Включение — отдельный ops-шаг (config/features.php, дефолт OFF).');
            $send = false;
        }

        $stages = $this->stages();
        if ($stages === []) {
            $this->warn('membership.renewal_stages пуст — последовательность не настроена, делать нечего.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $sent = 0;
        $skippedDedup = 0;
        $skippedNoChannel = 0;
        $rows = [];

        foreach ($this->candidates($onlyUserId) as $candidate) {
            $daysLeft = (int) $today->copy()->diffInDays($candidate['ends_at']->copy()->startOfDay(), false);

            foreach ($stages as $stage => $daysBefore) {
                // Отрицательные стадии (после ends_at) — только поверхности
                // с грейсом (club_period); у окон ключи закрываются в ends_at.
                if ($daysBefore < 0 && ! $candidate['grace_stage_allowed']) {
                    continue;
                }

                // d7/d3/d0: от сегодня до N дней до конца; отрицательные —
                // период уже за ends_at (активность гарантирует отбор).
                $due = $daysBefore >= 0
                    ? ($daysLeft <= $daysBefore && $daysLeft >= 0)
                    : ($daysLeft < 0);

                if (! $due) {
                    continue;
                }

                if (MembershipRenewalReminder::sentFor(
                    $candidate['surface'],
                    $candidate['subject_id'],
                    $stage,
                    $candidate['ends_at'],
                )) {
                    $skippedDedup++;

                    continue;
                }

                $user = $candidate['user'];
                $channels = $this->channelsFor($user);
                if ($channels === []) {
                    $skippedNoChannel++;
                    $rows[] = [$candidate['surface'], $candidate['subject_id'], $user->id, $stage, '—', 'нет каналов'];

                    continue;
                }

                $text = $this->render($stage, $user, $candidate);
                $delivered = [];

                if ($send) {
                    $delivered = $this->deliver($user, $text, $channels);
                    if ($delivered === []) {
                        // Ни один канал реально не ушёл — дедуп НЕ пишем,
                        // стадия повторится на следующем проходе.
                        $rows[] = [$candidate['surface'], $candidate['subject_id'], $user->id, $stage, '—', 'доставка не удалась'];

                        continue;
                    }

                    MembershipRenewalReminder::create([
                        'surface' => $candidate['surface'],
                        'subject_id' => $candidate['subject_id'],
                        'user_id' => $user->id,
                        'stage' => $stage,
                        'period_ends_at' => $candidate['ends_at'],
                        'channels' => implode('+', $delivered),
                        'sent_at' => now(),
                    ]);
                    $sent++;
                }

                $rows[] = [
                    $candidate['surface'],
                    $candidate['subject_id'],
                    $user->id,
                    $stage,
                    implode('+', $send ? $delivered : $channels),
                    $send ? 'отправлено' : 'dry-run',
                ];

                if ($limit > 0 && $sent >= $limit) {
                    break 2;
                }
            }
        }

        $mode = $send ? 'ОТПРАВЛЕНО' : 'DRY-RUN';
        $this->info("{$mode}: стадий ушло {$sent}, дедуп-пропусков {$skippedDedup}, без каналов {$skippedNoChannel}.");
        $this->table(['поверхность', 'объект', 'студент', 'стадия', 'каналы', 'статус'], $rows);

        return self::SUCCESS;
    }

    /**
     * Кандидаты двух поверхностей.
     *
     * @return iterable<string, array{surface: string, subject_id: int, user: User, ends_at: Carbon, tier: string, pay_link: string, grace_stage_allowed: bool}>
     */
    private function candidates(?int $onlyUserId): iterable
    {
        // --- Поверхность 1: клубные/подписочные периоды (H2644) ---
        $clubQuery = ClubMembership::query()
            ->active()
            ->with('user')
            ->where('tier_code', '!=', 'free')
            ->where('source', '!=', 'rehearsal')
            ->whereNull('renewal_cancelled_at')
            ->orderBy('ends_at');

        if ($onlyUserId !== null) {
            $clubQuery->where('user_id', $onlyUserId);
        }

        /** @var ClubMembership $membership */
        foreach ($clubQuery->cursor() as $membership) {
            $user = $membership->user;
            if (! $user instanceof User) {
                continue;
            }

            yield [
                'surface' => MembershipRenewalReminder::SURFACE_CLUB_PERIOD,
                'subject_id' => (int) $membership->id,
                'user' => $user,
                'ends_at' => $membership->ends_at,
                'tier' => $this->tierLabel($membership->tier_code->value),
                'pay_link' => $this->clubPayLink(),
                'grace_stage_allowed' => true,
            ];
        }

        // --- Поверхность 2: окна доступа к курсам (H4456, в скоупе H4468) ---
        $scope = array_map(intval(...), (array) config('access_window.enabled_course_ids', []));
        if ($scope === []) {
            return;
        }

        $windowQuery = CourseAccessWindow::query()
            ->with('user')
            ->with('course:id,slug,title')
            ->whereNotNull('ends_at') // вечные исключения не напоминаем
            ->whereIn('course_id', $scope)
            ->orderBy('ends_at');

        if ($onlyUserId !== null) {
            $windowQuery->where('user_id', $onlyUserId);
        }

        /** @var CourseAccessWindow $window */
        foreach ($windowQuery->cursor() as $window) {
            $user = $window->user;
            $course = $window->course;
            if (! $user instanceof User || ! $course instanceof Course) {
                continue;
            }

            yield [
                'surface' => MembershipRenewalReminder::SURFACE_ACCESS_WINDOW,
                'subject_id' => (int) $window->id,
                'user' => $user,
                'ends_at' => $window->ends_at,
                'tier' => (string) $course->title,
                'pay_link' => route('student.course', $course->slug),
                // Грейса у окон нет: после ends_at реальные ключи закрыты.
                'grace_stage_allowed' => false,
            ];
        }
    }

    /**
     * Стадии последовательности: ключ = имя, значение = дней до ends_at
     * (отрицательное = после). Берётся из конфига, отсортировано по убыванию
     * daysBefore — последовательность идёт от дальнего срока к ближнему.
     *
     * @return array<string, int>
     */
    private function stages(): array
    {
        $stages = (array) config('membership.renewal_stages', []);

        arsort($stages);

        return array_map(intval(...), $stages);
    }

    /**
     * Доступные каналы студента: tg / vk / email. Email с плейсхолдерным
     * адресом карточки (@no-email.com) каналом не считается.
     *
     * @return list<string>
     */
    private function channelsFor(User $user): array
    {
        $channels = [];

        if (! empty($user->telegram_id)) {
            $channels[] = 'tg';
        }

        if (! empty($user->vk_id)) {
            $channels[] = 'vk';
        }

        $email = (string) ($user->email ?? '');
        if ($email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! str_ends_with(mb_strtolower($email), '@no-email.com')) {
            $channels[] = 'email';
        }

        return $channels;
    }

    /**
     * Рендер текста стадии. Плейсхолдеры: {name}, {ends_date}, {tier},
     * {pay_link}. Тексты — config membership.renewal_texts, RU, правятся
     * конфигом без релиза.
     *
     * @param  array{tier: string, pay_link: string}  $candidate
     */
    private function render(string $stage, User $user, array $candidate): string
    {
        $template = (string) (config("membership.renewal_texts.{$stage}")
            ?? (string) config('membership.renewal_texts.default', ''));

        return strtr($template, [
            '{name}' => $user->greetingName(),
            '{ends_date}' => $candidate['ends_at']->locale('ru')->translatedFormat('d F Y'),
            '{tier}' => $candidate['tier'],
            '{pay_link}' => $candidate['pay_link'],
        ]);
    }

    private function clubPayLink(): string
    {
        $slug = (string) config('membership.club.course_slug', 'club');
        $course = Course::query()->where('slug', $slug)->first(['slug']);

        return $course !== null ? route('student.course', $course->slug) : url('/login');
    }

    /** Человекочитаемая метка тира для текстов (RU). */
    private function tierLabel(string $tier): string
    {
        return match ($tier) {
            'basic' => 'Базовый',
            'club' => 'Клуб',
            'standard' => '«В записи»',
            'professional' => 'Professional',
            'top' => 'Top',
            default => $tier,
        };
    }

    /**
     * Доставка по каналам. TG/VK — через очередь (SendMessengerAlerts,
     * антидубль-обвязка H2335 внутри); email — напрямую MembershipRenewalMail.
     * Возвращает СПИСОК каналов, которые фактически приняты к доставке.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function deliver(User $user, string $text, array $channels): array
    {
        $delivered = [];

        if (in_array('tg', $channels, true) || in_array('vk', $channels, true)) {
            SendMessengerAlerts::dispatch(
                $user,
                $text,
                in_array('tg', $channels, true),
                in_array('vk', $channels, true),
            );
            $delivered = array_values(array_intersect($channels, ['tg', 'vk']));
        }

        if (in_array('email', $channels, true)) {
            Mail::to($user->email)->send(new MembershipRenewalMail($user, $text));
            $delivered[] = 'email';
        }

        return $delivered;
    }
}
