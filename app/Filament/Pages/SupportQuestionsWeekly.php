<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\SupportQuestionClassification;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use App\Services\SupportQuestions\WeeklyQuestionAnalytics;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/**
 * H5709 — недельный дашборд студенческих вопросов Telegram.
 *
 * Показывает последний снапшот (и историю последних недель): популяции с
 * явными знаменателями, категории с счётчиками, тренды. Сравнения и
 * проценты изменения — только между окнами одной версии классификатора,
 * одного определения студента и без неполных окон (иначе подавлены).
 * Внутренняя штабная переписка (июльский legacy-корпус) — отдельный блок,
 * никогда не смешивается с прямыми студенческими вопросами.
 */
class SupportQuestionsWeekly extends Page
{
    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Вопросы по неделям';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Недельные вопросы студентов';

    protected static ?string $slug = 'support-questions-weekly';

    protected static string $view = 'filament.pages.support-questions-weekly';

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    /**
     * Последний снапшот + 4 предыдущие недели той же версии классификатора.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestProperty(): ?array
    {
        $snapshot = app(WeeklyQuestionAnalytics::class)
            ->recentSnapshots(5)
            ->first();

        if ($snapshot === null) {
            return null;
        }

        return [
            'week_start' => (string) $snapshot->week_start,
            'payload' => $snapshot->payload,
            'is_incomplete' => $snapshot->is_incomplete,
            'incompleteness_reason' => $snapshot->incompleteness_reason,
        ];
    }

    /**
     * История для трендов (от старой к новой) с теми же охранными правилами.
     *
     * @return list<array{week: string, external: int, by_category: array<string, int>, complete: bool}>
     */
    public function getHistoryProperty(): array
    {
        $snapshots = app(WeeklyQuestionAnalytics::class)
            ->recentSnapshots(6)
            ->reverse()
            ->values();

        $rows = [];
        foreach ($snapshots as $snapshot) {
            $payload = $snapshot->payload;
            $student = $payload['populations'][SupportQuestionClassification::POPULATION_STUDENT]['by_category'] ?? [];
            $enquiry = $payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY]['by_category'] ?? [];
            $byCategory = [];
            foreach (QuestionMessageClassifier::CATEGORY_ORDER as $category) {
                $byCategory[$category] = ($student[$category] ?? 0) + ($enquiry[$category] ?? 0);
            }
            $rows[] = [
                'week' => CarbonImmutable::parse((string) $snapshot->week_start)->format('d.m'),
                'external' => $payload['totals']['external_questions'] ?? 0,
                'by_category' => $byCategory,
                'complete' => ! $snapshot->is_incomplete,
            ];
        }

        return $rows;
    }

    /**
     * Δ к предыдущей неделе — null (подавлено) на нулевых знаменателях и
     * несовместимых окнах.
     *
     * @return array<string, mixed>|null
     */
    public function getComparisonProperty(): ?array
    {
        $snapshots = app(WeeklyQuestionAnalytics::class)->recentSnapshots(6);
        if ($snapshots->count() < 2) {
            return null;
        }

        $current = $snapshots[0];
        $previous = $snapshots
            ->filter(fn ($s): bool => (string) $s->week_start < (string) $current->week_start)
            ->first();
        if ($previous === null) {
            return null;
        }

        $ready = WeeklyQuestionAnalytics::comparisonReady(
            array_merge($current->payload, ['is_incomplete' => $current->is_incomplete]),
            array_merge($previous->payload, ['is_incomplete' => $previous->is_incomplete]),
        );

        $student = $current->payload['populations'][SupportQuestionClassification::POPULATION_STUDENT]['by_category'] ?? [];
        $enquiry = $current->payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY]['by_category'] ?? [];
        $prevStudent = $previous->payload['populations'][SupportQuestionClassification::POPULATION_STUDENT]['by_category'] ?? [];
        $prevEnquiry = $previous->payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY]['by_category'] ?? [];

        $deltas = [];
        foreach (QuestionMessageClassifier::CATEGORY_ORDER as $category) {
            $now = ($student[$category] ?? 0) + ($enquiry[$category] ?? 0);
            $before = ($prevStudent[$category] ?? 0) + ($prevEnquiry[$category] ?? 0);
            $deltas[$category] = [
                'now' => $now,
                'before' => $before,
                'change' => $now - $before,
                'pct' => ($ready['ready'] && $before > 0) ? round(($now - $before) / $before * 100) : null,
            ];
        }

        return [
            'ready' => $ready['ready'],
            'reason' => $ready['reason'],
            'previous_week' => (string) $previous->week_start,
            'deltas' => $deltas,
        ];
    }
}
