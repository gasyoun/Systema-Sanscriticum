<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Mail\MembershipRenewalMail;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\MembershipRenewalReminder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * H5823 — последовательность напоминаний о продлении членства (C3, протечка
 * воронки продлений). Продление РУЧНОЕ (авто-списаний нет — шапка
 * config/membership.php), поэтому оплаченный период кончается, демон
 * `membership:expire-club` снимает право — и член молчит. Здесь он
 * НАПОМИНАЕТСЯ до отсечки, пока продление ещё бесплатно и бесконфликтно.
 *
 * Последовательность (config membership.renewal_stages, дни до ends_at):
 *   d7 → d3 → d0 → grace1 (первый день ПОСЛЕ ends_at, доступ ещё жив в
 *   грейсе — «последний шанс продлить без разрыва»).
 *
 * Правила отбора (все обязательны):
 *   - период активен (не отозван, грейс жив — ClubMembership::scopeActive);
 *   - период ПЛАТНЫЙ (tier_code != free; бесплатный грант не продлевают деньгами);
 *   - не репетиция (source != rehearsal);
 *   - студент НЕ сказал «не продлевать» (renewal_cancelled_at IS NULL) —
 *     явный отказ не надоеданием не отменяют;
 *   - стадия ещё не уходила (membership_renewal_reminders, дедуп).
 *
 * Каналы: Telegram/VK (SendMessengerAlerts) + email (Mail::raw). Ушёл хотя
 * бы один канал → строка журнала (дедуп), иначе стадия повторится завтра.
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

    protected $description = 'H5823: напоминания о продлении членства (d7 → d3 → d0 → grace1), dry-run по умолчанию';

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
        'grace1' => 'первый день грейса (после ends_at)',
    ];

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $onlyUserId = $this->option('only-user') !== null ? (int) $this->option('only-user') : null;
        $limit = max(0, (int) $this->option('limit'));

        if ($send && ! (bool) config('features.membership_renewal_reminders')) {
            $this->warn('Флаг features.membership_renewal_reminders ВЫКЛЮЧЕН — только отчёт, ничего не шлём. '
                .'Включение — отдельный ops-шаг (CLUB_MEMBERSHIP-класс: config/features.php, дефолт OFF).');
            $send = false;
        }

        $stages = $this->stages();
        if ($stages === []) {
            $this->warn('membership.renewal_stages пуст — последовательность не настроена, делать нечего.');

            return self::SUCCESS;
        }

        $query = ClubMembership::query()
            ->active()
            ->with('user')
            ->where('tier_code', '!=', 'free')
            ->where('source', '!=', 'rehearsal')
            ->whereNull('renewal_cancelled_at')
            ->orderBy('ends_at');

        if ($onlyUserId !== null) {
            $query->where('user_id', $onlyUserId);
        }

        $today = Carbon::today();
        $sent = 0;
        $skippedDedup = 0;
        $skippedNoChannel = 0;
        $rows = [];

        /** @var ClubMembership $membership */
        foreach ($query->cursor() as $membership) {
            $user = $membership->user;
            if (! $user instanceof User) {
                continue;
            }

            $daysLeft = (int) $today->startOfDay()->diffInDays($membership->ends_at->copy()->startOfDay(), false);

            foreach ($stages as $stage => $daysBefore) {
                // d7/d3/d0: от сегодня до N дней до конца. grace1 (отрицательный):
                // период уже за ends_at, но грейс жив (scopeActive это гарантирует).
                $due = $daysBefore >= 0
                    ? ($daysLeft <= $daysBefore && $daysLeft >= 0)
                    : ($daysLeft < 0);

                if (! $due) {
                    continue;
                }

                if (MembershipRenewalReminder::sentFor((int) $membership->id, $stage)) {
                    $skippedDedup++;

                    continue;
                }

                $channels = $this->channelsFor($user);
                if ($channels === []) {
                    $skippedNoChannel++;
                    $rows[] = [$membership->id, $user->id, $membership->tier_code->value, $stage, '—', 'нет каналов'];

                    continue;
                }

                $text = $this->render($stage, $user, $membership);
                $delivered = [];

                if ($send) {
                    $delivered = $this->deliver($user, $text, $channels);
                    if ($delivered === []) {
                        // Ни один канал реально не ушёл — дедуп НЕ пишем,
                        // стадия повторится на следующем проходе.
                        $rows[] = [$membership->id, $user->id, $membership->tier_code->value, $stage, '—', 'доставка не удалась'];

                        continue;
                    }

                    MembershipRenewalReminder::create([
                        'club_membership_id' => $membership->id,
                        'user_id' => $user->id,
                        'stage' => $stage,
                        'period_ends_at' => $membership->ends_at,
                        'channels' => implode('+', $delivered),
                        'sent_at' => now(),
                    ]);
                    $sent++;
                }

                $rows[] = [
                    $membership->id,
                    $user->id,
                    $membership->tier_code->value,
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
        $this->table(['период', 'студент', 'тир', 'стадия', 'каналы', 'статус'], $rows);

        return self::SUCCESS;
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
     * {pay_link}. Тексты — config membership.renewal_texts, RU, но
     * перенастраиваются без релиза (админ правит конфиг, не код).
     */
    private function render(string $stage, User $user, ClubMembership $membership): string
    {
        $tier = $membership->tier_code;
        $payLink = $this->payLink();

        $template = (string) (config("membership.renewal_texts.{$stage}")
            ?? (string) config('membership.renewal_texts.default', ''));

        return strtr($template, [
            '{name}' => $user->greetingName(),
            '{ends_date}' => $membership->ends_at->locale('ru')->translatedFormat('d F Y'),
            '{tier}' => $this->tierLabel($membership->tier_code->value),
            '{pay_link}' => $payLink,
        ]);
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

    private function payLink(): string
    {
        $slug = (string) config('membership.club.course_slug', 'club');
        $course = Course::query()->where('slug', $slug)->first(['slug']);

        return $course !== null ? route('student.course', $course->slug) : url('/login');
    }

    /**
     * Доставка по каналам. TG/VK — через очередь (SendMessengerAlerts,
     * антидубль-обвязка H2335 внутри); email — напрямую Mail::raw.
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
