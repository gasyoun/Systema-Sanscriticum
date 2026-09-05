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

        // In-video resume (H1450, W2). Заполняются событием 'lesson-video-tick',
        // которое диспатчит lesson.blade.php САМ только когда флаг video_resume
        // включён — при выключенном флаге этот слушатель просто никогда не
        // срабатывает, и heartbeat шлёт ровно то же тело запроса, что до H1450.
        videoPosition: null,
        videoDuration: null,

        init() {
            if (!this.lessonId || !this.csrfToken) {
                console.warn('Heartbeat: lessonId или csrfToken не заданы');
                return;
            }

            this.lastTickTime = Date.now();

            // Запускаем тиккер раз в секунду (лёгкий)
            this.intervalId = setInterval(() => this.tick(), 1000);

            window.addEventListener('lesson-video-tick', (event) => {
                this.videoPosition = event.detail.position;
                if (event.detail.duration) { this.videoDuration = event.detail.duration; }
            });

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
         *
         * H4118: время больше не теряется молча — при сбое сети delta остаётся
         * в accumulatedSeconds (минус только после подтверждённой отправки),
         * одна немедленная повторная попытка, дальше секунды уйдут со
         * следующим тиком. sendBeacon-путь без изменений.
         */
        async send() {
            const delta = Math.min(Math.round(this.accumulatedSeconds), MAX_SEND);
            if (delta < 1) return;

            const post = async () => {
                const res = await fetch('/api/heartbeat', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.payload(delta, 'tick')),
                });
                if (!res.ok) { throw new Error('heartbeat HTTP ' + res.status); }
            };
            const commit = () => {
                this.accumulatedSeconds = Math.max(0, this.accumulatedSeconds - delta);
            };

            try {
                await post();
                commit();
            } catch (e) {
                try {
                    await post();
                    commit();
                } catch (e2) {
                    // Молчим — не спамим консоль юзера; секунды уйдут следующим тиком
                }
            }
        },

        /**
         * Финальный beacon при закрытии вкладки.
         * sendBeacon гарантированно отправляется браузером даже если страница закрывается.
         */
        sendBeacon() {
            const delta = Math.min(Math.round(this.accumulatedSeconds), MAX_SEND);
            if (delta < 1) return;

            const body = this.payload(delta, 'beacon');
            body._token = this.csrfToken; // beacon не может слать кастомные заголовки

            // sendBeacon требует Blob с content-type
            const blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
            navigator.sendBeacon('/api/heartbeat', blob);
        },

        /**
         * Тело запроса heartbeat. position/duration добавляются ТОЛЬКО если
         * пришло хотя бы одно событие 'lesson-video-tick' — иначе (флаг
         * video_resume выключен, или видео вообще не воспроизводилось) тело
         * запроса идентично тому, что было до H1450.
         */
        payload(delta, source) {
            const body = {
                lesson_id: this.lessonId,
                delta_seconds: delta,
                source: source,
            };

            if (this.videoPosition !== null) { body.position = this.videoPosition; }
            if (this.videoDuration !== null) { body.duration = this.videoDuration; }

            return body;
        },
    };
};