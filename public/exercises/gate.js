/* ============================================================
   Free-games funnel gate.
   Anonymous visitors get ONE free completed game; the next play
   attempt shows a soft "register a free cabinet to continue" wall.
   Logged-in students are never gated.

   Soft gate: state lives in localStorage — a funnel NUDGE, not a
   hard paywall (clearable, per-device). That is intentional: the
   goal is conversion, not DRM. Auth is read from /api/games/auth
   (web session); if that call fails the visitor is treated as
   anonymous, so the gate still works on a bare static host.

   Include from any drill page (served at the site root in prod):
     <script src="/exercises/gate.js" defer></script>
   ============================================================ */
(function () {
  "use strict";

  var KEY = "sgx_played_v1";
  var AUTH_URL = "/api/games/auth";
  var REGISTER_URL = "/online/konsultaciya"; // free 3-day diagnostic marathon = a free cabinet
  var LOGIN_URL = "/login";

  function played() { try { return localStorage.getItem(KEY) === "1"; } catch (e) { return false; } }
  function markPlayed() { try { localStorage.setItem(KEY, "1"); } catch (e) {} }

  function injectStyles() {
    if (document.getElementById("sgx-gate-style")) return;
    var s = document.createElement("style");
    s.id = "sgx-gate-style";
    s.textContent = [
      ".sgx-gate{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;",
      "padding:20px;background:rgba(28,23,18,.62);backdrop-filter:blur(4px);",
      "font-family:'Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;}",
      ".sgx-gate-card{max-width:400px;width:100%;background:#faf5e8;color:#2a2118;border:1px solid #d9cba8;",
      "border-radius:14px;padding:30px 26px 26px;text-align:center;box-shadow:0 12px 40px rgba(60,45,20,.32);}",
      ".sgx-gate-om{font-family:'Noto Sans Devanagari','Nirmala UI',serif;font-size:34px;color:#a8451f;margin-bottom:6px;}",
      ".sgx-gate-card h2{font-family:'Iowan Old Style',Palatino,Georgia,serif;font-size:23px;margin:0 0 10px;font-weight:600;}",
      ".sgx-gate-card p{font-size:14.5px;line-height:1.55;color:#6b5d47;margin:0 0 20px;}",
      ".sgx-gate-primary{display:block;background:#a8451f;color:#fff;font-weight:700;font-size:15px;",
      "text-decoration:none;padding:13px 18px;border-radius:999px;transition:background .15s;}",
      ".sgx-gate-primary:hover{background:#8f3917;}",
      ".sgx-gate-secondary{display:inline-block;margin-top:14px;color:#a8451f;font-size:13.5px;font-weight:600;text-decoration:none;}",
      ".sgx-gate-secondary:hover{text-decoration:underline;}",
      "@media (prefers-color-scheme:dark){",
      ".sgx-gate-card{background:#26201a;color:#efe6d4;border-color:#423628;}",
      ".sgx-gate-om{color:#e07a4e;}.sgx-gate-card p{color:#b3a488;}",
      ".sgx-gate-primary{background:#e07a4e;}.sgx-gate-primary:hover{background:#c9663a;}",
      ".sgx-gate-secondary{color:#e07a4e;}}"
    ].join("");
    document.head.appendChild(s);
  }

  function showWall() {
    if (document.querySelector(".sgx-gate")) return;
    injectStyles();
    var ov = document.createElement("div");
    ov.className = "sgx-gate";
    ov.setAttribute("role", "dialog");
    ov.setAttribute("aria-modal", "true");
    ov.innerHTML =
      '<div class="sgx-gate-card">' +
        '<div class="sgx-gate-om" aria-hidden="true">🕉</div>' +
        '<h2>Понравилось?</h2>' +
        '<p>Первая игра — бесплатно. ' +
        'Чтобы продолжить и открыть все тренажёры, ' +
        'заведите бесплатный кабинет ученика.</p>' +
        '<a class="sgx-gate-primary" href="' + REGISTER_URL + '">Начать бесплатно</a>' +
        '<a class="sgx-gate-secondary" href="' + LOGIN_URL + '">Уже занимаюсь — войти</a>' +
      '</div>';
    document.body.appendChild(ov);
    document.body.style.overflow = "hidden";
  }

  // After the one free win, intercept any further play action → wall.
  function armReplayGate() {
    document.addEventListener("click", function (e) {
      var b = e.target.closest && e.target.closest("button");
      if (!b) return;
      var t = (b.textContent || "").trim();
      if (t === "Заново" || t === "Проверить") {
        e.preventDefault();
        e.stopPropagation();
        showWall();
      }
    }, true);
  }

  // Let the visitor finish exactly one game, then arm the gate.
  function watchFirstCompletion() {
    var obs = new MutationObserver(function () {
      if (document.querySelector(".feedback.show")) {
        markPlayed();
        obs.disconnect();
        armReplayGate();
      }
    });
    obs.observe(document.body, {
      subtree: true, attributes: true, attributeFilter: ["class"], childList: true
    });
  }

  function start(authed) {
    if (authed) return;                    // logged-in students: never gated
    if (played()) { showWall(); return; }  // came back for a second game → wall
    watchFirstCompletion();                // first visit: one free game, then arm
  }

  fetch(AUTH_URL, { headers: { "Accept": "application/json" }, credentials: "same-origin" })
    .then(function (r) { return r.ok ? r.json() : { authenticated: false }; })
    .catch(function () { return { authenticated: false }; })
    .then(function (d) { start(!!(d && d.authenticated)); });
})();
