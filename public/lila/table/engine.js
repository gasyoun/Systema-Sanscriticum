/* ============================================================
   Table / grid-fill exercise engine.
   Headers stay fixed; remaining cells start empty. Learner places
   shuffled answer cards into cells (click card then cell, or drag).
   LearningApps tool 270 remake. Sibling to sort/match/cloze.
   Usage:
     TableExercise.mount(containerEl, config);
   where config = {
     task, feedback,
     fixedRows: 1,   // top N rows are headers (default 1)
     fixedCols: 1,   // left N cols are headers (default 1)
     cells: [ ["h","h2",...], ["rowh","answer",...], ... ],
     shuffle: true
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
    var cells = cfg.cells || [];
    if (!cells.length || !cells[0] || !cells[0].length) {
      container.innerHTML = '<p style="color:#b23b2e;font-family:sans-serif">Нужна непустая таблица cells[][].</p>';
      return null;
    }
    var nR = cells.length;
    var nC = cells[0].length;
    var fixedRows = (typeof cfg.fixedRows === "number") ? cfg.fixedRows : 1;
    var fixedCols = (typeof cfg.fixedCols === "number") ? cfg.fixedCols : 1;
    var doShuffle = cfg.shuffle !== false;

    container.classList.add("tablex");
    container.innerHTML = "";
    if (cfg.task) container.appendChild(el("p", "task", esc(cfg.task)));

    var toolbar = el("div", "toolbar");
    var checkBtn = el("button", "primary", "Проверить");
    var resetBtn = el("button", "ghost", "Заново");
    var spacer = el("span", "spacer");
    var score = el("span", "score");
    toolbar.appendChild(checkBtn);
    toolbar.appendChild(resetBtn);
    toolbar.appendChild(spacer);
    toolbar.appendChild(score);
    container.appendChild(toolbar);

    var pool = el("div", "pool");
    pool.setAttribute("aria-label", "Карточки для заполнения");
    container.appendChild(pool);

    var boardWrap = el("div", "board-wrap");
    var table = document.createElement("table");
    table.className = "grid";
    boardWrap.appendChild(table);
    container.appendChild(boardWrap);

    var feedback = el("div", "feedback",
      '<span class="om">🕉</span><span>' +
      esc(cfg.feedback || "Задание выполнено верно.") + "</span>");
    container.appendChild(feedback);

    // fillable slots: list of {r,c,answer,td,placed}
    var slots = [];
    var selectedCard = null;

    function isFixed(r, c) {
      return r < fixedRows || c < fixedCols;
    }

    function clearMarks() {
      slots.forEach(function (s) {
        s.td.classList.remove("correct", "wrong", "target");
      });
      feedback.classList.remove("show");
    }

    function placedCount() {
      return slots.reduce(function (n, s) { return n + (s.placed ? 1 : 0); }, 0);
    }
    function updateScore() {
      score.textContent = "Заполнено " + placedCount() + " / " + slots.length;
    }

    function deselectCard() {
      if (selectedCard) selectedCard.classList.remove("selected");
      selectedCard = null;
      slots.forEach(function (s) { s.td.classList.remove("target"); });
    }

    function placeInto(slot, cardEl) {
      if (!cardEl || !slot) return;
      // if slot occupied, return old card to pool
      if (slot.placed) {
        var old = slot.td.querySelector(".card");
        if (old) {
          old.classList.remove("placed");
          pool.appendChild(old);
        }
      }
      // if card was in another slot, clear that slot
      slots.forEach(function (s) {
        if (s.placed === cardEl) {
          s.placed = null;
          s.td.innerHTML = "";
          s.td.classList.add("empty");
        }
      });
      slot.td.innerHTML = "";
      slot.td.classList.remove("empty");
      cardEl.classList.add("placed");
      cardEl.classList.remove("selected");
      slot.td.appendChild(cardEl);
      slot.placed = cardEl;
      deselectCard();
      clearMarks();
      updateScore();
    }

    function wireCard(card) {
      card.setAttribute("draggable", "true");
      card.tabIndex = 0;
      card.addEventListener("click", function () {
        clearMarks();
        if (selectedCard === card) { deselectCard(); return; }
        deselectCard();
        selectedCard = card;
        card.classList.add("selected");
        slots.forEach(function (s) {
          if (!s.placed) s.td.classList.add("target");
        });
      });
      card.addEventListener("keydown", function (ev) {
        if (ev.key === "Enter" || ev.key === " ") {
          ev.preventDefault();
          card.click();
        }
      });
      card.addEventListener("dragstart", function (ev) {
        ev.dataTransfer.setData("text/plain", card.getAttribute("data-id") || "");
        card.classList.add("dragging");
        selectedCard = card;
      });
      card.addEventListener("dragend", function () {
        card.classList.remove("dragging");
      });
    }

    function wireSlot(slot) {
      var td = slot.td;
      td.addEventListener("click", function () {
        if (selectedCard) placeInto(slot, selectedCard);
      });
      td.addEventListener("dragover", function (ev) {
        ev.preventDefault();
        td.classList.add("drag-over");
      });
      td.addEventListener("dragleave", function () {
        td.classList.remove("drag-over");
      });
      td.addEventListener("drop", function (ev) {
        ev.preventDefault();
        td.classList.remove("drag-over");
        var id = ev.dataTransfer.getData("text/plain");
        var card = pool.querySelector('.card[data-id="' + id + '"]') ||
                   container.querySelector('.card[data-id="' + id + '"]');
        if (card) placeInto(slot, card);
      });
    }

    function build() {
      slots = [];
      selectedCard = null;
      pool.innerHTML = "";
      table.innerHTML = "";
      feedback.classList.remove("show");

      var answers = [];
      for (var r = 0; r < nR; r++) {
        var tr = document.createElement("tr");
        for (var c = 0; c < nC; c++) {
          var val = String(cells[r][c] == null ? "" : cells[r][c]).trim();
          var td = document.createElement("td");
          if (isFixed(r, c)) {
            td.className = "fixed" + (r < fixedRows ? " head-row" : "") + (c < fixedCols ? " head-col" : "");
            td.innerHTML = '<span class="fixed-text">' + esc(val) + "</span>";
          } else {
            td.className = "empty fillable";
            td.setAttribute("aria-label", "Ячейка " + (r + 1) + "," + (c + 1));
            var slot = { r: r, c: c, answer: val, td: td, placed: null };
            slots.push(slot);
            wireSlot(slot);
            if (val) answers.push(val);
          }
          tr.appendChild(td);
        }
        table.appendChild(tr);
      }

      var order = doShuffle ? shuffleArr(answers) : answers.slice();
      order.forEach(function (ans, i) {
        var card = el("button", "card", esc(ans));
        card.type = "button";
        card.setAttribute("data-id", "c" + i);
        card.setAttribute("data-value", ans);
        wireCard(card);
        pool.appendChild(card);
      });
      updateScore();
    }

    function check() {
      var correct = 0;
      var allFilled = true;
      slots.forEach(function (s) {
        s.td.classList.remove("correct", "wrong", "target");
        if (!s.placed) { allFilled = false; return; }
        var val = s.placed.getAttribute("data-value") || "";
        if (val === s.answer) {
          s.td.classList.add("correct");
          correct++;
        } else {
          s.td.classList.add("wrong");
        }
      });
      score.textContent = "Верно " + correct + " / " + slots.length;
      feedback.classList.toggle("show", allFilled && correct === slots.length);
    }

    checkBtn.addEventListener("click", check);
    resetBtn.addEventListener("click", build);
    build();
    return { check: check, reset: build, element: container };
  }

  global.TableExercise = { mount: mount };
})(window);
