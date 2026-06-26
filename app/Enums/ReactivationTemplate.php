<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Каталог шаблонов реактивации (тёплый возврат «уснувшей» базы). Один к одному
 * с разделом «/реактивация-*» в ОРС `Telegram_templates.md` — здесь живёт только
 * МАРШРУТИЗАЦИЯ (какой шаблон кому предложить), сами тексты ведёт куратор.
 *
 * Заземление — win/loss-анализ custdev: рассрочка/льгота дают самый сильный
 * положительный лифт (+8/+9), свой темп — +5; искусственная срочность (−7) и
 * социальное доказательство (−6) ОТТАЛКИВАЮТ. Поэтому все шаблоны — без давления:
 * предложение и помощь, а не «успей купить».
 */
enum ReactivationTemplate: string
{
    case Price = 'реактивация-цена';
    case Time = 'реактивация-время';
    case Doubt = 'реактивация-сомнение';
    case Student = 'реактивация-студент';
    case General = 'реактивация-общая';

    /** Команда шаблона в `Telegram_templates.md` (со слешем). */
    public function command(): string
    {
        return '/'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Цена · рассрочка/льгота',
            self::Time => 'Время · свой темп, записи',
            self::Doubt => 'Сомнение · можно с нуля',
            self::Student => 'Студент · тёплый возврат',
            self::General => 'Общая',
        };
    }

    /** Когда предлагать — и на каком win/loss-аргументе строить. */
    public function whenToUse(): string
    {
        return match ($this) {
            self::Price => 'Останавливает цена (есть долг/без оплат): мягко предложить рассрочку или льготу. Лифт +8/+9.',
            self::Time => 'Не хватает времени: занятия в записи, можно в своём темпе и догонять. Лифт +5.',
            self::Doubt => 'Сомневается, потянет ли: можно с нуля, заранее знать санскрит не нужно.',
            self::Student => 'Бывший ученик не продлил: тёплое «рады видеть снова», вернуться к своей группе/темпу.',
            self::General => 'Общий тёплый повод вернуться, когда сегмент не определён.',
        };
    }

    /**
     * Маршрутизация кандидата к шаблону по типу «долга» из DebtorsReport и
     * наличию рассрочки. БЕЗ давления: это подсказка куратору, а не автопродажа.
     *
     * @param  string  $debtType  'not_renewed' | 'no_payment' (см. DebtorsReport)
     * @param  bool  $hasPromise  есть активная/просроченная рассрочка (PaymentPromise)
     */
    public static function suggestFor(string $debtType, bool $hasPromise): self
    {
        // Рассрочка уже оформлена — самый прямой повод вернуться по цене.
        if ($hasPromise) {
            return self::Price;
        }

        return match ($debtType) {
            // Был в группе, ни одной оплаты — барьер чаще всего в цене.
            'no_payment' => self::Price,
            // Платил раньше, но не продлил — тёплый возврат бывшего ученика.
            'not_renewed' => self::Student,
            default => self::General,
        };
    }
}
