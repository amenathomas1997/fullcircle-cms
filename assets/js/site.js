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
})();
