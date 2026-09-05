_Created: 06-07-2026 · Last updated: 05-09-2026_

# Деплой аудит-фиксов (merge develop → main, коммит `8a90b7b`, 2026-07-02)

Главный риск: вебхуки TG/VK теперь **fail-closed** — без двух новых секретов
в `.env` боты начнут отвечать 403 сразу после выкатки.

## 1. Перед выкаткой — секреты (критично)

Сгенерировать два секрета (`openssl rand -hex 32`) и добавить на проде в `.env`:

```
TELEGRAM_BOT_WEBHOOK_SECRET=...
VK_CALLBACK_SECRET=...
```

Сразу после выкатки зарегистрировать их у провайдеров:

- **Telegram**: перевызвать `setWebhook` с `secret_token=<тот же секрет>`;
- **VK**: вписать тот же секрет в настройках Callback API группы.

Между выкаткой и этим шагом вебхуки отвечают 403 — TG и VK ретраят доставку,
окно в пару минут безболезненно, но затягивать нельзя.

## 2. Последовательность на сервере (`/var/www/html`)

```bash
git pull origin main
composer install --no-dev -o        # новый класс SocialEmailNotVerifiedException → свежий classmap
php artisan migrate --force         # подтянутся отложенные миграции (см. п. 3)
npm ci && npm run build             # если фронт собирается на сервере
php artisan optimize:clear
php artisan config:cache
php artisan filament:optimize-clear && php artisan filament:optimize   # новые Filament-ресурсы
systemctl restart php8.3-fpm        # opcache
supervisorctl restart horizon       # именно так, НЕ horizon:terminate
ps aux | grep '[h]orizon'           # проверить, что START обновился
```

## 3. Миграции-хвост

Если прод давно не обновлялся, `migrate` накатит ранее смерженные фичи:
напоминания о занятиях (`reminded_at`), письмо с записью вебинара,
Zoom-посещаемость, мягкий выход из группы (`left_at`). После этого их команды
планировщика заработают по-настоящему.

Отдельно: Zoom-посещаемость требует еще Event Subscription, report-scope и
`ZOOM_WEBHOOK_SECRET` — если включаем этим же деплоем.

## 4. Проверка после

- [ ] `getWebhookInfo` у Telegram-бота: `last_error_message` пуст, апдейты доходят
- [ ] Тестовое сообщение TG- и VK-боту — куратор отвечает
- [ ] Смоук чекаута: страница оплаты открывается, промокод применяется
      (на `/checkout/*/promo` throttle 10/мин, на `/payment/create` — 5/мин)
- [ ] Архив сертификатов из админки качается у персонала
      (студентам теперь 403 — ожидаемо, IDOR закрыт)

## Что еще едет в этом merge (для контекста)

- Анти-takeover чекаута: гость с существующим email получает отказ вместо
  тихого платежа на чужой аккаунт (`PaymentController::resolveUser`).
- Соцавторизация: привязка по email только при подтвержденном email провайдера
  (`SocialEmailNotVerifiedException`), иначе stub-адрес.
- Magnet-джобы TG/VK/MAX: `failed()`-хук с логированием недоставки.
- Tochka-вебхук больше не пишет полный payload в логи (перс. данные).

_Dr. Mārcis Gasūns_
