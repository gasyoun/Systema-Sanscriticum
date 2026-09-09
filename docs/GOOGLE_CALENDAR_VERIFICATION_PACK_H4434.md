# Google Calendar OAuth — пакет верификации для MG (H4434, фаза 7)

_Created: 09-09-2026 · Last updated: 09-09-2026_

> Цель: открыть внешний гейт фаз 2–4 roadmap'а Google-интеграции — верификацию
> sensitive scope `calendar` в Google Cloud Console. Заполняет MG руками в
> Console; пакет готовит все ответы заранее (~40 мин).

## Контекст (уже готово, не переделывать)

- Phase 1 (студенческий ICS/webcal-фид) отгружена 04-07-2026 и работает — верификация ей не нужна, только OAuth-фазам.
- Roadmap: [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md)
- Существующий Google OAuth (Socialite-логин) использует только `email`/`profile` — не трогаем.
- Студентам OAuth не нужен (читают фид). OAuth — только преподаватели/админ (two-way).

## Шаги в Google Cloud Console (~40 мин)

1. Откройте [console.cloud.google.com](https://console.cloud.google.com) → проект, где живут ключи Socialite-логина (там уже OAuth consent screen).
2. **APIs & Services → OAuth consent screen**:
   - User Type: **External** (внутренний не подходит — ученики вне Workspace).
   - App name: **Общество ревнителей санскрита (samskrte.ru)**.
   - Support email: ваш email; Homepage: `https://samskrte.ru`; Privacy policy: `https://samskrte.ru/privacy` (если нет — завести страницу перед подачей).
   - Authorized domains: `samskrte.ru`.
   - Scopes: добавить `https://www.googleapis.com/auth/calendar` — после этого Console пометит его **sensitive** и предложит верификацию.
3. **Credentials → OAuth client** (если отдельный клиент для календаря): redirect URI `https://samskrte.ru/auth/google/calendar/callback` (путь согласовать с реализацией фазы 2; Socialite-callback не переиспользовать).
4. **Submit for verification**:
   - «Why do you need this scope?» — готовый ответ ниже.
   - Screencast/демо: YouTube-видео 1–3 мин, где админ входит через OAuth, видит запрос scope `calendar`, подтверждает, в приложении появляется связанный календарь. Google не принимает текстовые ответы — только видео.
5. Lead time: обычно 3–7 рабочих дней; иногда запрос на уточнение приходит на support email.

## Готовые ответы для формы верификации

**How the scope is used (EN, вставить как есть):**

> The samskrte.ru school platform lets teachers and administrators (never
> students) link their own Google Calendar to our internal schedule so that
> class sessions created in our admin panel appear in their Google Calendar
> and so that moving an event inside Google Calendar updates our schedule and
> notifies students. The `calendar` scope is used only to read and write
> events on calendars of the authenticated teacher/admin user and to receive
> push notifications about changes to those events. We do not read any other
> calendars, we do not access events of students, and we never store calendar
> content beyond the events linked to our schedule records.

**Почему students не получают доступ к scope:** студенты подписываются на read-only
iCal-фид (без OAuth) — двухсторонняя синхронизация нужна только преподавателям.

**Homepage / Privacy:** до подачи проверить, что `https://samskrte.ru/privacy`
отвечает 200 и описывает обработку данных; если страницы нет — создать.

## После верификации (не в этом проходе)

- Фаза 2 (OAuth connect + app→Google push) разблокируется; фазы 3–4 (two-way, course-date propagation) — за фазой 2.
- Quota: Calendar API default 1M queries/day — хватит; следить за `events.watch`-каналами (истекают ~неделя, worker должен обновлять).

---

_Dr. Mārcis Gasūns_