// "Describe" buttons on the Images and files screen: ask lib/features/alt-text.php for a description
// of an uploaded picture, show it in the row's text field, and announce it. Everything else on the
// screen works without this script.
(function () {
  'use strict';

  var status = document.getElementById('alt-text-status');
  var csrfInput = document.querySelector('input[name="csrf"]');
  var csrf = csrfInput ? csrfInput.value : '';

  function announce(msg) {
    if (!status) return;
    status.textContent = '';
    setTimeout(function () { status.textContent = msg; }, 50);
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-describe]');
    if (!button) return;
    var url = button.getAttribute('data-describe');
    var field = document.getElementById(button.getAttribute('data-alt-target'));
    var more = field ? document.getElementById(field.id + '-more') : null;
    var copy = field ? document.querySelector('[data-copy-target="' + field.id + '"]') : null;
    button.disabled = true;
    announce('Asking Claude to describe the picture. This takes a few seconds.');
    fetch('/admin/?a=alt_text', { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ url: url, lang: 'en' }) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.alt) throw new Error(j.error || 'No description came back. Try again in a moment.'); return j; }); })
      .then(function (j) {
        if (field) { field.value = j.alt; field.focus(); }
        if (more) more.textContent = j.description || '';
        if (copy) { copy.setAttribute('data-copy', j.alt); copy.hidden = false; }
        announce('Description: ' + j.alt + ' It is in the Description field, with a longer version under it.');
      })
      .catch(function (err) { announce(err.message); if (more) more.textContent = err.message; })
      .then(function () { button.disabled = false; });
  });
})();
