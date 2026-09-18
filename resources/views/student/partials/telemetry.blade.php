{{--
    Первопартийная baseline-телеметрия кабинета (H962, Phase 0 ремейка, спека §4).

    Декларативный контракт для blade-разметки (без правок JS):
      data-track-event="offer.click" data-track-kind="next-block" ...  — клик;
      data-track-impression="offer.impression" data-track-kind="..."  — показ (1 раз при загрузке).
    Любые data-track-<x> (кроме event/impression) уходят в data[<x>].

    Инлайн-скрипт без Vite сознательно: кабинет ещё не мигрирован на Vite
    (миграция — в счёте гибрида), а телеметрия должна жить и до неё.
    fetch keepalive — чтобы клик по ссылке с уходом со страницы не терял событие.
    Никаких сторонних трекеров (R20) НАПРЯМУЮ; единственное исключение — мост
    в Метрику (MG 18-09-2026, docs/METRIKA_GOALS_SHOP_CABINET_2026-09-18.md):
    события из карты METRIKA_BRIDGE дублируются в reachGoal того же счётчика
    106964341 (имена: точки → подчёркивания). PII по-прежнему ноль — только
    имя цели. События без созданной цели (Tier 2) в карту не входят —
    мусорный reachGoal-трафик не плодим.
--}}
<script>
    (function () {
        'use strict';
        // Идемпотентность: layouts/student.blade.php подключает партиал всегда,
        // а страница могла подключить его ещё раз — два набора слушателей дали бы
        // ДВОЙНОЙ счёт кликов и импрешенов (H4185). Вешаемся ровно один раз.
        if (window.__cabinetTelemetryBound) return;
        window.__cabinetTelemetryBound = true;

        var URL = @json(route('student.telemetry'));
        var TOKEN = document.querySelector('meta[name="csrf-token"]');

        // Кабинетные цели Метрики Tier 1 (см. config/analytics.php).
        // zoom.join.click не в CLIENT_CABINET_EVENTS (пишется в
        // schedule_join_clicks сервером) — до Метрики дублируем только здесь.
        var METRIKA_BRIDGE = {
            'library.shelf.view': 'library_shelf_view',
            'path.station.view': 'path_station_view',
            'access.renewal.start': 'access_renewal_start',
            'zoom.join.click': 'zoom_join_click'
        };

        function send(event, data) {
            if (!event || !TOKEN) return;
            var goal = METRIKA_BRIDGE[event];
            if (goal && typeof window.shopReachGoal === 'function') {
                try { window.shopReachGoal(goal); } catch (e) { /* мост не ломает кабинет */ }
            }
            try {
                fetch(URL, {
                    method: 'POST',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': TOKEN.content,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ event: event, data: data || {} })
                }).catch(function () { /* телеметрия молчит про свои ошибки */ });
            } catch (e) { /* никогда не ломаем кабинет */ }
        }

        function payload(el, skipAttr) {
            var data = {};
            Array.prototype.forEach.call(el.attributes, function (attr) {
                if (attr.name.indexOf('data-track-') === 0 && attr.name !== skipAttr) {
                    data[attr.name.slice('data-track-'.length)] = attr.value;
                }
            });
            return data;
        }

        // Клики — делегированно, ловим и вложенные элементы кнопок/ссылок.
        document.addEventListener('click', function (e) {
            var el = e.target.closest && e.target.closest('[data-track-event]');
            if (el) send(el.getAttribute('data-track-event'), payload(el, 'data-track-event'));
        }, true);

        // Импрешены — один раз после загрузки, только реально отрендеренные узлы.
        window.addEventListener('load', function () {
            Array.prototype.forEach.call(
                document.querySelectorAll('[data-track-impression]'),
                function (el) {
                    send(el.getAttribute('data-track-impression'), payload(el, 'data-track-impression'));
                }
            );
        });
    })();
</script>
