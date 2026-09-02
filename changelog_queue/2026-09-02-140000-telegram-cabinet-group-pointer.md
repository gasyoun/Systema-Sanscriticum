# Telegram /кабинет group pointer (OxAlpha z-ai/glm-5.3-flash, 02-09-2026)

`/кабинет` typed in a group/supergroup now gets a short DM-pointer reply («напишите мне в личку /кабинет ваш@email.com») instead of silence — no accounts, no emails echoed, no magic links in group chats (everyone would see the one-time login link).

- [TelegramWebhookController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php): group branch answers the bare command via `CabinetProvisionBotCommand::groupPointerMessage()` and exits; same flag `telegram_cabinet_provision` (OFF = group silence, unchanged).
- [CabinetProvisionBotCommand](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/CabinetProvisionBotCommand.php): new `groupPointerMessage()` — pointer text only, no login links ever in groups.
- Tests: [TelegramCabinetProvisionTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/TelegramCabinetProvisionTest.php) 12/12 — DM-pointer reply contains no `/tg-login/`, no account created from a group message, flag-OFF silence preserved. Pint clean.
- Deploy rationale: MG runs the @rusamskrtam persona replies person-to-person; students learn the bot DM flow from the group command pointer without any access leak.
