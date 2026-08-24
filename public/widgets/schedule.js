/*
 * Публичный виджет расписания (H1427, wave 1b; запись на пробное — H3248).
 *
 * Обычный vanilla-JS IIFE, без зависимостей и без сборки (тот же стиль, что и
 * public/lila/telemetry.js). Тянет /api/public/schedule, строит фильтруемую
 * таблицу с группировкой по дням недели и постит свою высоту родителю через
 * postMessage, чтобы встраивающая страница могла авто-ресайзить <iframe>.
 *
 * Данные приходят строго через allowlist PublicScheduleResource, но названия
 * курсов/преподавателей вводит администратор — поэтому весь текст экранируется
 * перед вставкой в DOM, а ссылки пропускаются только http(s).
 *
 * H3248: у строк с bookable:true (флаг CRM_TRIAL_WIDGET_PUBLIC включён) рисуется
 * мини-форма «e-mail + Записаться»; POST /api/public/schedule/book создаёт
 * пробную заявку. Успех — без всяких ссылок на занятие: «Мы напишем вам».
 */
(function () {
    "use strict";

    var script = document.currentScript;
    var FEED_URL = (script && script.dataset && script.dataset.feedUrl) || "/api/public/schedule";
    var BOOK_URL = (script && script.dataset && script.dataset.bookUrl) || "";

    var WEEKDAYS = {
        1: "Понедельник",
        2: "Вторник",
        3: "Среда",
        4: "Четверг",
        5: "Пятница",
        6: "Суббота",
        7: "Воскресенье"
    };

    var root = document.getElementById("sw-schedule");
    var directionSelect = document.getElementById("sw-filter-direction");
    var teacherSelect = document.getElementById("sw-filter-teacher");
    var items = [];

    function esc(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function safeUrl(url) {
        var u = String(url == null ? "" : url);
        return /^https?:\/\//i.test(u) ? u : "";
    }

    function postHeight() {
        try {
            if (window.parent && window.parent !== window) {
                var height = document.documentElement.scrollHeight;
                window.parent.postMessage(
                    { type: "systema-schedule-widget", event: "resize", height: height },
                    "*"
                );
            }
        } catch (e) { /* межоконный доступ может быть запрещён — не роняем виджет */ }
    }

    function unique(values) {
        var seen = {};
        var out = [];
        values.forEach(function (v) {
            if (v && !Object.prototype.hasOwnProperty.call(seen, v)) {
                seen[v] = true;
                out.push(v);
            }
        });
        return out;
    }

    function fillSelect(select, values) {
        if (!select) { return; }
        values.sort(function (a, b) { return a.localeCompare(b, "ru"); });
        values.forEach(function (v) {
            var option = document.createElement("option");
            option.value = v;
            option.textContent = v;
            select.appendChild(option);
        });
    }

    function buildFilters() {
        var directions = {};
        var teachers = [];
        items.forEach(function (item) {
            (item.directions || []).forEach(function (d) {
                if (d && d.slug) { directions[d.slug] = d.name || d.slug; }
            });
            if (item.teacher) { teachers.push(item.teacher); }
        });
        Object.keys(directions).forEach(function (slug) {
            var option = document.createElement("option");
            option.value = slug;
            option.textContent = directions[slug];
            directionSelect.appendChild(option);
        });
        fillSelect(teacherSelect, unique(teachers));
    }

    function matchesFilters(item) {
        var dir = directionSelect ? directionSelect.value : "";
        var teacher = teacherSelect ? teacherSelect.value : "";
        if (dir) {
            var hit = (item.directions || []).some(function (d) { return d && d.slug === dir; });
            if (!hit) { return false; }
        }
        if (teacher && item.teacher !== teacher) { return false; }
        return true;
    }

    function bookFormHtml(item) {
        if (!BOOK_URL || !item.bookable || !item.book_token) { return ""; }
        return (
            '<div class="sw-book" data-token="' + esc(item.book_token) + '">' +
                '<input type="email" class="sw-email-input" placeholder="Ваш e-mail" aria-label="Электронная почта">' +
                '<button type="button" class="sw-btn">Записаться</button>' +
            "</div>"
        );
    }

    function setBookMessage(wrap, text, isError) {
        var old = wrap.querySelector(".sw-book-msg");
        if (old) { old.parentNode.removeChild(old); }
        var msg = document.createElement("span");
        msg.className = "sw-book-msg " + (isError ? "is-err" : "is-ok");
        msg.textContent = text;
        wrap.appendChild(msg);
    }

    function rowHtml(item) {
        var url = item.course ? safeUrl(item.course.url) : "";
        var courseTitle = item.course ? esc(item.course.title) : esc(item.title);
        var titleHtml = url
            ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + courseTitle + "</a>"
            : courseTitle;

        var metaParts = [];
        if (item.teacher) { metaParts.push(esc(item.teacher)); }
        var dirNames = (item.directions || []).map(function (d) { return esc(d.name); });
        if (dirNames.length) { metaParts.push(dirNames.join(", ")); }

        var recruiting = item.group && item.group.status === "forming";
        var badge = recruiting ? '<span class="sw-badge">Идёт набор</span>' : "<span></span>";

        return (
            '<div class="sw-row">' +
                '<span class="sw-time">' + esc(item.time || "") + "</span>" +
                '<span class="sw-title">' + titleHtml +
                    (metaParts.length ? '<span class="sw-meta">' + metaParts.join(" · ") + "</span>" : "") +
                    bookFormHtml(item) +
                "</span>" +
                badge +
            "</div>"
        );
    }

    function render() {
        if (!root) { return; }
        var visible = items.filter(matchesFilters);

        if (!visible.length) {
            root.innerHTML = '<p class="sw-state">На выбранных условиях ближайших занятий нет.</p>';
            postHeight();
            return;
        }

        var byDay = {};
        visible.forEach(function (item) {
            var wd = item.weekday || 0;
            (byDay[wd] = byDay[wd] || []).push(item);
        });

        var html = "";
        Object.keys(byDay)
            .map(Number)
            .sort(function (a, b) { return a - b; })
            .forEach(function (wd) {
                html += '<div class="sw-day"><h3>' + esc(WEEKDAYS[wd] || "") + "</h3>";
                byDay[wd].forEach(function (item) { html += rowHtml(item); });
                html += "</div>";
            });

        root.innerHTML = html;
        postHeight();
    }

    function load() {
        fetch(FEED_URL, { headers: { Accept: "application/json" }, credentials: "omit" })
            .then(function (response) {
                if (!response.ok) { throw new Error("HTTP " + response.status); }
                return response.json();
            })
            .then(function (payload) {
                items = (payload && payload.data) || [];
                buildFilters();
                render();
            })
            .catch(function () {
                if (root) {
                    root.innerHTML = '<p class="sw-state">Не удалось загрузить расписание. Попробуйте обновить страницу.</p>';
                }
                postHeight();
            });
    }

    if (directionSelect) { directionSelect.addEventListener("change", render); }
    if (teacherSelect) { teacherSelect.addEventListener("change", render); }

    // Делегирование на #sw-schedule: render() перерисовывает innerHTML,
    // а обработчик выживает и обслуживает кнопки «Записаться» всех строк.
    if (root && BOOK_URL) {
        root.addEventListener("click", function (event) {
            var target = event.target;
            var btn = target && target.closest ? target.closest(".sw-book .sw-btn") : null;
            if (!btn) { return; }
            var wrap = btn.closest(".sw-book");
            if (!wrap || wrap.getAttribute("data-done") === "1") { return; }
            var input = wrap.querySelector(".sw-email-input");
            var token = wrap.getAttribute("data-token") || "";
            var email = input ? String(input.value || "").trim() : "";

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setBookMessage(wrap, "Укажите корректный e-mail", true);
                if (input) { input.focus(); }
                return;
            }

            btn.disabled = true;

            fetch(BOOK_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                credentials: "omit",
                body: JSON.stringify({ book_token: token, email: email })
            })
                .then(function (response) {
                    if (!response.ok) { throw new Error("HTTP " + response.status); }
                    wrap.setAttribute("data-done", "1");
                    if (input) { input.parentNode.removeChild(input); }
                    btn.parentNode.removeChild(btn);
                    setBookMessage(wrap, "Спасибо! Мы напишем вам о подключении.", false);
                    postHeight();
                })
                .catch(function () {
                    btn.disabled = false;
                    setBookMessage(wrap, "Не удалось записаться. Попробуйте позже.", true);
                });
        });
    }

    window.addEventListener("load", postHeight);

    load();
})();
