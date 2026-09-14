{{--
    Первопартийная baseline-телеметрия кабинета (H962, Phase 0 ремейка, спека §4).

    Декларативный контракт для blade-разметки (без правок JS):
      data-track-event="offer.click" data-track-kind="next-block" ...  — клик;
      data-track-impression="offer.impression" data-track-kind="..."  — показ (1 раз при загрузке).
    Любые data-track-<x> (кроме event/impression) уходят в data[<x>].

    Инлайн-скрипт без Vite сознательно: кабинет ещё не мигрирован на Vite
    (миграция — в счёте гибрида), а телеметрия должна жить и до неё.
    fetch keepalive — чтобы клик по ссылке с уходом со страницы не терял событие.
    Никаких сторонних трекеров (R20); только свой эндпоинт student.telemetry.
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

        function send(event, data) {
            if (!event || !TOKEN) return;
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
