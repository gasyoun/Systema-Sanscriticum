/* SCORM 1.2 обёртка — мягкая: без LMS всё работает автономно */
var SCORM = (function () {
  var api = null, initialized = false;
  function findAPI(win) {
    var tries = 0;
    while (win && !win.API && tries < 12) {
      if (win.parent && win.parent !== win) win = win.parent; else break;
      tries++;
    }
    return win && win.API ? win.API : null;
  }
  function init() {
    try {
      api = findAPI(window);
      if (!api && window.opener) api = findAPI(window.opener);
      if (api) {
        initialized = api.LMSInitialize('') === 'true' || api.LMSInitialize('') === true;
        if (initialized && api.LMSSetValue('cmi.core.lesson_status', 'incomplete')) {}
      }
    } catch (e) { api = null; }
    return initialized;
  }
  function set(key, val) {
    if (!initialized || !api) return false;
    try { return api.LMSSetValue(key, String(val)); } catch (e) { return false; }
  }
  function get(key) {
    if (!initialized || !api) return '';
    try { return api.LMSGetValue(key); } catch (e) { return ''; }
  }
  function commit() {
    if (!initialized || !api) return;
    try { api.LMSCommit(''); } catch (e) {}
  }
  function finish() {
    if (!initialized || !api) return;
    try { api.LMSFinish(''); } catch (e) {}
  }
  return {
    init: init,
    available: function () { return initialized; },
    setLocation: function (h) { set('cmi.core.lesson_location', h); commit(); },
    getLocation: function () { return get('cmi.core.lesson_location'); },
    setSuspend: function (json) {
      var s = json.length > 4000 ? '' : json;
      if (s) { set('cmi.suspend_data', s); commit(); }
    },
    getSuspend: function () { return get('cmi.suspend_data'); },
    reportScore: function (percent) {
      set('cmi.core.score.raw', Math.round(percent));
      set('cmi.core.score.min', 0);
      set('cmi.core.score.max', 100);
      commit();
    },
    reportStatus: function (status) { set('cmi.core.lesson_status', status); commit(); },
    finish: finish
  };
})();
if (typeof window !== 'undefined') { window.addEventListener('load', function () { SCORM.init(); }); window.addEventListener('unload', function () { SCORM.finish(); }); }
