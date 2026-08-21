# Архитектура: карта денег бухгалтера

_Created: 21-08-2026 · Last updated: 21-08-2026_

План: [PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md).

## 1. Границы

Один канал, который уже работает: Markdown → [AccountantGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/AccountantGuide.php) → `MarkdownGuide::html`. Карта — картинка внутри того же md, не второй Filament-page и не Mermaid, который Filament может сломать.

```
docs/accountant-money-map.html          источник постера (точный русский текст)
docs/screenshots/accountant-map/*.png   снимок постера, без ПДн, в git
docs/ACCOUNTANT_CABINET_GUIDE_RU.md     книга; Часть 0 вставляет PNG
/admin/accountant-guide                 единственный вход для человека
/admin/payout-attribution-guide         живая очередь, не дублируется
```

## 2. Почему карта в git, а кадры зарплат — нет

[H3214 (Grok 4.6) — Wave 3: accountant operational book in /admin](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3214-Grok_Systema-Sanscriticum_accountant-cabinet-guide-illustrated_21.08.26.md) кладёт PNG **живых** финэкранов в storage: на них суммы и фамилии. Постер — схема ролей и кнопок, учебных сумм нет. Публичный репозиторий его держать может. Это не отмена H3084.

## 3. Три слоя — не меню Filament

Слои режутся по **действию бухгалтера**, не по `navigationGroup`:

| Слой | Гейт в коде (ориентир) | Что писать |
|---|---|---|
| 1. Делаете | `accounting()` плюс opex/разметка/потоки на `finance()` | Сценарий с шагами |
| 2. Читаете | `finance()` управленческое | Ящик: зачем экран, чего на нём не жать |
| 3. Нет в роли | `adminOnly()` / super_admin / другие | Один абзац «не ищите» |

Юнит-экономика и стоимость лида стоят в слое 2, даже если бухгалтер их видит: это не еженедельная проводка.

## 4. Build-vs-reuse

| Кусок | Что есть | Вердикт |
|---|---|---|
| Страница книги | AccountantGuide + MarkdownGuide | **Переиспользовать**, не вторая страница |
| Живая очередь | PayoutAttributionGuide | **Ссылка**, не копия |
| Кадры сценариев | storage prefix H3214 | **Тот же контур** для новых how-to |
| Постер | нет | **Новый** HTML+PNG |
| Image model | Imagine | **Не использовать** для подписей |
| `/ru-manual` | энциклопедия без кадров | **Не запускать** как замену этому плану |

## 5. Денежный контур, который карта обязана показать

1. Банк → проводка в «Финансах» → ученик видит уроки.
2. Блок закрылся → «Зарплаты» → «Записать выплату» = учёт денег.
3. Расход школы → opex (четыре статьи), не зарплата.
4. Легаси-тариф «Расход» на системного пользователя → очередь разметки = отметка, не перевод.
5. «Потоки курса» печатает остаток со словом «предварительно», пока очередь не закрыта.
6. Возврат в «Финансах» не закрывает доступ. Взаимозачёт фиксирует акт, не платёж.

_Dr. Mārcis Gasūns_
