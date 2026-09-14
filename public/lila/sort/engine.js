/* ============================================================
   Sort-into-groups exercise engine.
   Renders a drag-and-drop / tap-to-place sorting drill from a
   plain config object. No dependencies, no network.

   Usage:
     SortExercise.mount(containerEl, config);
   where config = {
     task:     "Распределите слова по группам.",   // optional instruction line
     feedback: "Задание выполнено верно.",          // shown when all correct
     perRound: 4,          // optional: sample N items per group each round
     shuffle:  true,        // optional (default true)
     groups: [
       { label:"Мужской род", sub:"masculine", image:"url",   // sub/image optional
         items: [ { text:"अजः", hint:"ajaḥ · козёл" }, ... ] },
       ...   // 2–8 groups
     ]
   }
   ============================================================ */
(function (global) {
  "use strict";

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function shuffleArr(a) {
    a = a.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function mount(container, cfg) {
    cfg = cfg || {};
    var groups = (cfg.groups || []).filter(function (g) { return g && g.items && g.items.length; });
    if (groups.length < 2) {
      container.innerHTML = '<p style="color:#b23b2e;font-family:sans-serif">' +
        'Нужны минимум две группы, каждая хотя бы с одним элементом.</p>';
      return null;
    }
    var doShuffle = cfg.shuffle !== false;
    var perRound = (typeof cfg.perRound === "number" && cfg.perRound > 0) ? cfg.perRound : 0;

    container.classList.add("sortx");
    container.innerHTML = "";
    container.style.setProperty("--cols", Math.min(groups.length, 4));

    if (cfg.task) container.appendChild(el("p", "task", esc(cfg.task)));

    // toolbar
    var toolbar = el("div", "toolbar");
    var hintBtn  = el("button", "ghost", "Показать подсказки");
    hintBtn.setAttribute("aria-pressed", "false");
    var checkBtn = el("button", "primary", "Проверить");
    var resetBtn = el("button", "ghost", "Заново");
    var spacer   = el("span", "spacer");
    // H4840 — visible round stopwatch (MG 14-09-2026).
    var timerEl  = el("span", "timer", "0:00");
    timerEl.setAttribute("role", "timer");
    timerEl.setAttribute("aria-label", "Время раунда");
    var score    = el("span", "score");
    var hasHints = groups.some(function (g) { return g.items.some(function (it) { return it.hint; }); });
    if (hasHints) toolbar.appendChild(hintBtn);
    toolbar.appendChild(checkBtn);
    toolbar.appendChild(resetBtn);
    toolbar.appendChild(spacer);
    toolbar.appendChild(timerEl);
    toolbar.appendChild(score);
    container.appendChild(toolbar);

    // board
    var board = el("div", "board");
    var zones = [];
    groups.forEach(function (g, gi) {
      var bucket = el("section", "bucket grp-" + (gi % 8));
      bucket.setAttribute("aria-label", g.label || ("Группа " + (gi + 1)));
      var inner = "";
      if (g.image) inner += '<img class="grp-img" alt="" src="' + esc(g.image) + '">';
      inner += '<h2>' + esc(g.label || ("Группа " + (gi + 1))) + '</h2>';
      if (g.sub) inner += '<p class="sub">' + esc(g.sub) + '</p>';
      bucket.innerHTML = inner;
      var drop = el("div", "drop");
      drop.dataset.gi = gi;
      bucket.appendChild(drop);
      board.appendChild(bucket);
      zones.push(drop);
    });
    container.appendChild(board);

    // tray
    var trayWrap = el("div", "tray");
    trayWrap.appendChild(el("p", "tray-label", "Карточки"));
    var tray = el("div", "drop");
    tray.dataset.gi = "tray";
    trayWrap.appendChild(tray);
    container.appendChild(trayWrap);

    // feedback
    var feedback = el("div", "feedback",
      '<span class="om">🕉</span><span>' +
      esc(cfg.feedback || "Задание выполнено верно.") + '</span>');
    container.appendChild(feedback);

    var selected = null;
    var totalItems = 0;

    // H4840 — per-item difficulty stats (qid -> {label, ms, wrong}) + the
    // visible stopwatch. ms = time from build() to the card's first placement.
    var roundStart = 0;
    var itemStats = {};
    var timerStart = 0;
    var timerFrozen = false;
    var timerHandle = null;

    function now() {
      return (typeof performance !== "undefined" && performance.now)
        ? performance.now() : Date.now();
    }
    function timerText(ms) {
      var s = Math.max(0, Math.floor(ms / 1000));
      return Math.floor(s / 60) + ":" + ("0" + (s % 60)).slice(-2);
    }
    function timerReset() {
      timerFrozen = false;
      timerStart = now();
      timerEl.textContent = "0:00";
      if (!timerHandle) {
        timerHandle = setInterval(function () {
          if (!timerFrozen) timerEl.textContent = timerText(now() - timerStart);
        }, 500);
      }
    }
    function timerStop() {
      timerFrozen = true;
      timerEl.textContent = timerText(now() - timerStart);
    }

    function clearSelection() {
      if (selected) selected.classList.remove("selected");
      selected = null;
    }
    function selectCard(c) {
      if (selected === c) { clearSelection(); return; }
      clearSelection(); selected = c; c.classList.add("selected");
    }
    function clearMark(c) {
      c.classList.remove("correct", "wrong");
      c.querySelector(".mark").textContent = "";
    }
    function place(card, drop) {
      // H4840 — first placement of this card is its "time to answer".
      var st = itemStats[card.dataset.qid];
      if (st && st.ms === null) {
        st.ms = Math.max(0, Math.round(now() - roundStart));
      }
      drop.appendChild(card);
      clearMark(card); clearSelection(); updateScore();
    }
    function placedCount() {
      return zones.reduce(function (n, z) { return n + z.querySelectorAll(".card").length; }, 0);
    }
    function updateScore() {
      score.textContent = "Размещено " + placedCount() + " / " + totalItems;
      feedback.classList.remove("show");
      zones.concat([tray]).forEach(function (z) {
        Array.prototype.forEach.call(z.querySelectorAll(".card"), clearMark);
      });
    }

    function makeCard(item, correctGi, qid) {
      var c = el("div", "card");
      c.setAttribute("draggable", "true");
      c.setAttribute("tabindex", "0");
      c.setAttribute("role", "button");
      c.dataset.gi = correctGi;
      c.dataset.qid = qid;
      var label = (item.text || "") + (item.hint ? (" — " + item.hint) : "");
      c.setAttribute("aria-label", label);
      var html = '<span class="word">' + esc(item.text) + '</span>';
      if (item.hint) html += '<span class="hint">' + esc(item.hint) + '</span>';
      html += '<span class="mark" aria-hidden="true"></span>';
      c.innerHTML = html;
      c.addEventListener("dragstart", function (e) {
        c.classList.add("dragging");
        e.dataTransfer.setData("text/plain", "");
        e.dataTransfer.effectAllowed = "move";
        dragged = c;
      });
      c.addEventListener("dragend", function () { c.classList.remove("dragging"); dragged = null; });
      c.addEventListener("click", function (ev) { ev.stopPropagation(); selectCard(c); });
      c.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); selectCard(c); }
      });
      return c;
    }

    var dragged = null;

    zones.concat([tray]).forEach(function (zone) {
      var bucket = zone.parentElement.classList.contains("bucket") ? zone.parentElement : null;
      zone.addEventListener("dragover", function (e) {
        e.preventDefault(); e.dataTransfer.dropEffect = "move";
        if (bucket) bucket.classList.add("over");
      });
      zone.addEventListener("dragleave", function () { if (bucket) bucket.classList.remove("over"); });
      zone.addEventListener("drop", function (e) {
        e.preventDefault(); if (bucket) bucket.classList.remove("over");
        if (dragged) place(dragged, zone);
      });
    });
    // tap-to-place onto bucket chrome
    board.querySelectorAll(".bucket").forEach(function (b) {
      b.addEventListener("click", function (e) {
        if (selected && !e.target.closest(".card")) place(selected, b.querySelector(".drop"));
      });
    });
    trayWrap.addEventListener("click", function (e) {
      if (selected && !e.target.closest(".card")) place(selected, tray);
    });

    function check() {
      var allPlaced = placedCount() === totalItems;
      var correct = 0;
      zones.forEach(function (zone) {
        var gi = zone.dataset.gi;
        Array.prototype.forEach.call(zone.querySelectorAll(".card"), function (card) {
          clearMark(card);
          if (card.dataset.gi === gi) {
            card.classList.add("correct");
            card.querySelector(".mark").textContent = "✓";
            correct++;
          } else {
            card.classList.add("wrong");
            card.querySelector(".mark").textContent = "✕";
            // H4840 — a check that scored this card wrong is a difficulty signal.
            var st = itemStats[card.dataset.qid];
            if (st) st.wrong++;
          }
        });
      });
      score.textContent = "Верно " + correct + " / " + totalItems;
      var solved = allPlaced && correct === totalItems;
      feedback.classList.toggle("show", solved);
      if (solved) {
        timerStop();          // H4840
        exposeRoundResult();
      }
    }

    // H4840 — per-card results for telemetry.js (one item_result per round):
    // l = card text, r = the correct group label, ms = time to first placement,
    // wrong = checks that scored it misplaced.
    function exposeRoundResult() {
      var items = [];
      Array.prototype.forEach.call(container.querySelectorAll(".card"), function (c) {
        var st = itemStats[c.dataset.qid] || {};
        var w = c.querySelector(".word");
        items.push({
          l: w ? w.textContent : "",
          r: st.label || "",
          ms: (typeof st.ms === "number") ? st.ms : 0,
          wrong: st.wrong || 0
        });
      });
      window.SGX_ROUND_RESULT = {
        hints: container.classList.contains("show-hints") ? 1 : 0,
        items: items
      };
    }

    function build() {
      clearSelection();
      feedback.classList.remove("show");
      tray.innerHTML = "";
      zones.forEach(function (z) { z.innerHTML = ""; });

      var deck = [];
      groups.forEach(function (g, gi) {
        var items = g.items;
        if (perRound) items = shuffleArr(items).slice(0, perRound);
        items.forEach(function (it) { deck.push({ item: it, gi: gi }); });
      });
      totalItems = deck.length;
      roundStart = now();     // H4840 — fresh per-round timing
      itemStats = {};
      timerReset();
      (doShuffle ? shuffleArr(deck) : deck).forEach(function (d, i) {
        var qid = "q" + i;
        itemStats[qid] = {
          label: (groups[d.gi] && groups[d.gi].label) || ("Группа " + (d.gi + 1)),
          ms: null,
          wrong: 0
        };
        tray.appendChild(makeCard(d.item, d.gi, qid));
      });
      updateScore();
    }

    hintBtn.addEventListener("click", function () {
      var on = container.classList.toggle("show-hints");
      hintBtn.setAttribute("aria-pressed", String(on));
      hintBtn.textContent = on ? "Скрыть подсказки" : "Показать подсказки";
    });
    checkBtn.addEventListener("click", check);
    resetBtn.addEventListener("click", build);

    build();
    return { check: check, reset: build, element: container };
  }

  global.SortExercise = { mount: mount };
})(window);
