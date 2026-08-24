# Метадок: VOICE_CONTRACT_MONEY_SURFACES_RU.md

_Created: 24-08-2026 · Last updated: 24-08-2026_

## Назначение и аудитория

Пост-фактум голосовой контракт десяти денежных поверхностей волны
revenue-copy (H1285–H1294): норма, выведенная из `main`, таблица
расхождений с цитатой файл:строка и статус каждого. Аудитория: сессии,
которые правят копию чекаута, страниц оплаты, писем после покупки, дожима,
PayPal-пути, реферала; человек-редактор, решающий строку 10 таблицы.

## Происхождение

- Handoff: [H3136](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3136-Fable_Systema-Sanscriticum_revenue-copy-cross-lane-voice-read_19.08.26.md),
  исполнитель Fable 5 (`claude-fable-5`), 24-08-2026 — follow-up R1 плана
  [PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.meta.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.meta.md).
- Предварительный контракт, который этот документ проверяет и дополняет:
  [ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md)
  (Uprava, 19-07-2026) и общие строки
  [docs/copy/_shared_strings.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md).
- Образец формы: [VOICE_CONTRACT_ORS_VK_WALL_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md)
  (H1754) — там правила выведены из замеров вовлеченности; здесь — из
  чтения, замеров нет и быть не может (транзакционная копия не голосуется).

## Ранжированный бэклог улучшений

1. Решить строку 10 таблицы (блок «Кабинет сам умеет» ×3 письма) и применить
   одним PR во всех трех письмах.
2. Механизировать §6-чеклист: скрипт по списку файлов §1 (ё вне «всё»,
   прописное «Вы», «студент» вне льготы, «рассрочка/кредит» в копии) —
   сейчас это ручной grep.
3. Расширить охват на поверхности, которые волна не писала, но которые стоят
   на тех же экранах: `checkout/show.blade.php` целиком, `shop/show.blade.php`
   вне блоков H1291, Telegram-тексты `MarketingSetting`.
4. Пересмотреть после следующего изменения оферты: §2.7 (юридический
   островок) ссылается на текущую редакцию приложения №1.

## Ограничения

- Чтение одного человека-модели за один проход; не A/B, не замер. Строки
  «норма» — суждение, а не доказательство.
- Цитаты привязаны к `main` `756e7a55` (24-08-2026); номера строк
  устаревают с первым же коммитом в эти файлы — ориентир, не якорь.
- Менеджерские переопределения дожима в `MarketingSetting` на проде не
  читались — если стадия 1 там переопределена, новый дефолт ее не меняет.

## История ревизий

| Дата | Что | Кто |
|---|---|---|
| 24-08-2026 | Строка 10 таблицы закрыта: MG выбрал «B-reword», блок «Кабинет сам умеет» переписан в трех письмах, §5 и §2.8 обновлены | Fable 5 (`claude-fable-5`), H3136 |
| 24-08-2026 | Создан; §1–§6, 15 строк расхождений, 9 исправлено этим PR, 1 передано человеку | Fable 5 (`claude-fable-5`), H3136 |

_Dr. Mārcis Gasūns_
