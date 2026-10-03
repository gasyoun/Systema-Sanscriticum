<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionWeeklySnapshot;
use Carbon\CarbonImmutable;

/**
 * HTML-сводка недели для чата «Отдел заботы» (H5709), повторно использует
 * Telegram-HTML-контракт care:post (жирный/курсив/ссылки, без превью).
 *
 * Состав: топ-3 категории внешних вопросов (студенты + незалинкованные
 * обращения; внутренняя штабная переписка — отдельно и никогда в топ),
 * счётчики и доли, изменение к предыдущей неделе (только при сравнимости),
 * покрытие источников и свежесть синка, ссылка на дашборд. Сырой текст
 * сообщений, имена и идентификаторы не попадают сюда никогда.
 */
class WeeklyReportComposer
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  SupportQuestionWeeklySnapshot|null  $previous  предыдущая завершённая неделя (уже с payload/is_incomplete)
     */
    public function compose(
        array $payload,
        bool $isIncomplete,
        ?string $incompletenessReason,
        ?SupportQuestionWeeklySnapshot $previous,
        string $dashboardUrl,
    ): string {
        $student = $payload['populations'][SupportQuestionClassification::POPULATION_STUDENT];
        $enquiry = $payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY];
        $staff = $payload['populations'][SupportQuestionClassification::POPULATION_STAFF_INTERNAL];

        // CATEGORY_ORDER — list-константа: итерируем ЗНАЧЕНИЯ (буквы A–I);
        // array_keys() давал числовые ключи 0..8 и обнулял топ-3 (замерено на
        // проде 03-10-2026, H5709).
        $externalByCategory = [];
        foreach (QuestionMessageClassifier::CATEGORY_ORDER as $category) {
            $externalByCategory[$category] =
                ($student['by_category'][$category] ?? 0) + ($enquiry['by_category'][$category] ?? 0);
        }
        arsort($externalByCategory);
        $top3 = array_slice($externalByCategory, 0, 3, true);
        $externalTotal = $payload['totals']['external_questions'];

        $lines = [];
        $lines[] = sprintf(
            '<b>Недельный отчёт по вопросам студентов</b> (%s — %s)',
            $this->ruDate($payload['window']['from']),
            $this->ruDate((string) CarbonImmutable::parse($payload['window']['to'])->modify('-1 day')),
        );
        $lines[] = '';

        $lines[] = sprintf(
            'Вопросов от студентов: <b>%d</b> (уникальных спрашивающих: %d), от незалинкованных обращений: <b>%d</b>.',
            $student['questions'],
            $student['unique_questioners'],
            $enquiry['questions'],
        );

        if ($externalTotal > 0) {
            $lines[] = '';
            $lines[] = '<b>Топ тем:</b>';
            $rank = 1;
            foreach ($top3 as $category => $count) {
                $share = round($count / $externalTotal * 100);
                $delta = $this->delta($payload, $previous, $category);
                $lines[] = sprintf(
                    '%d. %s — %d (%d%%)%s',
                    $rank++,
                    $this->categoryTitle($category),
                    $count,
                    $share,
                    $delta,
                );
            }
            $unclassifiedShare = $externalTotal > 0
                ? round((($student['unclassified'] ?? 0) + ($enquiry['unclassified'] ?? 0)) / $externalTotal * 100)
                : 0;
            $lines[] = sprintf('Неклассифицированных: %d%%', $unclassifiedShare);
        } else {
            $lines[] = 'Вопросов с классифицируемыми темами за неделю нет.';
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Внутренняя координация (штаб): %d сообщений-вопросов — учтена отдельно, в топ тем не входит.',
            $staff['questions'],
        );

        $activity = $payload['activity'] ?? [];
        if (($activity['active_students'] ?? 0) > 0) {
            $lines[] = sprintf(
                'Активных студентов за неделю: %d; вопросов на 100 активных: %s.',
                $activity['active_students'],
                $activity['questions_per_100_active'] !== null
                    ? number_format((float) $activity['questions_per_100_active'], 1, ',', ' ')
                    : 'н/д',
            );
        } else {
            $lines[] = 'Нормирование по активным студентам недоступно (нет занятий в окне).';
        }

        $coverage = $payload['coverage'];
        $sync = $coverage['sync'];
        if ($isIncomplete) {
            $lines[] = sprintf('⚠️ Снапшот неполный: %s — проценты изменения подавлены.', $incompletenessReason ?? 'причина не указана');
        }
        $lines[] = sprintf(
            'Покрытие источников: %d/%d дней, чатов: %d; синк %s.',
            $coverage['days_with_incoming'],
            $coverage['days_in_window'],
            $coverage['chats_active'],
            ($sync['fresh'] ?? false) ? 'свежий' : sprintf('от %s', (string) ($sync['last_synced_at'] ?? 'н/д')),
        );

        if ($dashboardUrl !== '') {
            $lines[] = '';
            $lines[] = sprintf('<a href="%s">Дашборд недельных вопросов</a>', e($dashboardUrl));
        }

        return implode("\n", $lines);
    }

    /**
     * Δ к предыдущей неделе с подавлением на нулевых знаменателях и
     * несовместимых окнах: сравнение только той же версии классификатора,
     * того же определения студента и двух полных окон.
     */
    private function delta(array $payload, ?SupportQuestionWeeklySnapshot $previous, string $category): string
    {
        if ($previous === null) {
            return '';
        }

        $ready = WeeklyQuestionAnalytics::comparisonReady($payload, $previous->payload ?? []);
        if (! $ready['ready']) {
            return '';
        }

        $prevStudent = $previous->payload['populations'][SupportQuestionClassification::POPULATION_STUDENT]['by_category'] ?? [];
        $prevEnquiry = $previous->payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY]['by_category'] ?? [];
        $prevTotal = array_sum($prevStudent) + array_sum($prevEnquiry);
        if ($prevTotal <= 0) {
            return ''; // нулевой знаменатель — процент изменения подавлен
        }

        $prevCount = ($prevStudent[$category] ?? 0) + ($prevEnquiry[$category] ?? 0);
        if ($prevCount === 0) {
            return ' (было 0)';
        }

        $currentStudent = $payload['populations'][SupportQuestionClassification::POPULATION_STUDENT]['by_category'] ?? [];
        $currentEnquiry = $payload['populations'][SupportQuestionClassification::POPULATION_ENQUIRY]['by_category'] ?? [];
        $currentCount = ($currentStudent[$category] ?? 0) + ($currentEnquiry[$category] ?? 0);

        $change = round(($currentCount - $prevCount) / $prevCount * 100);
        if ($change === 0) {
            return ' (без изменений)';
        }

        return sprintf(' (%s%d%% к пред. неделе)', $change > 0 ? '+' : '', $change);
    }

    private function categoryTitle(string $category): string
    {
        return QuestionMessageClassifier::CATEGORY_TITLES[$category] ?? $category;
    }

    private function ruDate(string $date): string
    {
        $months = [
            1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн',
            7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
        ];
        $d = CarbonImmutable::parse($date);

        return $d->day.' '.$months[(int) $d->month];
    }
}
