<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CourseFamilyMatcher;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * H3084, шаг 16 — акт сверки с преподавателем по семье потоков, одной страницей.
 *
 * Сервис ничего не считает заново: он берёт готовый отчёт
 * {@see CourseStreamComparisonReport::forFamily()} и раскладывает его по строкам
 * акта. Второй копии денежной арифметики в системе быть не должно — иначе PDF
 * и экран рано или поздно разойдутся, и никто не узнает, какой из них прав.
 *
 * Отдельная ПУСТАЯ строка «решение о доплате сверх остатка» внизу — требование
 * §4 PLAN: решение человека не растворяется в расчёте.
 */
class TeacherSettlementActPdf
{
    public function __construct(private readonly CourseStreamComparisonReport $report) {}

    /**
     * Данные акта. Вынесены отдельно от рендера, чтобы тест проверял цифры,
     * а не разметку.
     *
     * @return array<string, mixed>|null null = семьи нет
     */
    public function data(string $family): ?array
    {
        $report = $this->report->forFamily($family);
        if ($report === null) {
            return null;
        }

        $salary = $report['salary'];

        $streams = [];
        $revenueTotal = 0.0;
        foreach ($report['streams'] as $stream) {
            $revenueTotal += (float) $stream['revenue'];
            $streams[] = [
                'title' => (string) $stream['title'],
                'revenue' => (float) $stream['revenue'],
                'accrued' => (float) $stream['accrued'],
                'scheme' => $this->scheme($stream),
                'is_recording' => $stream['role'] === CourseFamilyMatcher::ROLE_RECORDING,
            ];
        }

        $paidLines = [];
        foreach ($salary['paid_out_lines'] as $line) {
            $paidLines[] = [
                'label' => match ($line['source']) {
                    'teacher_payouts' => 'Реестр выплат #'.$line['payout_id'],
                    'payment_expense_direct' => 'Платёж #'.$line['payment_id'],
                    'attribution_confirmed' => 'Платёж #'.$line['payment_id'].' (размечен)',
                    default => 'Строка',
                },
                'date' => $line['date'],
                'note' => (string) $line['note'],
                'amount' => (float) $line['amount'],
            ];
        }

        return [
            'family' => $family,
            'familyTitle' => (string) $report['family_title'],
            'teacherName' => (string) ($salary['teacher_name'] ?? '—'),
            'generatedOn' => now()->format('d.m.Y'),
            'streams' => $streams,
            'revenueTotal' => Money::round($revenueTotal),
            'accrued' => (float) $salary['accrued'],
            'paidLines' => $paidLines,
            'paidOut' => (float) $salary['paid_out'],
            'remainder' => (float) $salary['remainder'],
            'remainderIfAllConfirmed' => (float) $salary['remainder_if_all_confirmed'],
            'attributionConfirmed' => (bool) $salary['attribution_confirmed'],
            'pending' => $salary['pending_candidates'],
            'pendingTotal' => (float) $salary['pending_total'],
            'money' => fn (float $v): string => number_format($v, 2, ',', ' '),
        ];
    }

    public function make(string $family): ?PdfWrapper
    {
        $data = $this->data($family);
        if ($data === null) {
            return null;
        }

        $pdf = Pdf::loadView('pdf.teacher-settlement-act', $data);
        // DejaVu — единственное семейство в dompdf, у которого есть кириллица;
        // без него акт печатается пустыми прямоугольниками (как в сертификатах).
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    /** Имя файла акта — читаемое и без кириллицы в пути. */
    public function filename(string $family): string
    {
        return 'akt-sverki-'.$family.'-'.now()->format('Y-m-d').'.pdf';
    }

    /** @param array<string, mixed> $stream */
    private function scheme(array $stream): string
    {
        return match ($stream['salary_scheme']) {
            'percent' => rtrim(rtrim(number_format((float) $stream['salary_value'], 2, ',', ' '), '0'), ',').' %',
            'fixed' => number_format((float) $stream['salary_value'], 2, ',', ' ').' ₽',
            null, '' => 'схемы нет',
            default => (string) $stream['salary_scheme'].' '.(string) $stream['salary_value'],
        };
    }
}
