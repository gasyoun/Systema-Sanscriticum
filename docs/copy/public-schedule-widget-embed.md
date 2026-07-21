# Встраиваемый виджет расписания — код для WordPress (H1427)

_Created: 21-07-2026 · Last updated: 21-07-2026_

Артефакт wave 1b плана [публичного расписания](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md). **Было:** страница `samskrtam.ru/raspisanie/` вела расписание вручную. **Стало:** голый, встраиваемый в `<iframe>` виджет [`/widgets/schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicWidgetController.php) тянет ближайшие занятия по всем видимым курсам из публичного read-only фида [`/api/public/schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/PublicScheduleController.php) (строгий allowlist полей — [`PublicScheduleResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php); ни `link`, ни `zoom_*`, ни числовых id наружу), рендерит фильтруемую по направлению/преподавателю таблицу с группировкой по дням недели ([`public/widgets/schedule.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/widgets/schedule.js)) и сам сообщает свою высоту родителю для авто-ресайза.

## Код для вставки

В блочном редакторе WordPress добавьте блок **«Произвольный HTML»** и вставьте:

```
<iframe
  src="https://ORIGIN-ПРИЛОЖЕНИЯ/widgets/schedule"
  title="Расписание занятий"
  id="samskrtam-schedule"
  loading="lazy"
  style="width:100%;border:0;min-height:600px"></iframe>

<script>
window.addEventListener('message', function (event) {
  // Принимаем сообщение о высоте ТОЛЬКО от origin приложения-виджета.
  if (event.origin !== 'https://ORIGIN-ПРИЛОЖЕНИЯ') { return; }
  var data = event.data || {};
  if (data.type === 'systema-schedule-widget' && data.event === 'resize' && data.height) {
    var frame = document.getElementById('samskrtam-schedule');
    if (frame) { frame.style.minHeight = data.height + 'px'; }
  }
});
</script>
```

`ORIGIN-ПРИЛОЖЕНИЯ` — публичный origin LMS-приложения Systema (тот же домен, что отдаёт `/online/...`), **оба вхождения** заменить на реальный. Виджет разрешает встраивание только с `samskrtam.ru` / `www.samskrtam.ru` (заголовок `Content-Security-Policy: frame-ancestors` на самом ответе роута — site-wide ничего не меняется).

## Авто-ресайз

`schedule.js` постит родителю `postMessage({type:'systema-schedule-widget', event:'resize', height})` при загрузке и после каждой смены фильтра; сниппет выше подгоняет `min-height` `<iframe>` под контент, поэтому на мобильных таблица не обрезается фиксированной высотой (решение 16 плана). `min-height:600px` в `style` — только стартовое значение до первого сообщения.

## ⚠️ Публикация — действие человека

Вставка этого `<iframe>` на **живую** страницу `samskrtam.ru/raspisanie/` — ручное действие оператора, требующее отдельного явного «поехали» в чате в тот самый момент. Ни план, ни хендофф не авторизуют публикацию на живой публичный сайт заранее — даже если доступы к WordPress к тому времени уже переданы. Этот файл — только заготовка кода, а не разрешение её опубликовать.

## Решения, принятые без запроса

1. **Origin в сниппете — плейсхолдер, а не угаданный домен.** Точный публичный origin приложения знает оператор; подставлять предположение в копипастимый артефакт хуже, чем явный плейсхолдер с инструкцией заменить оба вхождения. Отвергнуто: хардкод домена из локального `.env` (там dev-значение).
2. **`X-Frame-Options` не выставляется вовсе, только CSP `frame-ancestors`.** XFO не умеет список origin и в части браузеров конфликтует с CSP; глобального XFO в проекте нет, ослаблять нечего. Отвергнуто: `X-Frame-Options: ALLOW-FROM` (устарел, не поддерживается).
3. **Проверка `event.origin` в родительском сниппете.** Без неё любой встроенный фрейм мог бы навязать высоту. Стоит одну строку, закрывает postMessage-инъекцию.

_Автор: Opus 4.8 (`claude-opus-4-8`), лейн H1427 (хендофф Sonnet-locked, исполнен на Opus 4.8 в рамках resume-сессии)._

_Dr. Mārcis Gasūns_
