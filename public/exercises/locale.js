/* ============================================================
   RU/EN locale shell for /exercises (H1678, Wave 1).

   Pure client-side string picker + a small toggle widget — no
   routing, no server round-trip. Default is `ru` (this site's
   primary audience); missing EN copy always falls back to RU
   rather than rendering blank.

   Usage in a pack's own <script> config block:
     var I18N = { ru: { task: "...", feedback: "..." },
                  en: { task: "...", feedback: "..." } };
     SortExercise.mount(el, {
       task: SgxLocale.pick(I18N, "task"),
       feedback: SgxLocale.pick(I18N, "feedback"),
       ...
     });
     SgxLocale.renderToggle(document.getElementById("locale-toggle"));
   ============================================================ */
(function (global) {
  "use strict";

  var KEY = "exercises.locale";
  var DEFAULT_LOCALE = "ru";
  var SUPPORTED = ["ru", "en"];

  function get() {
    try {
      var v = localStorage.getItem(KEY);
      return SUPPORTED.indexOf(v) > -1 ? v : DEFAULT_LOCALE;
    } catch (e) {
      return DEFAULT_LOCALE;
    }
  }

  function set(loc) {
    if (SUPPORTED.indexOf(loc) === -1) return;
    try { localStorage.setItem(KEY, loc); } catch (e) {}
  }

  // i18n = { ru: {...}, en: {...} }; never returns undefined/blank — falls
  // back to the ru branch, then to "" if even that key is missing.
  function pick(i18n, key) {
    if (!i18n) return "";
    var loc = get();
    var branch = i18n[loc] || i18n[DEFAULT_LOCALE] || {};
    if (branch[key] != null) return branch[key];
    var ruBranch = i18n[DEFAULT_LOCALE] || {};
    return ruBranch[key] != null ? ruBranch[key] : "";
  }

  function renderToggle(mount) {
    if (!mount) return;
    var loc = get();
    mount.innerHTML = "";
    mount.className = (mount.className ? mount.className + " " : "") + "sgx-locale-toggle";
    SUPPORTED.forEach(function (code) {
      var b = document.createElement("button");
      b.type = "button";
      b.textContent = code.toUpperCase();
      b.className = "sgx-locale-btn" + (code === loc ? " active" : "");
      b.setAttribute("aria-pressed", code === loc ? "true" : "false");
      b.addEventListener("click", function () {
        if (code === get()) return;
        set(code);
        location.reload();
      });
      mount.appendChild(b);
    });
  }

  global.SgxLocale = { get: get, set: set, pick: pick, renderToggle: renderToggle };
})(window);
