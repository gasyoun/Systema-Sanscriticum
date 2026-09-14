/* ============================================================
   Cloze (fill-in-the-blank) exercise engine.
   Renders a passage with dropdown blanks from a plain config
   object. The learner picks the right word for each blank.
   No dependencies, no network.

   Usage:
     ClozeExercise.mount(containerEl, config);
   where config = {
     task:     "Выберите нужный глагол.",       // optional instruction line
     feedback: "Отлично, верное решение!",       // shown when every blank is correct
     shuffle:  true,        // optional (default true) — shuffle each blank's options
     segments: [
       "गजः ",                                   // string = literal text
       { options: ["गच्छति","तिष्ठति","लसति"],   // object = a blank
         answer: 0,             // index into options (source lists correct first)
         gloss: "идет" },       // optional Russian hint
       "। नेत्रं ",
       { options: [...], answer: 0, gloss: "дрожит" },
       ...
     ]
   }
   `answer` may be an index (into the ORIGINAL options order) or the
   correct value string itself. Options are shuffled at render, but
   correctness is tracked by value, so the answer always resolves.
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
  // resolve a blank's correct VALUE (string) from answer index-or-value
  function correctValue(blank) {
    var opts = blank.options || [];
    if (typeof blank.answer === "number") return opts[blank.answer];
    if (blank.answer != null) return String(blank.answer);
    return opts[0]; // LearningApps convention: correct is listed first
  }

  function mount(container, cfg) {
    cfg = cfg || {};
    var segments = cfg.segments || [];
    var blanks = segments.filter(function (s) {
      return s && typeof s === "object" && s.options && s.options.length;
    });
    if (!blanks.length) {
      container.innerHTML = '<p style="color:#b23b2e;font-family:sans-serif">' +
        'Нужен хотя бы один пропуск с вариантами ответа.</p>';
      return null;
    }
    var doShuffle = cfg.shuffle !== false;
    var hasGloss = blanks.some(function (b) { return b.gloss; });

    container.classList.add("clozex");
    container.innerHTML = "";

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
    if (hasGloss) toolbar.appendChild(hintBtn);
    toolbar.appendChild(checkBtn);
    toolbar.appendChild(resetBtn);
    toolbar.appendChild(spacer);
    toolbar.appendChild(timerEl);
    toolbar.appendChild(score);
    container.appendChild(toolbar);

    // passage
    var passage = el("div", "passage");
    container.appendChild(passage);

    // feedback
    var feedback = el("div", "feedback",
      '<span class="om">🕉</span><span>' +
      esc(cfg.feedback || "Задание выполнено верно.") + '</span>');
    container.appendChild(feedback);

    // one control record per blank
    var controls = [];

    // H4840 — per-blank difficulty timing + the visible stopwatch.
    var roundStart = 0;
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

    function clearMarks() {
      controls.forEach(function (ct) {
        ct.wrap.classList.remove("correct", "wrong");
        ct.mark.textContent = "";
      });
      feedback.classList.remove("show");
    }
    function answeredCount() {
      return controls.reduce(function (n, ct) {
        return n + (ct.select.value ? 1 : 0);
      }, 0);
    }
    function updateScore() {
      score.textContent = "Заполнено " + answeredCount() + " / " + controls.length;
    }

    function makeBlank(blank, num) {
      var wrap = el("span", "blank");
      var select = document.createElement("select");
      select.className = "cloze-select";
      var lbl = "Пропуск " + num + (blank.gloss ? (" — " + blank.gloss) : "");
      select.setAttribute("aria-label", lbl);

      var ph = document.createElement("option");
      ph.value = ""; ph.textContent = "—"; ph.disabled = true; ph.selected = true;
      select.appendChild(ph);

      var opts = doShuffle ? shuffleArr(blank.options) : blank.options.slice();
      opts.forEach(function (v) {
        var o = document.createElement("option");
        o.value = v; o.textContent = v;
        select.appendChild(o);
      });
      wrap.appendChild(select);

      var mark = el("span", "mark", "");
      mark.setAttribute("aria-hidden", "true");
      wrap.appendChild(mark);

      if (blank.gloss) {
        var g = el("span", "gloss", esc(blank.gloss));
        wrap.appendChild(g);
      }

      var correct = correctValue(blank);
      // H4840 — per-blank record: ms = time from build() to the first choice
      // made in this blank; wrong = checks that scored it incorrectly.
      var ct = {
        wrap: wrap, select: select, mark: mark,
        correct: correct,
        label: blank.gloss || correct,
        ms: null,
        wrong: 0
      };

      select.addEventListener("change", function () {
        if (ct.ms === null) {
          ct.ms = Math.max(0, Math.round(now() - roundStart));
        }
        clearMarks();
        updateScore();
      });

      controls.push(ct);
      return wrap;
    }

    function build() {
      controls = [];
      passage.innerHTML = "";
      feedback.classList.remove("show");
      roundStart = now();   // H4840
      timerReset();
      var blankNum = 0;
      segments.forEach(function (seg) {
        if (seg && typeof seg === "object" && seg.options && seg.options.length) {
          blankNum++;
          passage.appendChild(makeBlank(seg, blankNum));
        } else {
          // literal text — preserve line breaks authored as \n
          var text = String(seg == null ? "" : seg);
          var parts = text.split("\n");
          parts.forEach(function (p, i) {
            if (i > 0) passage.appendChild(document.createElement("br"));
            if (p) passage.appendChild(document.createTextNode(p));
          });
        }
      });
      updateScore();
    }

    function check() {
      var correct = 0;
      var allFilled = true;
      controls.forEach(function (ct) {
        ct.wrap.classList.remove("correct", "wrong");
        var val = ct.select.value;
        if (!val) { allFilled = false; ct.mark.textContent = ""; return; }
        if (val === ct.correct) {
          ct.wrap.classList.add("correct");
          ct.mark.textContent = "✓";
          correct++;
        } else {
          ct.wrap.classList.add("wrong");
          ct.mark.textContent = "✕";
          ct.wrong++;   // H4840 — difficulty signal
        }
      });
      score.textContent = "Верно " + correct + " / " + controls.length;
      var solved = allFilled && correct === controls.length;
      feedback.classList.toggle("show", solved);
      if (solved) {
        timerStop();          // H4840
        exposeRoundResult();
      }
    }

    // H4840 — per-blank results for telemetry.js: l = gloss (or the answer
    // itself when the blank has no gloss), r = the correct word, ms = time to
    // the first choice in that blank, wrong = checks that scored it wrong.
    function exposeRoundResult() {
      var items = controls.map(function (ct) {
        return {
          l: ct.label,
          r: ct.correct,
          ms: (typeof ct.ms === "number") ? ct.ms : 0,
          wrong: ct.wrong || 0
        };
      });
      window.SGX_ROUND_RESULT = {
        hints: container.classList.contains("show-hints") ? 1 : 0,
        items: items
      };
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

  global.ClozeExercise = { mount: mount };
})(window);
