<?php

/*
 * Лестница эскалации напоминаний должникам (debts:remind, H1289).
 *
 * Стадия выбирается по расстоянию до дедлайна оплаты (00:00 МСК дня старта
 * блока — MG rule, см. RemindDebtors::deadlineLabel):
 *
 *   1 «мягкое напоминание»  — дедлайн дальше approaching_days или неизвестен;
 *   2 «дедлайн близко»      — до дедлайна осталось approaching_days и меньше;
 *   3 «доступ под угрозой»  — дедлайн прошёл, блок идёт без оплаты;
 *   4 «доступ закрыт»       — просрочка от suspended_after_days и больше.
 *
 * Тексты стадий — App\Support\DunningStage (дефолты) с переопределением
 * менеджером в MarketingSetting (debt_reminder_stage*_text/subject).
 * Env-backed, как соседние receivables/conversion — никогда не хардкодить
 * пороги в сервисах/страницах.
 */

return [
    'approaching_days' => (int) env('DUNNING_APPROACHING_DAYS', 3),
    'suspended_after_days' => (int) env('DUNNING_SUSPENDED_AFTER_DAYS', 14),
];
