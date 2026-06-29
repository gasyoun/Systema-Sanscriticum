<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Курс валюты для конвертации рублёвой выплаты преподавателю в валюту (PayPal).
 * Возвращает «₽ за 1 единицу валюты» на конкретную дату.
 *
 * Источник — exchangerate.host (исторические курсы по дате, есть RUB). На
 * бесплатном тарифе base всегда USD, поэтому курс RUB/EUR считаем кросс-курсом
 * (USDRUB / USDEUR). Реальный курс PayPal публично недоступен — это близкое
 * официальное приближение, которое куратор может поправить вручную.
 *
 * Фича аддитивная: нет ключа / API недоступен → возвращаем null, и калькулятор
 * остаётся на ручном вводе курса.
 */
class CurrencyRateProvider
{
    private const ENDPOINT = 'https://api.exchangerate.host/historical';

    private const SUPPORTED = ['USD', 'EUR'];

    /**
     * Сколько рублей за 1 единицу $currency на дату $date. Null, если валюта не
     * поддерживается, ключ не задан или курс получить не удалось.
     */
    public function rublesPerUnit(string $currency, DateTimeInterface $date): ?float
    {
        $currency = strtoupper($currency);

        if (! in_array($currency, self::SUPPORTED, true)) {
            return null;
        }

        $key = config('services.exchangerate.key');
        if (empty($key)) {
            Log::warning('CurrencyRateProvider: EXCHANGERATE_HOST_KEY не задан — авто-курс выключен.');

            return null;
        }

        $day = Carbon::instance(Carbon::parse($date))->toDateString();

        // Курс за прошедший день неизменен — кэшируем надолго; за сегодня — на час.
        $ttl = $day < Carbon::today()->toDateString() ? now()->addDays(30) : now()->addHour();

        return Cache::remember("fx:{$currency}:{$day}", $ttl, function () use ($currency, $day, $key): ?float {
            return $this->fetch($currency, $day, (string) $key);
        });
    }

    /** Запрос к API и расчёт кросс-курса. Любая проблема → null (не кэшируется как число). */
    private function fetch(string $currency, string $day, string $key): ?float
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get(self::ENDPOINT, [
                    'access_key' => $key,
                    'date' => $day,
                    'source' => 'USD',
                    'currencies' => 'RUB,EUR',
                ]);

            $data = $response->json();

            if (! $response->successful() || ! ($data['success'] ?? false)) {
                Log::warning('CurrencyRateProvider: некорректный ответ exchangerate.host', [
                    'day' => $day,
                    'status' => $response->status(),
                    'error' => $data['error'] ?? null,
                ]);

                return null;
            }

            $usdRub = (float) ($data['quotes']['USDRUB'] ?? 0);
            if ($usdRub <= 0) {
                return null;
            }

            // ₽ за 1 USD = USDRUB; ₽ за 1 EUR = USDRUB / USDEUR.
            if ($currency === 'USD') {
                return round($usdRub, 4);
            }

            $usdEur = (float) ($data['quotes']['USDEUR'] ?? 0);
            if ($usdEur <= 0) {
                return null;
            }

            return round($usdRub / $usdEur, 4);
        } catch (\Throwable $e) {
            Log::warning('CurrencyRateProvider: ошибка запроса курса', [
                'day' => $day,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
