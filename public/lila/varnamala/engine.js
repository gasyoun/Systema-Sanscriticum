/* ============================================================
   Varnamālā pilot engine (H2436) — CSS morph proxy.
   Mount: VarnamalaPilot.mount(el, { locale: "ru"|"en" })
   Data:  window.VARNAMALA_PILOT from data.js
   Later: swap glyph canvas for Rive runtime when .riv files exist.
   ============================================================ */
(function (global) {
  "use strict";

  var EMOJI = {
    a: "🐴", aa: "🌌", i: "🌙", u: "🐪", ka: "🪷",
    ga: "🐘", ca: "🛞", ta: "🌳", na: "🌊", ma: "🦚"
  };

  function t(locale, ru, en) {
    return locale === "en" ? en : ru;
  }

  function speak(text) {
    try {
      if (!global.speechSynthesis || !text) return;
      global.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = "hi-IN";
      u.rate = 0.85;
      global.speechSynthesis.speak(u);
    } catch (e) { /* optional */ }
  }

  function mount(root, opts) {
    opts = opts || {};
    var locale = opts.locale || "ru";
    var data = global.VARNAMALA_PILOT;
    if (!data || !data.aksaras || !data.aksaras.length) {
      root.innerHTML = "<p>VARNAMALA_PILOT data missing.</p>";
      return null;
    }

    var state = {
      idx: 0,
      stage: 0, // 0 idle, 1-3 morphs
      done: {}  // id -> true when stage 3 reached once
    };

    root.classList.add("vm-root");
    root.innerHTML =
      '<p class="vm-hint" data-role="hint"></p>' +
      '<div class="vm-strip" data-role="strip" role="tablist" aria-label="akṣara"></div>' +
      '<div class="vm-stage">' +
      '  <div class="vm-canvas" data-role="canvas" role="button" tabindex="0" aria-label="morph">' +
      '    <div class="vm-burst" aria-hidden="true"></div>' +
      '    <div class="vm-glyph is-s0" data-role="glyph"></div>' +
      '    <div class="vm-emoji-proxy" data-role="emoji" aria-hidden="true"></div>' +
      '    <div class="vm-poke-label" data-role="poke-label"></div>' +
      '    <div class="vm-dots" data-role="dots" aria-hidden="true"></div>' +
      "  </div>" +
      '  <aside class="vm-card is-locked" data-role="card">' +
      '    <p class="eyebrow" data-role="card-eye"></p>' +
      '    <p class="sound" data-role="card-sound"></p>' +
      '    <p class="word-deva" data-role="card-word"></p>' +
      '    <p class="meta word-meta" data-role="card-meta"></p>' +
      '    <p class="gloss" data-role="card-gloss"></p>' +
      '    <p class="placeholder" data-role="card-ph"></p>' +
      '    <div class="cta">' +
      '      <button type="button" class="vm-btn primary" data-role="btn-poke"></button>' +
      '      <button type="button" class="vm-btn" data-role="btn-say"></button>' +
      '      <button type="button" class="vm-btn" data-role="btn-next"></button>' +
      "    </div>" +
      "  </aside>" +
      "</div>" +
      '<p class="vm-progress" data-role="progress"></p>' +
      // Same completion signal as sort/match: gate.js + telemetry watch `.feedback.show`.
      '<div class="feedback" data-role="feedback" hidden aria-live="polite"></div>';

    var el = {
      hint: root.querySelector('[data-role="hint"]'),
      strip: root.querySelector('[data-role="strip"]'),
      canvas: root.querySelector('[data-role="canvas"]'),
      glyph: root.querySelector('[data-role="glyph"]'),
      emoji: root.querySelector('[data-role="emoji"]'),
      pokeLabel: root.querySelector('[data-role="poke-label"]'),
      dots: root.querySelector('[data-role="dots"]'),
      card: root.querySelector('[data-role="card"]'),
      cardEye: root.querySelector('[data-role="card-eye"]'),
      cardSound: root.querySelector('[data-role="card-sound"]'),
      cardWord: root.querySelector('[data-role="card-word"]'),
      cardMeta: root.querySelector('[data-role="card-meta"]'),
      cardGloss: root.querySelector('[data-role="card-gloss"]'),
      cardPh: root.querySelector('[data-role="card-ph"]'),
      btnPoke: root.querySelector('[data-role="btn-poke"]'),
      btnSay: root.querySelector('[data-role="btn-say"]'),
      btnNext: root.querySelector('[data-role="btn-next"]'),
      progress: root.querySelector('[data-role="progress"]'),
      feedback: root.querySelector('[data-role="feedback"]')
    };

    el.hint.textContent = t(
      locale,
      "Коснитесь буквы — она изменится. Три касания, и появится слово. Можно ходить по ленте акшар.",
      "Tap the letter — it transforms. Three taps reveal a word. Browse the strip freely."
    );
    el.btnPoke.textContent = t(locale, "Тронуть", "Poke");
    el.btnSay.textContent = t(locale, "Сказать", "Say");
    el.btnNext.textContent = t(locale, "Следующая", "Next");

    // stage dots
    for (var d = 0; d < 4; d++) {
      var dot = document.createElement("span");
      dot.className = "vm-dot";
      dot.dataset.stage = String(d);
      el.dots.appendChild(dot);
    }

    data.aksaras.forEach(function (ak, i) {
      var chip = document.createElement("button");
      chip.type = "button";
      chip.className = "vm-chip";
      chip.textContent = ak.devanagari;
      chip.setAttribute("role", "tab");
      chip.setAttribute("aria-label", ak.iast);
      chip.dataset.idx = String(i);
      chip.addEventListener("click", function () {
        state.idx = i;
        state.stage = 0;
        render();
        emit("item_seen", { id: ak.id, stage: 0 });
      });
      el.strip.appendChild(chip);
    });

    function current() {
      return data.aksaras[state.idx];
    }

    function doneCount() {
      return Object.keys(state.done).length;
    }

    function maybeCompletePlay() {
      var need = data.complete_distinct_for_play || 5;
      if (doneCount() >= need && !state.playRecorded) {
        state.playRecorded = true;
        // Edge-trigger: remove then re-add .show so MutationObserver in gate/telemetry fires.
        el.feedback.hidden = false;
        el.feedback.classList.remove("show");
        el.feedback.textContent = t(
          locale,
          "Пилот: открыто ≥" + need + " акшар — засчитана одна игра.",
          "Pilot: ≥" + need + " akṣaras unlocked — one play counted."
        );
        // force reflow
        void el.feedback.offsetWidth;
        el.feedback.classList.add("show");
      }
    }

    function emit(event, payload) {
      try {
        if (global.dispatchEvent) {
          global.dispatchEvent(new CustomEvent("varnamala:" + event, { detail: payload || {} }));
        }
      } catch (e) {}
    }

    function poke() {
      var ak = current();
      if (state.stage < 3) {
        state.stage += 1;
      } else {
        // cycle soft reset on same letter
        state.stage = 0;
      }
      if (state.stage === 3) {
        state.done[ak.id] = true;
        maybeCompletePlay();
        speak(ak.word.devanagari);
      } else if (state.stage === 1) {
        speak(ak.devanagari);
      }
      emit("item_seen", { id: ak.id, stage: state.stage });
      render();
      el.canvas.classList.add("is-lit");
      setTimeout(function () { el.canvas.classList.remove("is-lit"); }, 420);
    }

    function render() {
      var ak = current();
      var chips = el.strip.querySelectorAll(".vm-chip");
      chips.forEach(function (c, i) {
        c.classList.toggle("is-active", i === state.idx);
        c.classList.toggle("is-done", !!state.done[data.aksaras[i].id]);
      });

      el.glyph.textContent = ak.devanagari;
      el.glyph.className = "vm-glyph is-s" + state.stage;

      var morph = ak.morphs[state.stage - 1];
      if (state.stage === 0) {
        el.pokeLabel.textContent = t(locale, "коснись", "tap me");
      } else if (morph) {
        el.pokeLabel.textContent = t(locale, morph.label_ru, morph.label_en);
      } else {
        el.pokeLabel.textContent = "";
      }

      el.dots.querySelectorAll(".vm-dot").forEach(function (dot) {
        var s = Number(dot.dataset.stage);
        dot.classList.toggle("is-on", s <= state.stage);
      });

      var showWord = state.stage >= 3;
      el.emoji.textContent = EMOJI[ak.id] || "✨";
      el.emoji.classList.toggle("is-show", state.stage >= 2);

      el.card.classList.toggle("is-locked", !showWord);
      el.cardEye.textContent = t(locale, "Акшара · слово", "Akṣara · word");
      el.cardSound.textContent =
        ak.devanagari + " · " + ak.iast + " · " + ak.cyrillic;
      el.cardWord.textContent = ak.word.devanagari;
      el.cardMeta.textContent =
        ak.word.iast + " · " + ak.word.cyrillic;
      el.cardGloss.textContent = t(
        locale,
        ak.word.translation_ru,
        ak.word.translation_en
      );
      el.cardPh.textContent = showWord
        ? t(
            locale,
            "Прокси-анимация (CSS). Позже — Rive artboard «" + (ak.rive || ak.id) + "».",
            "CSS proxy morph. Later — Rive artboard «" + (ak.rive || ak.id) + "»."
          )
        : t(
            locale,
            "Слово откроется после третьего касания.",
            "The word unlocks after the third tap."
          );

      el.progress.textContent = t(
        locale,
        "Открыто акшар: " + doneCount() + " / " + data.aksaras.length +
          " · для «игры» в gate: ≥" + (data.complete_distinct_for_play || 5),
        "Unlocked: " + doneCount() + " / " + data.aksaras.length +
          " · play counts toward gate at ≥" + (data.complete_distinct_for_play || 5)
      );
    }

    el.canvas.addEventListener("click", poke);
    el.canvas.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        poke();
      }
    });
    el.btnPoke.addEventListener("click", poke);
    el.btnSay.addEventListener("click", function () {
      var ak = current();
      speak(state.stage >= 3 ? ak.word.devanagari : ak.devanagari);
    });
    el.btnNext.addEventListener("click", function () {
      state.idx = (state.idx + 1) % data.aksaras.length;
      state.stage = 0;
      render();
    });

    emit("started", { band: data.band, n: data.aksaras.length });
    render();

    return {
      poke: poke,
      setLocale: function (loc) {
        locale = loc || "ru";
        el.hint.textContent = t(
          locale,
          "Коснитесь буквы — она изменится. Три касания, и появится слово. Можно ходить по ленте акшар.",
          "Tap the letter — it transforms. Three taps reveal a word. Browse the strip freely."
        );
        el.btnPoke.textContent = t(locale, "Тронуть", "Poke");
        el.btnSay.textContent = t(locale, "Сказать", "Say");
        el.btnNext.textContent = t(locale, "Следующая", "Next");
        render();
      },
      getState: function () {
        return { idx: state.idx, stage: state.stage, done: Object.assign({}, state.done) };
      }
    };
  }

  global.VarnamalaPilot = { mount: mount };
})(typeof window !== "undefined" ? window : globalThis);
