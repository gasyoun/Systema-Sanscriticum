<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Models\ChatSilenceRemoval;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AccessDiagnosticsService;
use App\Services\Telegram\TelegramAccountResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Правило «молчит в учебном чате И урок закрыт» в исполняемом виде.
 *
 * Кто в чате — ростер юзербота (telegram-harvest:roster-groups, раз в час).
 * Кто писал — корпус сообщений, который пишет вебхук Почтальона
 * (ProcessTelegramZapisiUpdate → HarvestStoreWriter). Закрыт ли урок —
 * тот же гейт, что открывает урок в кабинете (AccessDiagnosticsService):
 * оплаченные ключи, conditional под живым обещанием, LessonAccessGrant.
 * Отчёт «Должники» здесь сознательно НЕ используется.
 *
 * Чего сервис НЕ делает: не ходит в Telegram и ничего не пишет.
 */
class ChatSilenceRemovalEligibility
{
    public const BLOCKER_UNLINKED = 'unlinked';

    public const BLOCKER_AMBIGUOUS = 'ambiguous_identity';

    public const BLOCKER_STAFF = 'staff';

    public const BLOCKER_WROTE_RECENTLY = 'wrote_recently';

    public const BLOCKER_RECENTLY_JOINED = 'recently_joined';

    public const BLOCKER_NO_PAST_LESSON = 'no_past_paid_lesson';

    public const BLOCKER_LESSON_OPEN = 'lesson_open';

    public const BLOCKER_PAID_MISSING_KEY = 'paid_missing_key';

    public const BLOCKER_ALREADY_REMOVED = 'already_removed';

    public const SKIP_NO_ROSTER = 'no_roster';

    public const SKIP_STALE_ROSTER = 'stale_roster';

    public const SKIP_SILENT_CHAT = 'silent_chat';

    /** @var array<string, string> chat_id → причина, по которой чат пропущен целиком */
    private array $skippedChats = [];

    public function __construct(
        private readonly AccessDiagnosticsService $access,
        private readonly TelegramAccountResolver $accounts,
    ) {}

    public function silenceDays(): int
    {
        return max(1, (int) config('chat_silence_removal.silence_days', 7));
    }

    public function graceDays(): int
    {
        return max(0, (int) config('chat_silence_removal.access_grace_days', 3));
    }

    /**
     * @return Collection<int, ChatSilenceCandidate>
     */
    public function candidates(?int $onlyUserId = null, ?string $onlyChatId = null, ?CarbonInterface $now = null): Collection
    {
        $now = $now !== null ? Carbon::instance($now) : Carbon::now();
        $this->skippedChats = [];

        $groupsByChat = Group::query()
            ->where('status', 'active')
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->when($onlyChatId !== null, fn ($q) => $q->where('telegram_chat_id', $onlyChatId))
            ->get()
            ->groupBy(fn (Group $g): string => trim((string) $g->telegram_chat_id));

        $out = collect();

        foreach ($groupsByChat as $chatId => $groups) {
            $chatId = (string) $chatId;

            $roster = $this->roster($chatId, $now);
            if ($roster === null) {
                continue;
            }

            [$lastMessageAt, $anyoneWrote] = $this->messageActivity($chatId, $now);
            if (! $anyoneWrote) {
                // Сломанный вебхук неотличим от тишины в чате: не уверены, что
                // видим сообщения, — никого из этого чата не трогаем.
                $this->skippedChats[$chatId] = self::SKIP_SILENT_CHAT;

                continue;
            }

            $lesson = $this->lastPastPaidLesson($groups, $now);

            foreach ($roster as $member) {
                $candidate = $this->evaluate($chatId, $member, $groups, $lesson, $lastMessageAt, $now, $onlyUserId);
                if ($candidate !== null) {
                    $out->push($candidate);
                }
            }
        }

        return $out->values();
    }

    /** @return array<string, string> */
    public function skippedChats(): array
    {
        return $this->skippedChats;
    }

    /**
     * @param  array{id: string, username: ?string, name: ?string}  $member
     * @param  Collection<int, Group>  $groups
     * @param  array<string, Carbon>  $lastMessageAt
     */
    private function evaluate(
        string $chatId,
        array $member,
        Collection $groups,
        ?Lesson $lesson,
        array $lastMessageAt,
        Carbon $now,
        ?int $onlyUserId,
    ): ?ChatSilenceCandidate {
        $tgId = $member['id'];
        $userIds = $this->accounts->userIds($tgId);

        if ($onlyUserId !== null && ! $userIds->contains($onlyUserId)) {
            return null;
        }

        $user = $userIds->count() >= 1 ? User::query()->find($userIds->first()) : null;
        $last = $lastMessageAt[$tgId] ?? null;
        $blockers = [];

        if ($user === null) {
            $blockers[] = self::BLOCKER_UNLINKED;
        } elseif ($userIds->count() > 1) {
            $blockers[] = self::BLOCKER_AMBIGUOUS;
        }

        if ($user !== null && $this->isStaff($user)) {
            $blockers[] = self::BLOCKER_STAFF;
        }

        if ($last !== null && $last->greaterThanOrEqualTo($now->copy()->subDays($this->silenceDays()))) {
            $blockers[] = self::BLOCKER_WROTE_RECENTLY;
        }

        if ($user !== null && $blockers === []) {
            $blockers = [...$blockers, ...$this->accessBlockers($user, $chatId, $groups, $lesson, $now)];
        }

        return new ChatSilenceCandidate(
            telegramChatId: $chatId,
            telegramUserId: $tgId,
            memberName: $member['name'],
            memberUsername: $member['username'],
            group: $groups->first(),
            user: $user,
            lastMessageAt: $last,
            lesson: $lesson,
            blockers: array_values(array_unique($blockers)),
        );
    }

    /**
     * Дорогие проверки (доступ, членство) — только для тех, кого не отсеяли
     * дешёвые: в чате ~300 человек, и большинство отпадает раньше.
     *
     * @param  Collection<int, Group>  $groups
     * @return list<string>
     */
    private function accessBlockers(User $user, string $chatId, Collection $groups, ?Lesson $lesson, Carbon $now): array
    {
        $joinedAt = DB::table('group_user')
            ->where('user_id', $user->id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->min('created_at');
        if ($joinedAt !== null && Carbon::parse($joinedAt)->greaterThan($now->copy()->subDays($this->silenceDays()))) {
            return [self::BLOCKER_RECENTLY_JOINED];
        }

        if (ChatSilenceRemoval::isBanned((int) $user->id, $chatId)) {
            return [self::BLOCKER_ALREADY_REMOVED];
        }

        if ($lesson === null) {
            return [self::BLOCKER_NO_PAST_LESSON];
        }

        $courseId = (int) $lesson->course_id;
        $keys = $this->access->ownedKeys($user, $courseId);

        if ($this->access->isLessonAccessible($lesson, $keys, $user, $courseId)) {
            return [self::BLOCKER_LESSON_OPEN];
        }

        // Оплата блока есть, а ключа нет — это поломка данных, а не неоплата.
        if ($this->access->hasPaidCoverageMissingKey($user, $courseId, (int) $lesson->block_number, $keys)) {
            return [self::BLOCKER_PAID_MISSING_KEY];
        }

        return [];
    }

    private function isStaff(User $user): bool
    {
        $role = (string) ($user->role ?? '');

        return ($role !== '' && $role !== 'student')
            || (bool) ($user->is_admin ?? false)
            || ! empty($user->teacher_id);
    }

    /**
     * Последний прошедший платный урок курсов чата (с запасом на оплату).
     *
     * @param  Collection<int, Group>  $groups
     */
    private function lastPastPaidLesson(Collection $groups, Carbon $now): ?Lesson
    {
        $groupIds = $groups->pluck('id')->all();
        $courseIds = DB::table('course_group')->whereIn('group_id', $groupIds)->pluck('course_id')->unique()->all();
        if ($courseIds === []) {
            return null;
        }

        return Lesson::query()
            ->whereIn('course_id', $courseIds)
            ->where(fn ($q) => $q->whereNull('group_id')->orWhereIn('group_id', $groupIds))
            ->where('is_published', true)
            ->where('is_free', false)
            ->where('is_preview', false)
            ->whereNotNull('lesson_date')
            ->whereDate('lesson_date', '<=', $now->copy()->subDays($this->graceDays())->toDateString())
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Участники-люди из снимка ростера; null — снимка нет или он протух.
     *
     * @return list<array{id: string, username: ?string, name: ?string}>|null
     */
    private function roster(string $chatId, Carbon $now): ?array
    {
        $file = $this->storePath().'/roster/'.$chatId.'.json';
        if (! File::exists($file)) {
            $this->skippedChats[$chatId] = self::SKIP_NO_ROSTER;

            return null;
        }

        $decoded = json_decode((string) File::get($file), true);
        if (! is_array($decoded) || ! is_array($decoded['members'] ?? null)) {
            $this->skippedChats[$chatId] = self::SKIP_NO_ROSTER;

            return null;
        }

        $staleHours = (int) config('services.telegram_harvest.roster_stale_after_hours', 6);
        $fetchedAt = isset($decoded['fetched_at']) ? Carbon::parse((string) $decoded['fetched_at']) : null;
        if ($staleHours > 0 && ($fetchedAt === null || $fetchedAt->lessThan($now->copy()->subHours($staleHours)))) {
            $this->skippedChats[$chatId] = self::SKIP_STALE_ROSTER;

            return null;
        }

        $members = [];
        foreach ($decoded['members'] as $m) {
            if (! is_array($m) || ! empty($m['is_bot']) || empty($m['id'])) {
                continue;
            }
            $members[] = [
                'id' => (string) $m['id'],
                'username' => isset($m['username']) && $m['username'] !== '' ? (string) $m['username'] : null,
                'name' => isset($m['name']) && $m['name'] !== '' ? (string) $m['name'] : null,
            ];
        }

        return $members;
    }

    /**
     * Последнее сообщение каждого автора за history_days и признак «в окне
     * молчания в чат вообще кто-то писал».
     *
     * @return array{0: array<string, Carbon>, 1: bool}
     */
    private function messageActivity(string $chatId, Carbon $now): array
    {
        $dir = $this->storePath().'/corpus/'.$chatId;
        $historyFrom = $now->copy()->subDays(max($this->silenceDays(), (int) config('chat_silence_removal.history_days', 90)))->startOfDay();
        $windowFrom = $now->copy()->subDays($this->silenceDays());

        $last = [];
        $anyoneWrote = false;

        foreach (File::glob($dir.'/*.jsonl') ?: [] as $file) {
            $day = basename($file, '.jsonl');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || Carbon::parse($day)->endOfDay()->lessThan($historyFrom)) {
                continue;
            }

            foreach (preg_split('/\r\n|\n|\r/', (string) File::get($file)) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $row = json_decode($line, true);
                $author = is_array($row) ? trim((string) ($row['telegram_user_id'] ?? '')) : '';
                $sentAt = is_array($row) && ! empty($row['sent_at']) ? Carbon::parse((string) $row['sent_at']) : null;
                if ($author === '' || $sentAt === null) {
                    continue;
                }

                if ($sentAt->greaterThanOrEqualTo($windowFrom)) {
                    $anyoneWrote = true;
                }
                if (! isset($last[$author]) || $sentAt->greaterThan($last[$author])) {
                    $last[$author] = $sentAt;
                }
            }
        }

        return [$last, $anyoneWrote];
    }

    private function storePath(): string
    {
        return (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
    }

    public static function blockerLabel(string $blocker): string
    {
        return match ($blocker) {
            self::BLOCKER_UNLINKED => 'аккаунт не сопоставлен с учёткой',
            self::BLOCKER_AMBIGUOUS => 'аккаунт привязан к нескольким учёткам',
            self::BLOCKER_STAFF => 'персонал',
            self::BLOCKER_WROTE_RECENTLY => 'писал в чат недавно',
            self::BLOCKER_RECENTLY_JOINED => 'недавно в группе',
            self::BLOCKER_NO_PAST_LESSON => 'у курса нет прошедшего платного урока',
            self::BLOCKER_LESSON_OPEN => 'урок открыт в кабинете',
            self::BLOCKER_PAID_MISSING_KEY => 'оплата есть, но ключа доступа нет (ошибка данных)',
            self::BLOCKER_ALREADY_REMOVED => 'уже исключён',
            self::SKIP_NO_ROSTER => 'нет снимка состава чата',
            self::SKIP_STALE_ROSTER => 'снимок состава чата устарел',
            self::SKIP_SILENT_CHAT => 'в чате никто не писал — не уверены, что видим сообщения',
            default => $blocker,
        };
    }
}
