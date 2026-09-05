_Created: 05-09-2026 · Last updated: 05-09-2026_

# H4117 (кабинет-routes iPhone WebKit аудит): scripts/cabinet_ios_webkit_audit.mjs + root-cause находка — Tailwind Play CDN отдаёт сломанную сборку (OxAlpha z-ai/glm-5.3-flash, 05-09-2026)

Расширение H1488-паттерна ([mobile_viewport_audit.mjs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/mobile_viewport_audit.mjs)) на Playwright **WebKit** с iPhone-дескрипторами (390×844 / 360×740 / 320×568, isMobile+touch+iPhone UA) по маршрутам кабинета: /dvaram, /c/{slug}, /c/{slug}/u/{id}, SRS koloda+stats, calendar; проверки: горизонтальный overflow + offending-элементы, tap-targets <44px, form-контролы <16px (iOS focus-zoom), wire:loading/wire:offline, WebKit console errors, viewport-meta sanity. Отчёт: [CABINET_IOS_WEBKIT_AUDIT_2026-09-05.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_IOS_WEBKIT_AUDIT_2026-09-05.md) + runtime JSON; скриншоты в storage (не git).

- **Root-cause находка (F1, critical):** `cdn.tailwindcss.com` сейчас 302 → `/3.4.17`, и его in-browser JIT генерирует лишь ~128 правил, **никогда — `hidden`, `md:*`, `sm:*`** (проверено rule-walk на t=0/3s/8s, стабильно). Весь кабинет рендерится в desktop-layout на iPhone: shell 1036px на 390-viewport → overflow **646px на каждом авторизованном маршруте × каждом viewport** (WebKit и Chromium — не engine-специфично); карточка `/login` считается width:0, submit — некликабельный сливер 32×88 («html intercepts pointer events»). Идентичный H1488-аудит 24-07-2026 был зелёным на том же коде кабинета — регрессия пришла со стороны CDN, не репозитория. **Фикс (H4118 P0-0): уйти с runtime-CDN на компилируемый через Vite self-host Tailwind.**
- Статика под H4118 подтверждена прогоном: safe-area 0 использований, 100vh без dvh, wire:loading/offline = 0 всюду (включая Livewire SRS), input 14px на dashboard (focus-zoom); heartbeat-дроп и session 120min — из код-аудита, рулинги в GTD.
- Runner: guide/trial/demo-course 404 на сидированном датасете → n/a (не провалы); console-ошибки JSON — артефакты тех же 404-навигаций, на реальных маршрутах ноль.

_Dr. Mārcis Gasūns_
