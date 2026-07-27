/* ============================================================
   Match-the-pairs exercise engine.
   Renders a two-column "connect each item on the left to its
   partner on the right" drill from a plain config object.
   Sibling to sort/engine.js — same theme, a11y, offline, no deps.

   Usage:
     MatchExercise.mount(containerEl, config);
   where config = {
     task:      "Найдите для каждого корня форму 3 л. ед. ч.", // optional instruction line
     feedback:  "Задание решено верно.",                       // shown when all pairs correct
     leftTitle: "Формы",   // optional column headings
     rightTitle:"Корни",
     perRound:  8,          // optional: sample N pairs per round (omit = all)
     shuffle:   true,        // optional (default true)
     pairs: [
       { left:{text:"गच्छति", hint:"идёт"}, right:{text:"gam-"} },  // hint optional either side
       ...   // 2+ pairs
     ]
   }
   Correct pairing is positional: pairs[i].left ↔ pairs[i].right.
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

  // Well-spread hues for up to a dozen simultaneous links; readable on
  // both light and dark grounds at the fixed sat/lightness below.
  var HUES = [16, 145, 262, 45, 200, 328, 96, 238, 4, 174, 300, 68];
  function hueVal(i) { return (i < HUES.length) ? HUES[i] : (i * 47) % 360; }

  function mount(container, cfg) {
    cfg = cfg || {};
    var allPairs = (cfg.pairs || []).filter(function (p) {
      return p && p.left && p.right && p.left.text != null && p.right.text != null;
    });
    if (allPairs.length < 2) {
      container.innerHTML = '<p style="color:#b23b2e;font-family:sans-serif">' +
        'Нужны минимум две пары, у каждой левый и правый элемент.</p>';
      return null;
    }
    var doShuffle = cfg.shuffle !== false;
    var perRound = (typeof cfg.perRound === "number" && cfg.perRound > 0) ? cfg.perRound : 0;

    container.classList.add("matchx");
    container.innerHTML = "";

    if (cfg.task) container.appendChild(el("p", "task", esc(cfg.task)));

    // ---- toolbar ----
    var toolbar = el("div", "toolbar");
    var hintBtn  = el("button", "ghost", "Показать подсказки");
    hintBtn.setAttribute("aria-pressed", "false");
    var checkBtn = el("button", "primary", "Проверить");
    var resetBtn = el("button", "ghost", "Заново");
    var spacer   = el("span", "spacer");
    var score    = el("span", "score");
    var hasHints = allPairs.some(function (p) {
      return (p.left && p.left.hint) || (p.right && p.right.hint);
    });
    if (hasHints) toolbar.appendChild(hintBtn);
    toolbar.appendChild(checkBtn);
    toolbar.appendChild(resetBtn);
    toolbar.appendChild(spacer);
    toolbar.appendChild(score);
    container.appendChild(toolbar);

    // ---- board: two columns ----
    var board = el("div", "board");
    function makeColumn(side, title) {
      var col = el("section", "col side-" + side);
      col.appendChild(el("p", "col-title", esc(title)));
      var drop = el("div", "col-drop");
      drop.dataset.side = side;
      col.appendChild(drop);
      board.appendChild(col);
      return drop;
    }
    var dropL = makeColumn("l", cfg.leftTitle || "Слева");
    var dropR = makeColumn("r", cfg.rightTitle || "Справа");
    container.appendChild(board);

    // ---- feedback ----
    var feedback = el("div", "feedback",
      '<span class="om">🕉</span><span>' +
      esc(cfg.feedback || "Задание решено верно.") + '</span>');
    container.appendChild(feedback);

    // ---- state ----
    var selected = null;       // currently selected card element (either column)
    var dragged = null;
    var usedHues = {};         // hue-slot index -> true
    var linkCtr = 0;
    var totalPairs = 0;

    function nextHueSlot() {
      var i;
      for (i = 0; i < HUES.length; i++) { if (!usedHues[i]) { usedHues[i] = true; return i; } }
      // beyond the palette: keep handing out fresh indices
      for (i = HUES.length; ; i++) { if (!usedHues[i]) { usedHues[i] = true; return i; } }
    }

    function clearSelection() {
      if (selected) selected.classList.remove("selected");
      selected = null;
    }
    function selectCard(card) {
      if (selected === card) { clearSelection(); return; }
      clearSelection();
      selected = card;
      card.classList.add("selected");
    }
    function clearMark(card) {
      card.classList.remove("correct", "wrong");
      card.querySelector(".mark").textContent = "";
    }
    function clearAllMarks() {
      Array.prototype.forEach.call(board.querySelectorAll(".card"), clearMark);
    }

    function link(a, b) {
      // a and b are unlinked cards on opposite columns
      var id = ++linkCtr;
      var slot = nextHueSlot();
      var h = hueVal(slot);
      [a, b].forEach(function (c) {
        c.dataset.linkId = String(id);
        c.dataset.hueSlot = String(slot);
        c.style.setProperty("--link", "hsl(" + h + " 58% 47%)");
        c.classList.add("linked");
        c.querySelector(".badge").textContent = String(slot + 1);
      });
      clearSelection();
      updateScore();
    }
    function unlink(card) {
      var id = card.dataset.linkId;
      if (!id) return;
      var slot = card.dataset.hueSlot;
      if (slot != null) delete usedHues[slot];
      Array.prototype.forEach.call(board.querySelectorAll('[data-link-id="' + id + '"]'), function (c) {
        c.classList.remove("linked", "correct", "wrong");
        c.style.removeProperty("--link");
        delete c.dataset.linkId;
        delete c.dataset.hueSlot;
        c.querySelector(".badge").textContent = "";
        c.querySelector(".mark").textContent = "";
      });
      updateScore();
    }

    function activate(card) {
      // primary interaction: click / Enter / Space on a card
      if (card.dataset.linkId) { unlink(card); clearSelection(); return; }
      if (!selected) { selectCard(card); return; }
      if (selected === card) { clearSelection(); return; }
      if (selected.dataset.side === card.dataset.side) { selectCard(card); return; }
      // opposite columns, both unlinked → connect
      link(selected, card);
    }

    function makeCard(item, pid, side) {
      var c = el("div", "card");
      c.setAttribute("draggable", "true");
      c.setAttribute("tabindex", "0");
      c.setAttribute("role", "button");
      c.dataset.pid = String(pid);
      c.dataset.side = side;
      var label = (item.text || "") + (item.hint ? (" — " + item.hint) : "");
      c.setAttribute("aria-label", label);
      var html = '<span class="badge" aria-hidden="true"></span>';
      html += '<span class="word">' + esc(item.text) + '</span>';
      if (item.hint) html += '<span class="hint">' + esc(item.hint) + '</span>';
      html += '<span class="mark" aria-hidden="true"></span>';
      c.innerHTML = html;
      c.addEventListener("click", function (ev) { ev.stopPropagation(); activate(c); });
      c.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); activate(c); }
      });
      c.addEventListener("dragstart", function (e) {
        c.classList.add("dragging");
        e.dataTransfer.setData("text/plain", "");
        e.dataTransfer.effectAllowed = "move";
        dragged = c;
      });
      c.addEventListener("dragend", function () { c.classList.remove("dragging"); dragged = null; });
      c.addEventListener("dragover", function (e) {
        if (dragged && dragged.dataset.side !== c.dataset.side) {
          e.preventDefault(); e.dataTransfer.dropEffect = "move"; c.classList.add("over");
        }
      });
      c.addEventListener("dragleave", function () { c.classList.remove("over"); });
      c.addEventListener("drop", function (e) {
        e.preventDefault(); c.classList.remove("over");
        if (!dragged || dragged === c) return;
        if (dragged.dataset.side === c.dataset.side) return;
        // re-link: drop onto/ from a linked card replaces the old connection(s)
        if (dragged.dataset.linkId) unlink(dragged);
        if (c.dataset.linkId) unlink(c);
        link(dragged, c);
      });
      return c;
    }

    function linkedCount() {
      return board.querySelectorAll('.side-l .card.linked').length;
    }
    function updateScore() {
      score.textContent = "Соединено " + linkedCount() + " / " + totalPairs;
      feedback.classList.remove("show");
      clearAllMarks();
    }

    function check() {
      var leftCards = board.querySelectorAll('.side-l .card');
      var allLinked = true, correct = 0;
      Array.prototype.forEach.call(leftCards, function (lc) {
        clearMark(lc);
        var id = lc.dataset.linkId;
        if (!id) { allLinked = false; return; }
        var partner = board.querySelector('.side-r .card[data-link-id="' + id + '"]');
        var ok = partner && partner.dataset.pid === lc.dataset.pid;
        [lc, partner].forEach(function (c) {
          if (!c) return;
          clearMark(c);
          c.classList.add(ok ? "correct" : "wrong");
          c.querySelector(".mark").textContent = ok ? "✓" : "✕";
        });
        if (ok) correct++;
      });
      score.textContent = "Верно " + correct + " / " + totalPairs;
      feedback.classList.toggle("show", allLinked && correct === totalPairs);
    }

    function build() {
      clearSelection();
      feedback.classList.remove("show");
      usedHues = {}; linkCtr = 0;
      dropL.innerHTML = ""; dropR.innerHTML = "";

      var pairs = allPairs;
      if (perRound && perRound < allPairs.length) {
        pairs = shuffleArr(allPairs).slice(0, perRound);
      }
      totalPairs = pairs.length;

      var lefts  = pairs.map(function (p, i) { return { item: p.left,  pid: i }; });
      var rights = pairs.map(function (p, i) { return { item: p.right, pid: i }; });
      (doShuffle ? shuffleArr(lefts)  : lefts ).forEach(function (d) { dropL.appendChild(makeCard(d.item, d.pid, "l")); });
      (doShuffle ? shuffleArr(rights) : rights).forEach(function (d) { dropR.appendChild(makeCard(d.item, d.pid, "r")); });
      updateScore();
    }

    // clicking empty column space clears the current selection
    board.addEventListener("click", function (e) {
      if (!e.target.closest(".card")) clearSelection();
    });

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

  global.MatchExercise = { mount: mount };
})(window);
