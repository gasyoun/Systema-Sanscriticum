{{-- H4434: клиентская конверсия московского времени в зону устройства гостя.
     Работает на /raspisanie, странице курса и embed-виджете (iframe-safe, без cookie).
     Источник: <time data-msk-timestamp="unix">…</time> из FullSchedulePost::formatDateWithTimestamp().
     Guest cookie-фолбэк не нужен: Intl сам даёт зону устройства (MG ruling 09-09-2026). --}}
<script>
(function () {
    'use strict';

    function convert() {
        var tz;
        try {
            tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {
            return;
        }
        if (!tz || tz === 'Europe/Moscow') {
            return; // МСК-устройства видят как есть
        }

        var nodes = document.querySelectorAll('time[data-msk-timestamp]');
        if (!nodes.length) {
            return;
        }

        var fmtDate = new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric', month: 'long', year: 'numeric', weekday: 'long', timeZone: tz
        });
        var fmtTime = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: tz });

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            var ts = parseInt(el.getAttribute('data-msk-timestamp'), 10);
            if (!ts) continue;
            var d = new Date(ts * 1000);

            var msk = el.textContent.trim();
            var local = fmtDate.format(d) + ', ' + fmtTime.format(d) + ' (ваше время)';
            el.textContent = msk + ' · ' + local;
            el.title = 'Московское время: ' + msk;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', convert);
    } else {
        convert();
    }
})();
</script>