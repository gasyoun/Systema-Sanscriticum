<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\LogRecord;

/**
 * 152-ФЗ: маскирование ПДн в логах приложения.
 *
 * Почта и телефоны в тексте сообщения и в контексте заменяются частично (iv***@yandex.ru,
 * +7 *** ***-**-84) — по логу ещё можно опознать запись при разборе
 * инцидента, но полный адрес/номер не хранится 14 дней в storage/logs.
 *
 * Подключается через 'tap' каналов single/daily (config/logging.php).
 * Выключатель — config('logging.mask_personal_data') (LOG_MASK_PERSONAL_DATA).
 */
class MaskPersonalData
{
    private const EMAIL = '/([A-Za-z0-9._%+-]{1,2})[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/';

    // Российские номера: +7 / 8 и ещё 10 цифр с любыми разделителями (пробел, -, (, )).
    private const PHONE = '/(?<!\d)(?:\+7|8)(?:[\s\-()]*\d){10}(?!\d)/';

    public function __invoke(IlluminateLogger $logger): void
    {
        if (! config('logging.mask_personal_data', true)) {
            return;
        }

        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::mask($record->message),
                context: self::maskArray($record->context),
            );
        });
    }

    public static function mask(string $text): string
    {
        $text = (string) preg_replace(self::EMAIL, '$1***@$2', $text);

        return (string) preg_replace_callback(self::PHONE, function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]);

            return '+7 *** ***-**-'.substr((string) $digits, -2);
        }, $text);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function maskArray(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::mask($value);
            } elseif (is_array($value)) {
                $data[$key] = self::maskArray($value, $depth + 1);
            }
        }

        return $data;
    }
}
