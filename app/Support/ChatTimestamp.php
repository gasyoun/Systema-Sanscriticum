<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Подпись времени под сообщением в переписке.
 *
 * Лента показывала голое «H:i», из-за чего мартовское сообщение выглядело
 * сегодняшним: оператор видел «11:12» и отвечал как на свежий вопрос. Дата
 * появляется ровно тогда, когда она что-то меняет — у сообщений не за сегодня.
 */
class ChatTimestamp
{
    /** Короткая подпись: сегодня — только время, иначе с датой. */
    public static function label(?CarbonInterface $at): string
    {
        if (! $at) {
            return '';
        }

        $local = $at->timezone(config('app.timezone'));

        if ($local->isToday()) {
            return $local->format('H:i');
        }

        if ($local->isYesterday()) {
            return 'вчера '.$local->format('H:i');
        }

        return $local->isCurrentYear()
            ? $local->format('d.m, H:i')
            : $local->format('d.m.Y, H:i');
    }

    /** Полная дата для атрибута title — всегда однозначная. */
    public static function full(?CarbonInterface $at): string
    {
        return $at ? $at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '';
    }
}
