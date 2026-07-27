/* ============================================================
   Free-games funnel telemetry (H1360).

   Anonymous, first-party, no third-party trackers (privacy
   contract R20). Sends four funnel signals to /api/games/event:
     start          — a drill page was opened (a "play")
     complete       — a round was solved (reuses gate.js's own
                      completion signal: `.feedback.show`)
     gate_shown     — the register wall appeared (`.sgx-gate`)
     gate_cta_click — the wall's «Начать бесплатно» was clicked

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

  function send(event) {
    var payload = { anon_id: anonId(), drill: DRILL, band: BAND, event: event };
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

  var sentComplete = false;
  var sentGate = false;

  // Reuse the exact completion signal gate.js watches (`.feedback.show`),
  // and catch the register wall gate.js injects (`.sgx-gate`).
  function scan() {
    if (!sentComplete && document.querySelector(".feedback.show")) {
      sentComplete = true;
      send("complete");
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
