/* ============================================================================
   Halo Email Resources — playground engine
   Builds form controls from a config, substitutes [PLACEHOLDERS] into a template,
   and live-renders the result inside an isolated <iframe>. Pure client-side.

   initPlayground({
     template:   string with [PLACEHOLDER] tokens,
     fields:     [{ key, label, type, default, options?, hint? }],
     presets?:   { triggerKey: { optionValue: { targetKey: value, ... } } },
     backgrounds?: [{ label, color }],   // preview background swatches
     wrap:       function(html, bgColor) -> full HTML document string for the iframe
   })
   ============================================================================ */
(function (global) {
  "use strict";

  function elem(tag, attrs) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === "text") n.textContent = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    return n;
  }

  global.initPlayground = function (cfg) {
    var controls = document.getElementById("pg-controls");
    var frame    = document.getElementById("pg-frame");
    var srcOut   = document.getElementById("pg-src");
    var barBg    = document.getElementById("pg-bg-swatches");
    var inputs   = {};
    var bgColor  = (cfg.backgrounds && cfg.backgrounds[0] && cfg.backgrounds[0].color) || "#ffffff";

    // ---- build a control for each field ----
    cfg.fields.forEach(function (f) {
      var wrap = elem("div", { "class": "field" });
      var id = "f_" + f.key;
      var label = elem("label", { "for": id, text: f.label });
      wrap.appendChild(label);
      if (f.hint) wrap.appendChild(elem("span", { "class": "hint", text: f.hint }));

      var control;
      if (f.type === "select") {
        control = elem("select", { id: id });
        (f.options || []).forEach(function (opt) {
          var o = elem("option", { value: opt });
          o.textContent = opt;
          control.appendChild(o);
        });
        if (f.default != null) control.value = f.default;
        wrap.appendChild(control);
      } else if (f.type === "textarea") {
        control = elem("textarea", { id: id });
        control.value = f.default || "";
        wrap.appendChild(control);
      } else if (f.type === "color") {
        var row = elem("div", { "class": "color-row" });
        var picker = elem("input", { type: "color", value: f.default || "#000000" });
        var hex = elem("input", { type: "text", id: id, value: f.default || "" });
        // keep the two in sync
        picker.addEventListener("input", function () { hex.value = picker.value; render(); });
        hex.addEventListener("input", function () {
          if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
          render();
        });
        row.appendChild(picker);
        row.appendChild(hex);
        wrap.appendChild(row);
        control = hex;            // hex input is the source of truth for substitution
        control._picker = picker; // so presets can update the swatch too
      } else {
        control = elem("input", { type: (f.type === "url" ? "url" : (f.type === "number" ? "number" : "text")), id: id, value: f.default || "" });
        wrap.appendChild(control);
      }

      if (f.type !== "color") {
        control.addEventListener("input", render);
        control.addEventListener("change", render);
      }
      inputs[f.key] = control;
      controls.appendChild(wrap);
    });

    // ---- variant/preset wiring ----
    if (cfg.presets) {
      Object.keys(cfg.presets).forEach(function (trigger) {
        var map = cfg.presets[trigger];
        var src = inputs[trigger];
        if (!src) return;
        src.addEventListener("change", function () {
          var preset = map[src.value];
          if (!preset) return;
          Object.keys(preset).forEach(function (targetKey) {
            var t = inputs[targetKey];
            if (!t) return;
            t.value = preset[targetKey];
            if (t._picker && /^#[0-9a-fA-F]{6}$/.test(t.value)) t._picker.value = t.value;
          });
          render();
        });
      });
    }

    // ---- background swatches ----
    if (cfg.backgrounds && barBg) {
      cfg.backgrounds.forEach(function (b, i) {
        var sw = elem("button", { type: "button", "class": "swatch" + (i === 0 ? " is-active" : ""), title: b.label });
        sw.style.background = b.color;
        sw.addEventListener("click", function () {
          bgColor = b.color;
          Array.prototype.forEach.call(barBg.children, function (c) { c.classList.remove("is-active"); });
          sw.classList.add("is-active");
          render();
        });
        barBg.appendChild(sw);
      });
    }

    function values() {
      var v = {};
      cfg.fields.forEach(function (f) { v[f.key] = inputs[f.key] ? inputs[f.key].value : ""; });
      return v;
    }

    function substitute() {
      var html = cfg.template;
      var v = values();
      Object.keys(v).forEach(function (k) { html = html.split("[" + k + "]").join(v[k]); });
      return html;
    }

    function render() {
      var html = substitute();
      frame.srcdoc = cfg.wrap(html, bgColor);
      if (srcOut) srcOut.textContent = html;
    }

    // ---- copy-source button ----
    var copyBtn = document.getElementById("pg-copy");
    if (copyBtn && srcOut) {
      copyBtn.addEventListener("click", function () {
        var done = function () { var t = copyBtn.textContent; copyBtn.textContent = "Copied"; setTimeout(function () { copyBtn.textContent = t; }, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(srcOut.textContent).then(done, done);
        else done();
      });
    }

    render();
  };
})(window);
