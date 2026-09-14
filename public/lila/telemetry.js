/* ============================================================
   Free-games funnel telemetry (H1360).

   Anonymous, first-party, no third-party trackers (privacy
   contract R20). Sends four funnel signals to /api/games/event:
     start          — a drill page was opened (a "play")
     complete       — a round was solved (reuses gate.js's own
                      completion signal: `.feedback.show`)
     gate_shown     — the register wall appeared (`.sgx-gate`)
     gate_cta_click — the wall's «Начать бесплатно» was clicked

   H4692/H4791 adds one optional detail event:
     item_result    — per-pair {l, r, ms, wrong} rows from the match
                      engine's window.SGX_ROUND_RESULT, sent once per
                      SOLVED ROUND (a «Заново» replay sends again, H4791);
                      absent -> no request, no behavior change (same
                      opt-in pattern as SGX_SEEN_ITEMS).

   This script is a passive OBSERVER of the DOM that gate.js
   produces — it never touches gate.js or its localStorage, so the
   gate's own behaviour stays byte-for-byte unchanged. Load order
   relative to gate.js does not matter.

   The only identifier is a short random `anon_id` kept in
   localStorage — no PII, no server login sent from here (the
   server stamps the authenticated flag from the web session).

   Include from any drill page (served at the site root in prod),
   declaring which drill/band this page is:
     <script src="/lila/telemetry.js"
             data-drill="ligatures" data-band="top-50" defer></script>
   ============================================================ */
(function () {
  "use strict";

  var ENDPOINT = "/api/games/event";
  var ANON_KEY = "sgx_anon_v1";

  // Read this script tag's data-drill / data-band. document.currentScript
  // is null under `defer`, so find the tag by its src.
  var self = document.currentScript ||
    document.querySelector('script[src*="telemetry.js"]');
  var DRILL = (self && self.getAttribute("data-drill")) || "unknown";
  var BAND = (self && self.getAttribute("data-band")) || null;

  function anonId() {
    try {
      var v = localStorage.getItem(ANON_KEY);
      if (v) return v;
      var bytes = new Uint8Array(8);
      (window.crypto || window.msCrypto).getRandomValues(bytes);
      v = "";
      for (var i = 0; i < bytes.length; i++) {
        v += (bytes[i] + 0x100).toString(16).slice(1);
      }
      localStorage.setItem(ANON_KEY, v);
      return v;
    } catch (e) {
      return null; // private mode / crypto unavailable → anonymous, no id
    }
  }

  function send(event, itemPayload) {
    var payload = { anon_id: anonId(), drill: DRILL, band: BAND, event: event };
    if (itemPayload) payload.payload = itemPayload;
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: "application/json" });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
      }
      // Fallback for browsers without sendBeacon (or a queue-full refusal).
      fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: body,
        keepalive: true,
        credentials: "same-origin"
      }).catch(function () {});
    } catch (e) {
      // Telemetry must never break a drill page.
    }
  }

  // One "play" per page load.
  send("start");

  // H1680 — opt-in only: a page may expose `window.SGX_SEEN_ITEMS =
  // [{iast, ru}, ...]` (the items it actually shows this load) BEFORE this
  // deferred script runs; if present, one `item_seen` is sent alongside
  // "start", feeding the onboarding-from-games SRS import. Absent (the
  // default for every pack that hasn't opted in) -> no new request, no
  // behavior change.
  (function () {
    var items = window.SGX_SEEN_ITEMS;
    if (!Array.isArray(items) || items.length === 0) return;
    var clean = items.slice(0, 20).map(function (it) {
      return { iast: String((it && it.iast) || ""), ru: String((it && it.ru) || "") };
    }).filter(function (it) { return it.iast !== ""; });
    if (clean.length > 0) send("item_seen", { items: clean });
  })();

  var sentComplete = false;
  var sentGate = false;
  var lastResultSent = null; // H4791 — identity of the last item_result sent

  function clampInt(v, lo, hi) {
    v = Math.round(Number(v));
    if (!isFinite(v)) v = 0;
    return Math.max(lo, Math.min(hi, v));
  }

  // H4692/H4791 — forward the match engine's per-pair round results as one
  // `item_result` event PER SOLVED ROUND (the engine builds a fresh
  // SGX_ROUND_RESULT object each time, so a «Заново» replay sends again).
  // Client-side caps mirror the server's: 40 items, ms 0..3 600 000,
  // wrong 0..50; rows missing either side are dropped.
  function sendItemResult(res) {
    if (!res || !Array.isArray(res.items) || res.items.length === 0) return;
    var items = res.items.slice(0, 40).map(function (it) {
      return {
        l: String((it && it.l) || ""),
        r: String((it && it.r) || ""),
        ms: clampInt(it && it.ms, 0, 3600000),
        wrong: clampInt(it && it.wrong, 0, 50)
      };
    }).filter(function (it) { return it.l !== "" && it.r !== ""; });
    if (items.length > 0) {
      send("item_result", { hints: res.hints ? 1 : 0, items: items });
    }
  }

  // Reuse the exact completion signal gate.js watches (`.feedback.show`),
  // and catch the register wall gate.js injects (`.sgx-gate`).
  function scan() {
    if (!sentComplete && document.querySelector(".feedback.show")) {
      sentComplete = true;
      send("complete"); // funnel stays one per page load (H1360)
    }
    // H4791 — per round, not per page load: a new SGX_ROUND_RESULT object
    // means another solved round (replays after «Заново» included).
    var res = window.SGX_ROUND_RESULT;
    if (document.querySelector(".feedback.show") && res && res !== lastResultSent) {
      lastResultSent = res;
      sendItemResult(res);
    }
    if (!sentGate && document.querySelector(".sgx-gate")) {
      sentGate = true;
      send("gate_shown");
    }
  }

  try {
    var obs = new MutationObserver(scan);
    obs.observe(document.documentElement, {
      subtree: true, childList: true, attributes: true, attributeFilter: ["class"]
    });
  } catch (e) {}
  scan(); // in case the state is already present at load

  // CTA click on the wall's primary button.
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest(".sgx-gate-primary");
    if (a) send("gate_cta_click");
  }, true);
})();
