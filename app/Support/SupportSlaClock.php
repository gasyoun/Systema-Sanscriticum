<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * H3999 (рулинг A5): часы SLA поддержки — «сколько РАБОЧИХ минут вопрос висит
 * без ответа» и «сейчас тихий час или нет».
 *
 * Почему отдельный класс, а не пара приватных методов в команде: арифметику
 * рабочего окна надо проверять временем, а не поведением команды, и она нужна
 * и отчёту, и команде. Почему НЕ config/support_hours.php: тот блок кодирует
 * 10:00–20:00 по будням с null на выходных и питает виджет сайта
 * ({@see SupportAvailability::isOnline()}); правило SLA — 09:00–22:00 все семь
 * дней. Общий блок означал бы, что правка часов виджета молча двигает
 * эскалацию поддержки.
 *
 * Тихие часы — это ПАУЗА, а не пропуск: минуты ночи не копятся, поэтому вопрос,
 * пришедший в 21:55, получает первый пинг не в полночь, а в 09:10 следующего
 * утра, и порог «15 минут» остаётся 15 минутами рабочего времени.
 */
final class SupportSlaClock
{
    public function __construct(
        private readonly string $quietFrom = '22:00',
        private readonly string $quietTo = '09:00',
        private readonly string $timezone = 'Europe/Moscow',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('support.sla.quiet_from', '22:00'),
            (string) config('support.sla.quiet_to', '09:00'),
            (string) config('support.sla.timezone', 'Europe/Moscow'),
        );
    }

    /** Сейчас тихий час — кураторов не будим. */
    public function isQuietNow(?CarbonImmutable $now = null): bool
    {
        return $this->isQuiet($this->local($now ?? CarbonImmutable::now()));
    }

    /**
     * Рабочие минуты между двумя моментами: тихие часы не считаются.
     *
     * Считаем поминутно по границам окон, а не приближением «минус N часов за
     * ночь»: приближение расходится с правдой ровно в тех случаях, ради которых
     * SLA и существует — вопрос на границе окна.
     */
    public function workingMinutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $this->local($from);
        $end = $this->local($to);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $minutes = 0;
        $cursor = $start;

        // Шагаем по рабочим окнам суток, а не по минутам: окон максимум по
        // одному в день, и цикл остаётся дешёвым на любом lookback.
        while ($cursor->lessThan($end)) {
            [$windowStart, $windowEnd] = $this->windowFor($cursor);

            if ($cursor->lessThan($windowStart)) {
                $cursor = $windowStart;

                continue;
            }

            if ($cursor->greaterThanOrEqualTo($windowEnd)) {
                // Ночь: прыгаем на открытие следующего окна.
                $cursor = $this->windowFor($cursor->addDay()->startOfDay())[0];

                continue;
            }

            $segmentEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;
            $minutes += (int) floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            $cursor = $segmentEnd;

            if ($cursor->greaterThanOrEqualTo($windowEnd)) {
                $cursor = $this->windowFor($cursor->addDay()->startOfDay())[0];
            }
        }

        return $minutes;
    }

    private function isQuiet(CarbonImmutable $moment): bool
    {
        [$windowStart, $windowEnd] = $this->windowFor($moment);

        return $moment->lessThan($windowStart) || $moment->greaterThanOrEqualTo($windowEnd);
    }

    /**
     * Рабочее окно суток, которым живёт этот момент: [quiet_to, quiet_from).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowFor(CarbonImmutable $moment): array
    {
        [$openHour, $openMinute] = $this->parse($this->quietTo);
        [$closeHour, $closeMinute] = $this->parse($this->quietFrom);

        $open = $moment->startOfDay()->addHours($openHour)->addMinutes($openMinute);
        $close = $moment->startOfDay()->addHours($closeHour)->addMinutes($closeMinute);

        // Окно, заданное «через полночь» (например 22:00 → 09:00 как рабочее),
        // здесь не поддерживается сознательно: SLA-правило — дневное окно, а
        // ночная поддержка была бы другим продуктом, а не другой цифрой.
        if ($close->lessThanOrEqualTo($open)) {
            $close = $open;
        }

        return [$open, $close];
    }

    /** @return array{0: int, 1: int} */
    private function parse(string $time): array
    {
        $parts = explode(':', $time);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    private function local(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone($this->timezone);
    }
}
