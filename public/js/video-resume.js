/**
 * Host-agnostic in-video resume adapters (H1450, Anton ops-gaps W2).
 *
 * Один адаптер на хост (D8): YouTube + RuTube через postMessage (уже были в
 * lesson.blade.php как ad-hoc код — вынесены сюда без изменения протокола),
 * VK/Kinescope/Vimeo — через их официальные JS SDK. Ни один плеер этих трёх
 * хостов сегодня не рендерится в student.lesson (только youtube-player/
 * rutube-player iframe'ы существуют) — адаптеры готовы к переиспользованию
 * W3 (Kinescope-пилот) без правок, но на сегодняшней странице они просто
 * ничего не делают: available() возвращает false, если нужного SDK/iframe
 * нет в DOM. Это и есть «деградирует без ошибки» из D8.
 *
 * Контракт адаптера:
 *   available(iframe) -> bool             можно ли использовать адаптер для
 *                                          этого элемента прямо сейчас
 *   attach(iframe, onTick) -> void         (опционально) подписка на события,
 *                                          onTick({ time, duration }) на каждое
 *                                          обновление позиции
 *   poll(iframe) -> void                   (опционально) шлём запрос текущей
 *                                          позиции — для хостов без push-модели
 *   parseMessage(data, iframe) -> {time, duration}|null
 *                                          парсинг window 'message' payload,
 *                                          если хост живёт на postMessage
 *   seek(iframe, seconds) -> void          перемотать и продолжить воспроизведение
 *
 * Все вызовы SDK обёрнуты в try/catch — сбой ОДНОГО адаптера никогда не
 * ломает страницу и не мешает остальным.
 */
window.VideoResumeAdapters = (function () {
    'use strict';

    function safe(fn) {
        try {
            fn();
        } catch (e) {
            // Тихо игнорируем — сбой SDK одного хоста не должен ломать страницу.
        }
    }

    var youtube = {
        // Push-модель: подписываемся 'listening', плеер сам шлёт infoDelivery.
        poll: function (iframe) {
            if (iframe && iframe.contentWindow) {
                safe(function () {
                    iframe.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1 }), '*');
                });
            }
        },
        parseMessage: function (data) {
            if (data && data.event === 'infoDelivery' && data.info && data.info.currentTime !== undefined) {
                return {
                    time: data.info.currentTime,
                    duration: data.info.duration || null,
                };
            }
            return null;
        },
        seek: function (iframe, seconds) {
            if (!iframe || !iframe.contentWindow) return;
            safe(function () {
                iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'seekTo', args: [seconds, true] }), '*');
                iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*');
            });
        },
        available: function (iframe) {
            return !!(iframe && iframe.contentWindow);
        },
    };

    var rutube = {
        // Pull-модель: явно просим текущее время каждый тик.
        poll: function (iframe) {
            if (iframe && iframe.contentWindow) {
                safe(function () {
                    iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:getCurrentTime', data: {} }), '*');
                });
            }
        },
        parseMessage: function (data) {
            if (data && data.type === 'player:currentTime' && data.data && data.data.time !== undefined) {
                // RuTube не отдаёт длительность через этот же канал (R3, под-
                // документированный API) — duration остаётся null, прогресс-бар
                // просто не показывается для этого хоста. Деградация, не ошибка.
                return { time: data.data.time, duration: null };
            }
            return null;
        },
        seek: function (iframe, seconds) {
            if (!iframe || !iframe.contentWindow) return;
            safe(function () {
                iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:setCurrentTime', data: { time: seconds } }), '*');
                iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:play', data: {} }), '*');
            });
        },
        available: function (iframe) {
            return !!(iframe && iframe.contentWindow);
        },
    };

    // --- VK Open API (VK.VideoPlayer) ---
    // Требует внешний скрипт https://vk.com/js/api/openapi.js, который сегодня
    // никогда не подключается (в student.lesson нет VK-плеера). available()
    // честно вернёт false, пока это не изменится (W3-и-далее территория).
    var vk = {
        _players: {},
        available: function (iframe) {
            return !!(iframe && typeof window.VK !== 'undefined' && window.VK && typeof window.VK.VideoPlayer === 'function');
        },
        _player: function (iframe) {
            if (!this._players[iframe.id]) {
                this._players[iframe.id] = window.VK.VideoPlayer(iframe);
            }
            return this._players[iframe.id];
        },
        poll: function (iframe, onTick) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                if (player && typeof player.getCurrentTime === 'function') {
                    player.getCurrentTime().then(function (time) {
                        onTick({ time: time, duration: null });
                    }, function () {});
                }
            });
        },
        seek: function (iframe, seconds) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                if (player && typeof player.seek === 'function') {
                    player.seek(seconds);
                }
            });
        },
    };

    // --- Kinescope Player SDK ---
    // Требует https://player.kinescope.io/latest/iframe.player.js. Готов для
    // W3 (Kinescope-пилот, "reuse W2's Kinescope resume adapter") — сегодня
    // available() всегда false, т.к. SDK не загружается и iframe не рендерится.
    var kinescope = {
        _players: {},
        available: function (iframe) {
            return !!(iframe && typeof window.Kinescope !== 'undefined' && window.Kinescope && window.Kinescope.IframePlayer);
        },
        _player: function (iframe) {
            if (!this._players[iframe.id]) {
                this._players[iframe.id] = window.Kinescope.IframePlayer.create(iframe);
            }
            return this._players[iframe.id];
        },
        poll: function (iframe, onTick) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                if (!player) return;
                Promise.all([player.getCurrentTime(), player.getDuration()]).then(function (values) {
                    onTick({ time: values[0], duration: values[1] || null });
                }, function () {});
            });
        },
        seek: function (iframe, seconds) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                if (player && typeof player.seekTo === 'function') {
                    player.seekTo(seconds);
                }
            });
        },
    };

    // --- Vimeo Player.js ---
    // Требует https://player.vimeo.com/api/player.js. Тоже inert-заготовка,
    // пока в плеере урока нет ни одного vimeo-iframe.
    var vimeo = {
        _players: {},
        available: function (iframe) {
            return !!(iframe && typeof window.Vimeo !== 'undefined' && window.Vimeo && window.Vimeo.Player);
        },
        _player: function (iframe) {
            if (!this._players[iframe.id]) {
                this._players[iframe.id] = new window.Vimeo.Player(iframe);
            }
            return this._players[iframe.id];
        },
        poll: function (iframe, onTick) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                Promise.all([player.getCurrentTime(), player.getDuration()]).then(function (values) {
                    onTick({ time: values[0], duration: values[1] || null });
                }, function () {});
            });
        },
        seek: function (iframe, seconds) {
            if (!this.available(iframe)) return;
            var self = this;
            safe(function () {
                var player = self._player(iframe);
                if (player && typeof player.setCurrentTime === 'function') {
                    player.setCurrentTime(seconds);
                    player.play();
                }
            });
        },
    };

    return {
        youtube: youtube,
        rutube: rutube,
        vk: vk,
        kinescope: kinescope,
        vimeo: vimeo,
    };
})();
