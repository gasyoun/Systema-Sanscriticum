<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Carbon\CarbonInterface;

/**
 * Единый текст подтверждения отмены (MG 08-09):
 *
 *   ❗ Занятие отменено (отсутствие кворума)
 *
 *   «Рецитация сутр Патанджали, вт 15:00» не состоится.
 *   Следующее занятие: 15.09.2026 в 15:00 (МСК) — 13-е из 16.
 *   Последнее, 16-е занятие пройдет 06.10.2026.
 *
 * Причина — ровно одна главная; «N-е из M» — только когда номер известен;
 * строка о последнем занятии — когда M известен и последнее занятие ещё
 * не прошло (иначе нечего обещать). Датированный вариант (H4253) вместо
 * титула перечисляет отменённые даты.
 */
final class CancelNoticeRenderer
{
    public static function build(
        string $label,
        ?CarbonInterface $nextStart,
        ?int $number,
        ?int $total,
        ?CarbonInterface $lastStart,
        ?string $reason,
        bool $seriesEnded = false,
    ): string {
        $reason = CancelReasonResolver::sanitize($reason);

        $text = '❗ <b>Занятие отменено'.self::reasonSuffix($reason)."</b>\n\n";
        $text .= '«'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8')."» не состоится.\n";
        $text .= self::nextLines($nextStart, $number, $total, $lastStart, $seriesEnded);

        return rtrim($text);
    }

    /**
     * @param  array<int, string>  $cancelled
     * @param  array<int, string>  $missing
     */
    public static function buildDated(
        array $cancelled,
        array $missing,
        ?CarbonInterface $nextStart,
        ?int $number,
        ?int $total,
        ?CarbonInterface $lastStart,
        ?string $reason,
        bool $seriesEnded = false,
    ): string {
        $reason = CancelReasonResolver::sanitize($reason);

        $text = '❗ <b>Занятия отменены'.self::reasonSuffix($reason).'</b>'.(isset($cancelled[0]) ? ': '.implode(', ', $cancelled).'.' : '.')."\n";
        if (isset($missing[0])) {
            $text .= 'На эти даты занятий не нашлось: '.implode(', ', $missing).".\n";
        }
        $text .= self::nextLines($nextStart, $number, $total, $lastStart, $seriesEnded);

        return rtrim($text);
    }

    private static function reasonSuffix(?string $reason): string
    {
        return $reason !== null ? ' ('.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8').')' : '';
    }

    private static function nextLines(
        ?CarbonInterface $nextStart,
        ?int $number,
        ?int $total,
        ?CarbonInterface $lastStart,
        bool $seriesEnded,
    ): string {
        $text = '';
        if ($nextStart !== null) {
            $text .= 'Следующее занятие: <b>'.$nextStart->format('d.m.Y').' в '.$nextStart->format('H:i').'</b> (МСК)';
            $text .= self::ordinalSuffix($number, $total);
            $text .= ".\n";
        } elseif ($seriesEnded) {
            $text .= "Запланированных занятий в этом потоке больше нет.\n";
        }

        if ($total !== null
            && $lastStart !== null
            && $lastStart->isFuture()
            && ($nextStart === null || ! $lastStart->equalTo($nextStart))
            && ($number === null || $total === null || $number !== $total)) {
            $text .= 'Последнее, '.$total.'-е занятие пройдет <b>'.$lastStart->format('d.m.Y').'</b>.';
        }

        return $text;
    }

    /** Хвост строки «Следующее занятие…»: « — 13-е из 16» / « — 16-е из 16, последнее» / « — 13-е» / «». */
    public static function ordinalSuffix(?int $number, ?int $total): string
    {
        if ($number === null) {
            return '';
        }

        if ($total === null) {
            return ' — '.$number.'-е';
        }

        return $number === $total
            ? ' — '.$number.'-е из '.$total.', последнее'
            : ' — '.$number.'-е из '.$total;
    }
}
