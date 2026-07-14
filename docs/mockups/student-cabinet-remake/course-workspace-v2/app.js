/* H822 mockup #2 — light JS layer. No external requests, console-clean.
   Features: theme toggle (localStorage), workspace tabs (hash-addressable),
   collapsible foldlines with remembered state. */

(function () {
  'use strict';

  var STORE = 'ss-mockup2-theme';

  /* ---------- theme ---------- */
  function applyTheme(t) {
    if (t === 'light' || t === 'dark') {
      document.documentElement.setAttribute('data-theme', t);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  }
  try { applyTheme(localStorage.getItem(STORE)); } catch (e) { /* storage may be blocked on file:// */ }

  function currentTheme() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t) return t;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('themeToggle');
    if (btn) {
      btn.addEventListener('click', function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem(STORE, next); } catch (e) { /* ignore */ }
      });
    }

    /* ---------- workspace tabs (course page) ---------- */
    var tabbar = document.querySelector('.ws-tabs[data-tabs]');
    if (tabbar) {
      var links = Array.prototype.slice.call(tabbar.querySelectorAll('a[data-tab]'));
      var panels = Array.prototype.slice.call(document.querySelectorAll('.tab-panel'));

      function show(id, push) {
        var found = false;
        panels.forEach(function (p) {
          var match = p.id === 'tab-' + id;
          p.hidden = !match;
          if (match) found = true;
        });
        links.forEach(function (a) {
          var active = a.getAttribute('data-tab') === id;
          a.classList.toggle('active', active);
          if (active) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
        });
        if (found && push) {
          history.replaceState(null, '', '#' + id);
        }
        return found;
      }

      links.forEach(function (a) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          show(a.getAttribute('data-tab'), true);
        });
      });

      var fromHash = location.hash.replace('#', '');
      if (!fromHash || !show(fromHash, false)) {
        show(links[0].getAttribute('data-tab'), false);
      }
      window.addEventListener('hashchange', function () {
        var id = location.hash.replace('#', '');
        if (id) show(id, false);
      });
    }

    /* ---------- foldlines remember state ---------- */
    Array.prototype.slice.call(document.querySelectorAll('details.foldline[data-fold]')).forEach(function (d) {
      var key = 'ss-mockup2-fold-' + d.getAttribute('data-fold');
      try {
        var saved = localStorage.getItem(key);
        if (saved === 'open') d.open = true;
        if (saved === 'closed') d.open = false;
      } catch (e) { /* ignore */ }
      d.addEventListener('toggle', function () {
        try { localStorage.setItem(key, d.open ? 'open' : 'closed'); } catch (e) { /* ignore */ }
      });
    });
  });
})();
