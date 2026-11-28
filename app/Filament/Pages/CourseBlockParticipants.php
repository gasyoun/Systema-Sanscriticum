<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseBlockParticipantsReport;
use App\Services\DebtorReminderDispatcher;
use App\Services\Reactivation\BlockReminderSender;
use App\Support\RoleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Дашборд «Участники по блокам»: по выбранному курсу — сколько участников в его
 * группах и сколько студентов оплатили, с разбивкой по блокам и половинам.
 * Расчёты берутся из CourseBlockParticipantsReport.
 *
 * Ниже матрицы — мини-рассылка напоминаний по выбранному блоку: отметить
 * должников, выбрать каналы (аккаунт школы / бот / почта) и отправить. Отправка
 * идёт через {@see BlockReminderSender} — тот же дедуп 24 ч и журнал
 * `debt_reminders`, что у «Реактивации», чтобы должник не получил два письма
 * за день из двух экранов админки.
 */
class CourseBlockParticipants extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Участники по блокам';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 75;

    protected static ?string $title = 'Участники по блокам';

    protected static ?string $slug = 'course-block-participants';

    protected static string $view = 'filament.pages.course-block-participants';

    /** Выбранный курс (wire:model.live в blade). */
    public ?int $courseId = null;

    /** Блок, по которому ищем должников. */
    public ?int $reminderBlock = null;

    /** @var array<int, int> отмеченные студенты */
    public array $reminderIds = [];

    public string $reminderText = '';

    // По умолчанию выключен: Telegram не даёт бизнес-боту писать первым
    // (BUSINESS_PEER_USAGE_MISSING) — канал годится только как ответ.
    public bool $reminderAccount = false;

    public bool $reminderBot = false;

    public bool $reminderEmail = false;

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public function mount(): void
    {
        $this->courseId = Course::where('is_active', true)->orderBy('title')->value('id');
        $this->reminderText = DebtorReminderDispatcher::DEFAULT_TEXT;
        $this->reminderBlock = $this->defaultBlockNumber();
    }

    /** Смена курса: блока по умолчанию и чужие отметки сбрасываем. */
    public function updatedCourseId(): void
    {
        $this->reminderBlock = $this->defaultBlockNumber();
        $this->reminderIds = [];
    }

    /** Смена блока: отметки прошлого блока к новому не относятся. */
    public function updatedReminderBlock(): void
    {
        $this->reminderIds = [];
    }

    /** @return array<int, string> */
    public function getCourseOptionsProperty(): array
    {
        return Course::orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, string> */
    public function getReminderBlockOptionsProperty(): array
    {
        $out = [];
        foreach ($this->reportData()['blocks'] ?? [] as $block) {
            $label = 'Блок '.$block['number'];
            if (($block['starts_at'] ?? null) !== null) {
                $label .= ' · '.$block['starts_at']->format('d.m.Y');
            }
            $out[(int) $block['number']] = $label;
        }

        return $out;
    }

    /**
     * Должники выбранного блока: студенты курса без доступа к нему.
     *
     * @return list<array{id: int, name: string, account: bool, bot: bool, email: bool}>
     */
    public function getReminderDebtorsProperty(): array
    {
        if ($this->courseId === null || $this->reminderBlock === null) {
            return [];
        }

        $report = $this->reportData();
        if (($report['blocks'] ?? []) === []) {
            return [];
        }

        $course = Course::find($this->courseId);
        if ($course === null) {
            return [];
        }

        $sender = app(BlockReminderSender::class);
        $out = [];
        foreach (app(CourseBlockParticipantsReport::class)->debtorsFor($course, (int) $this->reminderBlock) as $debtor) {
            $user = User::find($debtor['id']);
            if ($user === null) {
                continue;
            }
            $channels = $sender->channelsFor($user);
            $out[] = [
                'id' => $user->id,
                'name' => $user->name,
                'account' => $channels['account'],
                'bot' => $channels['bot'],
                'email' => $channels['email'],
            ];
        }

        return $out;
    }

    /** Отправить напоминание отмеченным студентам выбранными каналами. */
    public function sendReminders(): void
    {
        $course = $this->courseId ? Course::find($this->courseId) : null;

        if ($course === null || $this->reminderBlock === null) {
            Notification::make()->title('Выберите курс и блок')->warning()->send();

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $this->reminderIds)));
        if ($ids === []) {
            Notification::make()->title('Отметьте хотя бы одного студента')->warning()->send();

            return;
        }

        if (! $this->reminderAccount && ! $this->reminderBot && ! $this->reminderEmail) {
            Notification::make()->title('Выберите хотя бы один канал')->warning()->send();

            return;
        }

        $text = trim($this->reminderText) !== '' ? $this->reminderText : DebtorReminderDispatcher::DEFAULT_TEXT;
        $sender = app(BlockReminderSender::class);

        $sent = 0;
        $skipped = 0;
        $noChannels = 0;
        $accountRefused = 0;

        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $result = $sender->send(
                $user,
                (int) $course->id,
                (int) $this->reminderBlock,
                $text,
                DebtorReminderDispatcher::DEFAULT_SUBJECT,
                $this->reminderAccount,
                $this->reminderBot,
                $this->reminderEmail,
            );

            if ($result['status'] === 'sent') {
                $sent++;
            } elseif ($result['status'] === 'skipped_recent') {
                $skipped++;
            } else {
                $noChannels++;
            }

            // Канал аккаунта есть в списке, но Telegram его не принял:
            // BUSINESS_PEER_USAGE_MISSING — писать первым бизнес-бот не может.
            if ($this->reminderAccount && ($result['account_error'] ?? null) !== null) {
                $accountRefused++;
            }
        }

        $this->reminderIds = [];

        $title = "Отправлено: {$sent}. Пропущено (писали за сутки): {$skipped}. Без каналов: {$noChannels}.";
        if ($accountRefused > 0) {
            $title .= " Аккаунт отказал: {$accountRefused} — Telegram не разрешает бизнес-боту писать первым.";
        }

        Notification::make()
            ->title($title)
            ->status($sent > 0 ? 'success' : 'warning')
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $course = $this->courseId ? Course::find($this->courseId) : null;
        $debtors = $course ? $this->reminder_debtors : [];

        return [
            'courseOptions' => $this->course_options,
            'course' => $course,
            'report' => $course ? $this->reportData() : null,
            'reminderBlockOptions' => $course ? $this->reminder_block_options : [],
            'reminderDebtors' => $debtors,
            // Счётчики достижимости считаются в PHP: @php-блок внутри
            // Livewire-ветки ломает компиляцию шаблона (проверено компилятором).
            'reminderReach' => [
                'account' => count(array_filter($debtors, static fn (array $d): bool => $d['account'])),
                'bot' => count(array_filter($debtors, static fn (array $d): bool => $d['bot'])),
                'email' => count(array_filter($debtors, static fn (array $d): bool => $d['email'])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reportData(): array
    {
        $course = $this->courseId ? Course::find($this->courseId) : null;

        return $course ? app(CourseBlockParticipantsReport::class)->forCourse($course) : [];
    }

    /** Последний блок курса — самый вероятный предмет напоминания. */
    private function defaultBlockNumber(): ?int
    {
        $numbers = array_map(
            static fn (array $block): int => (int) $block['number'],
            $this->reportData()['blocks'] ?? [],
        );

        return $numbers === [] ? null : max($numbers);
    }
}
