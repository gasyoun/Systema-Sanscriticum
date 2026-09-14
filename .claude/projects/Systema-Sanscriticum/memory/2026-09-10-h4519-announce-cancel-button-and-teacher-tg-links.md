# 2026-09-10 — H4519 кнопка отмены по анонсу + памятка «ник → id» (TG-линки 19/23)

## Что сделано

1. **TG-линки учителей: 19/23** (`teachers:link-telegram`) — команды бота H4253
   («Отмена ДД.ММ», «Каникулы с ДД.ММ по ДД.ММ») доступны учителям в своих группах.
   14 из whitelist `zapisi_cancel_admin_ids` (H4199) + 5 через getInfo-резолв:
   Дружинин 397893563, Клебанов 793496332, Пахомов 1404528067, Лундышева 221860621,
   Щербак 1480105818. Парибок/Соболева/Емельянов — рулинг MG 10-09: разовые курсы,
   линки не нужны (финально). Гасунс не в счёт (admin). Пароли новых User случайные —
   доступ через «Доступ» в TeacherResource.

2. **Кнопка отмены по анонсу (H4519)** — учитель пишет обычными словами
   («в четверг не смогу, врач») в чате группы → бот предлагает
   [Снять DD.MM H:i] / [Не отменять]; снимает только явный тап
   (`ScheduleMover::cancelSingle`, без сдвига). Детали и верификация:
   `changelog_queue/2026-09-10-203500-announce-cancel-button-h4519.md`.
   Флаг `TELEGRAM_ZAPISI_ANNOUNCE_CANCEL_DETECT` на .92 = true.

## Памятка: «ник есть, численного id нет» (проблема H4199 «неразрешены (5)»)

Не работают: `getChat?chat_id=@nick` у ОБОИХ ботов (zapisi + tg) — Bot API резолвит
ник только для «увиденных» ботом пользователей; ростеры
`storage/app/telegram-harvest/raw/roster/*.json` покрывают не все группы.

Работает: `getInfo('@username')` через единую support-сессию
(`MadelineClientFactory::open()` + `Cache::lock(MadelineSessionContext::lockName(), 900)`),
аккаунт MG знает этих людей (contact=true в ответе). Read-only, ~1 с/ник, lock
обязателен — не concurrent с telegram-support:sync / telegram-harvest:sync.
Одноразовый скрипт: `php artisan tinker --execute='require "/tmp/x.php";'` — файл
ОБЯЗАН начинаться с `<?php` (иначе require выведет исходник как текст).
В ответе `bot_api_id` = численный id для `teachers:link-telegram`.
getInfo-ответ содержит телефоны контактов — в логи/чаты не эхотить, только id+имя.

## Осторожность

- `teachers:link-telegram` линкует вслепую: перед прогоном проверять коллизии
  (`social_accounts` по provider_id + `users.telegram_id`).
- Тестовые артефакты смоука на проде (Group + Schedule в Sandbox) удалять начисто
  (`forceDelete`), иначе мусор уедет в публичные витрины/выгрузки.
