# Подписка на рассылку → кабинет + полка подписчика (H324)

_Created: 07-07-2026 · Last updated: 07-07-2026_

GitHub-стиль инлайн-бокс «Подписаться на рассылку»: не-студент вводит **только
email**, а в ответ получает (а) облегчённый кабинетный аккаунт (беспарольный, вход
по одноразовой magic-ссылке) и (б) несколько бесплатных лид-магнитов на «полке
подписчика» в личном кабинете. Закрывает разрыв атрибуции лид→пользователь→оплата
из [`docs/growth-ideas-2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/growth-ideas-2026.md) (идея #8).

## Фича-флаг

Всё за флагом [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php)
`newsletter_subscribe` (env `NEWSLETTER_SUBSCRIBE_ENABLED`), **ВЫКЛ по умолчанию** —
deploy-рубильник. Пока OFF: виджет не рендерится, маршруты `/subscribe` и
`/magic/{token}` отдают 404, полка и Filament-ресурс скрыты. Прод не затронут, пока
человек не выставит `NEWSLETTER_SUBSCRIBE_ENABLED=true` после ревью.

## Поток

1. **Виджет** — `<x-newsletter-subscribe />` ([resources/views/components/newsletter-subscribe.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/components/newsletter-subscribe.blade.php)):
   email + чекбокс согласия. Ставится в футер/промо-блоки. Самогейтится по флагу.
2. **`POST /subscribe`** ([NewsletterSubscribeController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/NewsletterSubscribeController.php)):
   троттлинг (1/5с/IP + `throttle:10,1`), валидация `email` + `is_promo_agreed:accepted`.
   Ответ единый (анти-enumeration) — не раскрывает, был ли уже аккаунт.
3. **[NewsletterSubscriptionService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/NewsletterSubscriptionService.php)**:
   find-or-create `User` по нормализованному email (тот же путь, что у соц-входа:
   касты/обсерверы/атрибуция срабатывают одинаково), пометка `newsletter_subscribed_at`,
   запись `Lead`-строки (CRM/UTM), выпуск magic-токена, постановка письма в очередь.
4. **Письмо** ([NewsletterMagnetsMail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/NewsletterMagnetsMail.php),
   очередь `mailing`) — одноразовая ссылка в кабинет + список магнитов. **Основной
   канал** доставки бонусов; бот — опциональный второй канал (существующая
   lead-magnet-механика).
5. **`GET /magic/{token}`** → валидация → `Auth::login` → редирект в кабинет.

## Инварианты безопасности magic-link

Совпадают с password-reset ([MagicLinkToken](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MagicLinkToken.php)):

- **Одноразовый** — гасится атомарным `UPDATE ... WHERE consumed_at IS NULL`; проигравший гонку/replay получает 404.
- **С TTL** — по умолчанию 24 часа (`DEFAULT_TTL_MINUTES`).
- **Hashed-at-rest** — в БД только `sha256(token)`; plaintext существует лишь в письме.
- **Без enumeration** — невалид/протух/использован = одинаковый 404.

## Полка подписчика (ортогональна оплатам)

**Важно:** «полка подписчика» — это НЕ система доступа к платным урокам. Осознанное
решение: вместо того чтобы протаскивать лид-магниты через `Lesson`/`Group`/`Payment`
(рискованно для денежного ядра), полка сделана отдельным свободным слоем
([SubscriberMagnet](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SubscriberMagnet.php)):
админ-редактируемые файлы/ссылки, видимые пользователю с `newsletter_subscribed_at`.
`Tariff::accessKey()` и key-based доступ **не тронуты**; расширить платный доступ
через этот слой физически нельзя. Управление — Filament [SubscriberMagnetResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/SubscriberMagnetResource.php)
(группа «Маркетинг», только админ, только при ВКЛ флаге).

## Открытые решения (для человека при включении)

- **Double-opt-in:** сейчас клик по magic-ссылке = подтверждение подписки (одно
  письмо; согласие уже дано явным чекбоксом). Отдельный confirm-шаг не делаем.
- **Какие магниты** на полке — заполняются в Filament после включения флага.
- **Deliverability** — подтвердить, что транзакционный мейлер (как у password-reset)
  тянет объём магнит-писем, либо развести на маркетинговый отправитель.

_Dr. Mārcis Gasūns_
