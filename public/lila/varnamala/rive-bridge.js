/* ============================================================
   Varnamālā Rive bridge (H2436 W1).
   Loads @rive-app web runtime (global `rive` from CDN) when a
   .riv exists for the active akṣara; otherwise reports unavailable
   so the CSS morph proxy stays on screen.

   Designer contract: public/lila/varnamala/rive/README.md
   ============================================================ */
(function (global) {
  "use strict";

  var DEFAULTS = {
    base_path: "/lila/varnamala/rive/",
    state_machine: "Varnamala",
    stage_input: "stage",
    poke_trigger: "poke",
    // W1 keys expected on disk as {key}.riv
    w1_keys: ["ka", "ma"]
  };

  var cache = Object.create(null); // key -> "yes" | "no" | Promise
  var active = null; // { key, instance, canvas }

  function cfg(data) {
    var c = (data && data.rive) || {};
    return {
      base_path: c.base_path || DEFAULTS.base_path,
      state_machine: c.state_machine || DEFAULTS.state_machine,
      stage_input: c.stage_input || DEFAULTS.stage_input,
      poke_trigger: c.poke_trigger || DEFAULTS.poke_trigger,
      w1_keys: c.w1_keys || DEFAULTS.w1_keys,
      enabled: c.enabled !== false
    };
  }

  function rivUrl(config, key) {
    return config.base_path + encodeURIComponent(key) + ".riv";
  }

  function hasRuntime() {
    return !!(global.rive && global.rive.Rive);
  }

  function probe(url) {
    if (cache[url] === "yes") return Promise.resolve(true);
    if (cache[url] === "no") return Promise.resolve(false);
    if (cache[url] && typeof cache[url].then === "function") return cache[url];

    cache[url] = fetch(url, { method: "HEAD", credentials: "same-origin" })
      .then(function (r) {
        // Some static hosts 405 HEAD → fall back to GET range/GET
        if (r.ok) {
          cache[url] = "yes";
          return true;
        }
        if (r.status === 405 || r.status === 501) {
          return fetch(url, {
            method: "GET",
            credentials: "same-origin",
            headers: { Range: "bytes=0-0" }
          }).then(function (r2) {
            var ok = r2.ok || r2.status === 206;
            cache[url] = ok ? "yes" : "no";
            return ok;
          });
        }
        cache[url] = "no";
        return false;
      })
      .catch(function () {
        cache[url] = "no";
        return false;
      });
    return cache[url];
  }

  function cleanup() {
    if (active && active.instance) {
      try {
        active.instance.cleanup();
      } catch (e) { /* ignore */ }
    }
    active = null;
  }

  function setStage(n) {
    if (!active || !active.instance) return false;
    try {
      var sm = active.config.state_machine;
      var inputs = active.instance.stateMachineInputs(sm) || [];
      var stageName = active.config.stage_input;
      var pokeName = active.config.poke_trigger;
      var stageIn = null;
      var pokeIn = null;
      for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].name === stageName) stageIn = inputs[i];
        if (inputs[i].name === pokeName) pokeIn = inputs[i];
      }
      if (stageIn != null && typeof stageIn.value !== "undefined") {
        stageIn.value = n;
      }
      if (pokeIn && typeof pokeIn.fire === "function" && n > 0) {
        pokeIn.fire();
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  /**
   * Ensure Rive is showing for aksara key at stage n.
   * @returns {Promise<"rive"|"css">}
   */
  function show(canvas, data, key, stage) {
    var config = cfg(data);
    if (!config.enabled || !key || !canvas) {
      cleanup();
      return Promise.resolve("css");
    }
    if (!hasRuntime()) {
      cleanup();
      return Promise.resolve("css");
    }

    var url = rivUrl(config, key);
    return probe(url).then(function (ok) {
      if (!ok) {
        cleanup();
        canvas.hidden = true;
        return "css";
      }

      // Reuse instance for same key
      if (active && active.key === key && active.instance) {
        setStage(stage);
        canvas.hidden = false;
        try {
          active.instance.resizeDrawingSurfaceToCanvas();
        } catch (e) {}
        return "rive";
      }

      cleanup();
      canvas.hidden = false;

      return new Promise(function (resolve) {
        var settled = false;
        function done(mode) {
          if (settled) return;
          settled = true;
          resolve(mode);
        }

        try {
          var instance = new global.rive.Rive({
            src: url,
            canvas: canvas,
            autoplay: true,
            autoBind: true,
            stateMachines: config.state_machine,
            onLoad: function () {
              try {
                instance.resizeDrawingSurfaceToCanvas();
              } catch (e) {}
              active = { key: key, instance: instance, canvas: canvas, config: config };
              setStage(stage);
              done("rive");
            },
            onLoadError: function () {
              cleanup();
              canvas.hidden = true;
              cache[url] = "no";
              done("css");
            }
          });
          // Safety: if neither callback fires
          setTimeout(function () {
            if (!settled) {
              if (active && active.key === key) done("rive");
              else {
                cleanup();
                canvas.hidden = true;
                done("css");
              }
            }
          }, 4000);
        } catch (e) {
          cleanup();
          canvas.hidden = true;
          done("css");
        }
      });
    });
  }

  function modeFor(data, key) {
    var config = cfg(data);
    if (!config.enabled || !hasRuntime()) return Promise.resolve("css");
    return probe(rivUrl(config, key)).then(function (ok) {
      return ok ? "rive" : "css";
    });
  }

  global.VarnamalaRive = {
    show: show,
    setStage: setStage,
    cleanup: cleanup,
    hasRuntime: hasRuntime,
    modeFor: modeFor,
    defaults: DEFAULTS
  };
})(typeof window !== "undefined" ? window : globalThis);
