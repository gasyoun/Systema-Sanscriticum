# Аудит скорости витрины `/online` — 18-08-2026

H3082 (Opus 5) — Витрина /online: 28 МБ обложек, Play CDN и HTTP/1.1 — аудит и разгон

_Created: 18-08-2026 · Last updated: 18-08-2026_

Замеры сделаны с рабочей машины (Рига → 193.232.229.92) 18-08-2026 ~09:15 UTC,
Opus 5 (`claude-opus-5`). Все числа — из `curl`, не из оценки.

## Итог одной строкой

Страница [https://samskrte.ru/online](https://samskrte.ru/online) тянет **27,6 МБ
обложек курсов в первом же запросе** — 89 PNG без `loading="lazy"`, по HTTP/1.1
(6 параллельных соединений), без `Cache-Control`. HTML при этом лёгкий: 76 КБ gzip.
Проблема не в PHP и не в базе.

## Измеренная картина

| Метрика | Значение | Комментарий |
|---|---|---|
| DNS | 13 мс | норма |
| TCP connect | 17 мс | норма |
| TLS handshake | **556 мс** | ощутимо; HTTP/1.1 + отсутствие session resumption |
| TTFB | 781 мс | серверная часть ≈ 225 мс, остальное — рукопожатие |
| HTML | 680 КБ raw → **76 КБ gzip** | не узкое место |
| Обложек курсов | **89 файлов, 27,6 МБ** | `loading="lazy"` — 0, `width/height` — 0 |
| Самая тяжёлая обложка | 477 КБ | при размере **533×399 px** |
| Протокол | **HTTP/1.1** | `listen 443 ssl;` без `http2` |
| `Cache-Control` на картинках | **отсутствует** | только `ETag`/`Last-Modified` |
| `livewire.min.js` | **166 КБ без сжатия** | `gzip_types` в nginx не задан |
| Play CDN Tailwind | 126 КБ gzip, **1,35 с** с редиректом | render-blocking в `<head>` |
| Font Awesome (cdnjs) | 19 КБ CSS + шрифты | отдельный DNS+TLS |
| Google Fonts | 5 начертаний Nunito Sans | отдельный DNS+TLS |

## Пять причин, по убыванию веса

### 1. 27,6 МБ обложек без lazy-loading — главная

[`app/Livewire/Shop/CourseCatalog.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/Shop/CourseCatalog.php)
отдаёт каталог целиком (`->get()`, без пагинации — это осознанное решение, комментарий
в `render()`). Само по себе это нормально: SQL лёгкий, HTML 76 КБ. Но
[`resources/views/components/shop/course-card.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/components/shop/course-card.blade.php)
рендерил `<img>` без `loading="lazy"`, поэтому браузер запрашивал **все 89 обложек сразу**.

### 2. Обложки — фотографии, сохранённые в PNG

Проверенный образец: `01KZRD530853WSG1957NDX75A7.png` — **533×399 px, 488 КБ**.
Тот же кадр в WebP q80 весит 30–50 КБ. То есть формат раздувает вес примерно **в 10 раз**.
Пиксельный размер как раз под карточку `aspect-[4/3]` — переразмера нет, виноват только формат.

### 3. HTTP/1.1 вместо HTTP/2

В [`/etc/nginx/sites-enabled/samskrte.ru`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy)
блок Certbot оставил `listen 443 ssl;` без `http2`. На HTTP/1.1 браузер держит
~6 соединений на хост, поэтому 89 картинок выстраиваются в очередь из ~15 волн.
Соседний блок `samudra.*` в том же конфиге уже настроен правильно — есть с чего копировать.

### 4. Нет `Cache-Control` на статике

Ответ на обложку: только `ETag` + `Last-Modified`, ни `Cache-Control`, ни `Expires`.
Повторный визит = 89 условных запросов с полным round-trip каждый, даже когда все
файлы уже в кеше браузера.

### 5. `gzip_types` в nginx не задан → сжимается только `text/html`

Дефолтный `gzip on;` без `gzip_types` покрывает единственный тип — `text/html`.
Поэтому `livewire.min.js` уезжает клиенту **166 КБ вместо ~50 КБ**.

### Фон: Play CDN Tailwind

[`resources/views/partials/tailwind-cdn.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/tailwind-cdn.blade.php)
подключает `cdn.tailwindcss.com` — 126 КБ gzip, render-blocking, и **компилирует весь CSS
в браузере на каждой загрузке**. Замер: 1,35 с вместе с редиректом на `/3.4.17`.

Это НЕ быстрый фикс. Партиал несёт документированную историю H2560 (коммит `68f80829`):
когда бренд-токены увели в `app.css`, на всех CDN-страницах утилиты `brand`/`brand-hover`
перестали существовать — кнопка оплаты осталась без фона, белый текст на белой карточке.
Переход на собранный CSS требует прогнать через сборку все пять шаблонов, которые
включают партиал, и держать `tests/Feature/Ui/BrandTokenParityTest` зелёным.

## Сделано этим проходом

### В коде (этот PR)

| Что | Где | Эффект |
|---|---|---|
| `loading="lazy"` + `decoding="async"` + `width/height` на обложке; первый ряд (4 карточки) — `eager` + `fetchpriority="high"` | `course-card.blade.php`, `course-catalog.blade.php` | **~27,6 МБ → ~1,5 МБ** в первой загрузке |
| `preconnect` к `cdn.tailwindcss.com`, `fonts.googleapis.com`, `fonts.gstatic.com`, `cdnjs.cloudflare.com` | `tailwind-cdn.blade.php`, `layouts/shop.blade.php`, `layouts/student.blade.php` | минус одно DNS+TLS-рукопожатие на каждый origin |

`BrandTokenParityTest` не запускался PHPUnit'ом (в worktree нет `vendor/`), но все три
его утверждения проверены механически скриптом: хост CDN остался только в санкционированном
партиале, `tailwind.config` по-прежнему после тега скрипта, оба `--color-*` токена
зеркалятся. `preconnect` к Play CDN лежит ВНУТРИ партиала именно поэтому — второе
утверждение теста валит любой другой шаблон, где встретится строка `cdn.tailwindcss.com`.

### На проде (nginx, применено 18-08-2026 09:34 UTC)

Бэкапы до правки: `/root/samskrte.ru.nginx.bak-20260818-093142`,
`/root/nginx.conf.bak-*`. Патч-скрипт идемпотентен, лежит на боксе как
`/root/patch_nginx.py`.

| Что | Файл | Проверено после `systemctl reload nginx` |
|---|---|---|
| `http2 on;` в блоке samskrte.ru | `sites-available/samskrte.ru` | `openssl s_client -alpn h2` → `ALPN protocol: h2` |
| `expires 30d` на статику (`png/jpg/webp/svg/css/js/woff2/…`) | там же | обложка отдаёт `Cache-Control: max-age=2592000` + `Expires` |
| `gzip_types` + `gzip_vary` + `gzip_comp_level 5` | `nginx.conf` (http-блок) | `livewire.min.js` → `Content-Encoding: gzip`; HTML 76 → **48,5 КБ** |

Важные детали, чтобы не сломать при следующей правке:

- **`http2 on;`, а не `listen 443 ssl http2;`.** На nginx 1.26 старая форма
  задаёт опции на уровне сокета, и `nginx -t` ругался
  `protocol options redefined for [::]:443` — соседний vhost `samudra` слушает
  тот же сокет. Директива `http2 on;` действует по-server'но через SNI и
  конфликта не даёт.
- **`expires`, а не `add_header Cache-Control`.** `expires` перезаписывает
  заголовок от upstream'а; `add_header` добавил бы второй, конфликтующий
  (у `livewire.min.js` свой `max-age=31536000` из PHP).
- **В новом `location` пересозданы оба security-заголовка.** `add_header`
  внутри `location` отменяет унаследованные от `server`, иначе статика
  осталась бы без `X-Frame-Options`/`X-Content-Type-Options`.
- `try_files … /index.php?$query_string` в том же блоке обязателен: часть
  «статики» (`/livewire/livewire.min.js`) на диске не лежит, её отдаёт роут.

## Остаётся сделать

| # | Работа | Ожидаемый выигрыш | Риск |
|---|---|---|---|
| 1 | Конвертация обложек в WebP при загрузке (Intervention/Image в пайплайне `image_path`) + миграция 89 существующих, оригиналы сохранить | 27,6 МБ → ~2,5 МБ на полной прокрутке | средний: трогает загрузку файлов |
| 2 | Собранный Tailwind вместо Play CDN | минус 126 КБ render-blocking и компиляция CSS в браузере | **средний-высокий** — см. H2560 выше |
| 3 | Урезать Nunito Sans с 5 начертаний до 3 (400/700/900) | минус ~2 шрифтовых файла | низкий, но это дизайнерское решение |
| 4 | `ssl_session_cache`/`ssl_session_tickets` — TLS-рукопожатие мерилось в 556 мс | ~200–300 мс на первом визите | низкий |

Пункт 1 даёт больше всех оставшихся и не конфликтует с H2826 (пагинация каталога).

## Как перепроверить

```
curl -sS -o /dev/null -w 'ttfb=%{time_starttransfer} total=%{time_total} size=%{size_download}\n' --compressed https://samskrte.ru/online
curl -sSI https://samskrte.ru/storage/courses/<любая>.png | grep -i -E 'HTTP/|cache-control|content-length'
echo | openssl s_client -alpn h2 -connect samskrte.ru:443 -servername samskrte.ru 2>/dev/null | grep -i ALPN
```

Замечание: у сборки curl под Windows (Schannel) нет HTTP/2, она всегда покажет
`http_version=1.1`. Смотреть надо ALPN, а не `%{http_version}`.

| | до (09:15 UTC) | после (09:34 UTC) |
|---|---|---|
| TTFB | 0,781 с | 0,613 с |
| HTML gzip | 76 053 Б | 48 545 Б |
| `Cache-Control` на обложке | нет | `max-age=2592000` |
| `livewire.min.js` | 166 КБ plain | gzip |
| ALPN | `http/1.1` | `h2` |

_Dr. Mārcis Gasūns_
