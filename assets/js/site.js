// Full Circle CMS site script. Everything here is an extra: the site works without it.
(function () {
  'use strict';

  var root = document.documentElement;

  // After a form is sent, or has a problem, move focus to the message so
  // screen reader and keyboard users hear it straight away.
  var status = document.querySelector('[data-focus-on-load]');
  if (status) {
    status.focus();
  }

  // "Print this page" buttons on large-print pages.
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  /* ---------- Display options: text size and colors ---------- */

  var box = document.querySelector('.display-options');
  if (!box) return;
  var toggle = box.querySelector('.display-toggle');
  var panel = box.querySelector('.display-panel');
  var settings = { 'fc-text': ['data-text', 'default'], 'fc-theme': ['data-theme', 'auto'] };

  function load(name) {
    // Only values the panel offers: a stray stored value must never break the panel.
    try {
      var value = localStorage.getItem(name);
      value = value ? value.replace(/[^a-z-]/g, '') : '';
      return value && panel.querySelector('input[name="' + name + '"][value="' + value + '"]') ? value : settings[name][1];
    } catch (e) { return settings[name][1]; }
  }
  function save(name, value) {
    try { localStorage.setItem(name, value); } catch (e) { /* private browsing: still applies to this page */ }
  }
  function apply(name, value) {
    var attr = settings[name][0];
    if (value === settings[name][1]) {
      root.removeAttribute(attr);
    } else {
      root.setAttribute(attr, value);
    }
  }
  function sync() {
    Object.keys(settings).forEach(function (name) {
      var input = panel.querySelector('input[name="' + name + '"][value="' + load(name) + '"]');
      if (input) input.checked = true;
    });
  }
  function open() {
    sync();
    panel.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    var first = panel.querySelector('input:checked') || panel.querySelector('input');
    first.focus();
  }
  function close(returnFocus) {
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    if (returnFocus) toggle.focus();
  }

  box.hidden = false;
  toggle.addEventListener('click', function () {
    if (panel.hidden) { open(); } else { close(false); }
  });
  panel.addEventListener('change', function (e) {
    if (settings[e.target.name]) {
      save(e.target.name, e.target.value);
      apply(e.target.name, e.target.value);
    }
  });
  panel.querySelector('[data-display-reset]').addEventListener('click', function () {
    Object.keys(settings).forEach(function (name) {
      save(name, settings[name][1]);
      apply(name, settings[name][1]);
    });
    sync();
  });
  panel.querySelector('[data-display-close]').addEventListener('click', function () { close(true); });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) {
      e.preventDefault();
      close(true);
    }
  });
  document.addEventListener('click', function (e) {
    if (!panel.hidden && !box.contains(e.target)) close(false);
  });
  box.addEventListener('focusout', function (e) {
    if (!panel.hidden && e.relatedTarget && !box.contains(e.relatedTarget)) close(false);
  });
  /* ---------- Custom forms: fields shown on an earlier answer, and drawn signatures ---------- */

  document.querySelectorAll('form[data-form-conditions]').forEach(function (form) {
    var dependent = form.querySelectorAll('[data-show-if]');
    if (!dependent.length) return;
    function answer(index, type) {
      if (type === 'checkbox') {
        var box = form.querySelector('[name="f[' + index + ']"][type="checkbox"]');
        return box && box.checked ? 'yes' : 'no';
      }
      if (type === 'checkboxes') {
        return Array.prototype.map.call(form.querySelectorAll('[name="f[' + index + '][]"]:checked'), function (b) { return b.value; });
      }
      var el = form.querySelector('[name="f[' + index + ']"]');
      return el ? el.value : '';
    }
    function met(el) {
      var type = el.getAttribute('data-show-if-type');
      var want = (el.getAttribute('data-show-if-value') || '').trim().toLowerCase();
      var got = answer(el.getAttribute('data-show-if'), type);
      if (type === 'checkbox') {
        var wantYes = ['', 'yes', 'y', '1', 'true', 'sí', 'si', 'ticked', 'checked'].indexOf(want) !== -1;
        return (got === 'yes') === wantYes;
      }
      if (type === 'checkboxes') return got.some(function (v) { return v.trim().toLowerCase() === want; });
      return String(got).trim().toLowerCase() === want;
    }
    function shown(el) {
      if (!met(el)) return false;
      // Hidden too if the field it depends on is itself hidden.
      var controller = form.querySelector('[data-field="' + el.getAttribute('data-show-if') + '"]');
      return !controller || !controller.hasAttribute('data-show-if') || shown(controller);
    }
    function update() {
      dependent.forEach(function (el) {
        var show = shown(el);
        el.hidden = !show;
        el.querySelectorAll('input, select, textarea').forEach(function (c) {
          // A hidden field is not asked, so it must not block sending.
          if (show) { if (c.dataset.wasRequired) { c.required = true; delete c.dataset.wasRequired; } }
          else if (c.required) { c.dataset.wasRequired = '1'; c.required = false; }
        });
      });
    }
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
  });

  document.querySelectorAll('[data-signature]').forEach(function (field) {
    var box = field.querySelector('[data-signature-draw]');
    var canvas = box && box.querySelector('canvas');
    var hidden = field.querySelector('[data-signature-data]');
    if (!canvas || !hidden || !canvas.getContext || !window.PointerEvent) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, drawn = false;
    box.hidden = false;
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = getComputedStyle(canvas).color || '#000';
    function point(e) {
      var r = canvas.getBoundingClientRect();
      return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height];
    }
    canvas.addEventListener('pointerdown', function (e) {
      drawing = true;
      canvas.setPointerCapture(e.pointerId);
      var p = point(e);
      ctx.beginPath();
      ctx.moveTo(p[0], p[1]);
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = point(e);
      ctx.lineTo(p[0], p[1]);
      ctx.stroke();
      drawn = true;
      e.preventDefault();
    });
    function stop() {
      if (!drawing) return;
      drawing = false;
      hidden.value = drawn ? canvas.toDataURL('image/png') : '';
    }
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    canvas.addEventListener('pointerleave', stop);
    var clear = field.querySelector('[data-signature-clear]');
    if (clear) clear.addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      drawn = false;
      hidden.value = '';
    });
  });
})();
