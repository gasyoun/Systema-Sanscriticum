/**
 * Alpine.js компонент для отправки heartbeat с урока.
 *
 * Работа:
 * - Каждые TICK_INTERVAL секунд шлём POST /api/heartbeat с дельтой времени
 * - Пауза когда вкладка не активна (Page Visibility API)
 * - При закрытии вкладки шлём финальный beacon
 *
 * Использование в Blade:
 *   <div x-data="lessonHeartbeat({ lessonId: {{ $lesson->id }} })"></div>
 */
window.lessonHeartbeat = function (config) {
    const TICK_INTERVAL = 30; // секунд между отправками
    const MAX_SEND = 90;      // максимум секунд за одну отправку (совпадает с сервером)

    return {
        lessonId: config.lessonId,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),

        // Секунды, накопленные с последней отправки
        accumulatedSeconds: 0,

        // Активна ли вкладка сейчас
        isVisible: !document.hidden,

        // Id интервала для остановки
        intervalId: null,
        
        // Последний тик — в миллисекундах (для точного подсчёта дельты)
        lastTickTime: null,

        init() {
            if (!this.lessonId || !this.csrfToken) {
                console.warn('Heartbeat: lessonId или csrfToken не заданы');
                return;
            }

            this.lastTickTime = Date.now();

            // Запускаем тиккер раз в секунду (лёгкий)
            this.intervalId = setInterval(() => this.tick(), 1000);

            // Обработчики видимости вкладки
            document.addEventListener('visibilitychange', () => {
                this.isVisible = !document.hidden;
                this.lastTickTime = Date.now(); // сбрасываем счётчик при смене видимости
            });

            // Финальный beacon при закрытии / перезагрузке
            window.addEventListener('pagehide', () => this.sendBeacon());

            // Очистка при удалении компонента
            this.$el.addEventListener('alpine:destroy', () => {
                if (this.intervalId) clearInterval(this.intervalId);
                this.sendBeacon();
            });
        },

        /**
         * Тик раз в секунду — считаем прошедшее время и отправляем, когда накопилось достаточно.
         */
        tick() {
            // Пропускаем если вкладка не активна
            if (!this.isVisible) {
                this.lastTickTime = Date.now();
                return;
            }

            const now = Date.now();
            const deltaMs = now - this.lastTickTime;
            this.lastTickTime = now;

            // Защита от системного sleep (дельта > 5 сек за 1 тик — не учитываем)
            // Это бывает когда комп ушёл в сон/пользователь свернул окно с разрывом JS
            if (deltaMs > 5000) {
                return;
            }

            this.accumulatedSeconds += deltaMs / 1000;

            // Отправляем когда накопили TICK_INTERVAL секунд
            if (this.accumulatedSeconds >= TICK_INTERVAL) {
                this.send();
            }
        },

        /**
         * Обычная отправка через fetch.
         */
        async send() {
            const delta = Math.min(Math.round(this.accumulatedSeconds), MAX_SEND);
            if (delta < 1) return;

            // Сбрасываем сразу — даже если запрос упадёт, не дублируем время
            this.accumulatedSeconds = 0;

            try {
                await fetch('/api/heartbeat', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        lesson_id: this.lessonId,
                        delta_seconds: delta,
                        source: 'tick',
                    }),
                });
            } catch (e) {
                // Молчим — не спамим консоль юзеру
            }
        },

        /**
         * Финальный beacon при закрытии вкладки.
         * sendBeacon гарантированно отправляется браузером даже если страница закрывается.
         */
        sendBeacon() {
            const delta = Math.min(Math.round(this.accumulatedSeconds), MAX_SEND);
            if (delta < 1) return;

            const payload = JSON.stringify({
                lesson_id: this.lessonId,
                delta_seconds: delta,
                source: 'beacon',
                _token: this.csrfToken, // beacon не может слать кастомные заголовки
            });

            // sendBeacon требует Blob с content-type
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon('/api/heartbeat', blob);
        },
    };
};