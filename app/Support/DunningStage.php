<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\DebtorReminderDispatcher;
use Carbon\CarbonInterface;

/**
 * Четыре стадии лестницы напоминаний должникам (H1289). Одна температура не
 * доводит студента от «вы забыли» до «доступ закрыт», не начав пилить рано
 * или не шокировав поздно — поэтому стадия выбирается по расстоянию до
 * дедлайна (00:00 МСК дня старта блока), пороги — config/dunning.php.
 *
 * Тексты по умолчанию здесь; менеджер переопределяет каждую стадию в
 * MarketingSetting (пусто = дефолт). Плейсхолдеры — только существующий
 * набор MessagePlaceholders::KEYS, новых не вводим (кастомный шаблон
 * менеджера не подхватывает новые плейсхолдеры — DEPLOY_QUEUE №11).
 *
 * Строка «Если оплата уже внесена — просто проигнорируйте…» обязана быть в
 * каждой стадии: она держит всю лестницу человечной и покрывает мессенджеры,
 * где нет email-футера.
 *
 * Стадия 4 честна по механике доступа: материалы неоплаченного блока
 * закрыты автоматически (Lesson::isUnlockedBy), а получатель по построению
 * не отчислен — «Исключен»/«Покинул» не попадают в выборку должников
 * (DebtorsReport::NON_DEBT_STATUSES). Win-back после отчисления — территория
 * H219, сюда не пишем.
 */
enum DunningStage: int
{
    case SoftNudge = 1;
    case ApproachingDeadline = 2;
    case AccessAtRisk = 3;
    case AccessSuspended = 4;

    /**
     * Стадия по дедлайну. Без дедлайна (у блока нет starts_at) эскалировать
     * не по чему — остаёмся на мягком напоминании.
     */
    public static function fromDeadline(?CarbonInterface $deadline, CarbonInterface $now): self
    {
        if ($deadline === null) {
            return self::SoftNudge;
        }

        $approaching = max(0, (int) config('dunning.approaching_days', 3));
        $suspended = max(1, (int) config('dunning.suspended_after_days', 14));

        if ($now->gte($deadline->copy()->addDays($suspended))) {
            return self::AccessSuspended;
        }
        if ($now->gte($deadline)) {
            return self::AccessAtRisk;
        }
        if ($now->gte($deadline->copy()->subDays($approaching))) {
            return self::ApproachingDeadline;
        }

        return self::SoftNudge;
    }

    /** Колонка MarketingSetting с текстом стадии (пусто = дефолт). */
    public function settingTextKey(): string
    {
        return $this === self::SoftNudge
            ? 'debt_reminder_text'
            : "debt_reminder_stage{$this->value}_text";
    }

    /** Колонка MarketingSetting с темой письма стадии (пусто = дефолт). */
    public function settingSubjectKey(): string
    {
        return $this === self::SoftNudge
            ? 'debt_reminder_subject'
            : "debt_reminder_stage{$this->value}_subject";
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::SoftNudge => DebtorReminderDispatcher::DEFAULT_TEXT,
            self::ApproachingDeadline => "Намасте, {name}!\n\nБлок №{block} курса «{course}» стартует совсем скоро, а оплата пока не поступила.{paid_until}{deadline} Если оплата не придет до старта, доступ к материалам блока не откроется — а догонять группу труднее, чем идти с ней в ногу.\n\nОплатить курс: {pay_link}\n\nЕсли оплата уже внесена — просто проигнорируйте это сообщение.",
            self::AccessAtRisk => "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идет, а оплата так и не поступила.{paid_until}{deadline} Материалы блока пока закрыты: они откроются сразу после оплаты, обычно в течение пары минут.\n\nЕсли сейчас оплатить не получается — напишите нам в Telegram (https://t.me/rusamskrtam), вместе найдем решение.\n\nОплатить курс: {pay_link}\n\nЕсли оплата уже внесена — просто проигнорируйте это сообщение.",
            self::AccessSuspended => "Намасте, {name}!\n\nОплата за блок №{block} курса «{course}» не поступила, поэтому доступ к материалам блока закрыт.{paid_until}{deadline} Это не отчисление: место в группе за вами, а доступ откроется сразу после оплаты — обычно в течение пары минут.\n\nЕсли оплатить не получается — напишите нам в Telegram (https://t.me/rusamskrtam), обсудим, как быть дальше.\n\nОплатить курс: {pay_link}\n\nЕсли оплата уже внесена — просто проигнорируйте это сообщение.",
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::SoftNudge => DebtorReminderDispatcher::DEFAULT_SUBJECT,
            self::ApproachingDeadline => 'Срок оплаты близко — {course}',
            self::AccessAtRisk => 'Оплата не поступила — {course}',
            self::AccessSuspended => 'Доступ к материалам закрыт — {course}',
        };
    }

    /** Человекочитаемое имя стадии — для подсказок в Filament. */
    public function label(): string
    {
        return match ($this) {
            self::SoftNudge => 'Стадия 1 — мягкое напоминание',
            self::ApproachingDeadline => 'Стадия 2 — дедлайн близко',
            self::AccessAtRisk => 'Стадия 3 — доступ под угрозой',
            self::AccessSuspended => 'Стадия 4 — доступ закрыт',
        };
    }
}
