// Control panel behavior. Everything here is an enhancement: every screen
// works with JavaScript turned off.
(function () {
  'use strict';

  // Move focus to a message or error summary so it is announced straight away.
  var message = document.getElementById('error-summary') || document.getElementById('notice');
  if (message) {
    message.focus();
  }

  // Links in the error summary: focus the field and announce its label.
  document.addEventListener('click', function (e) {
    var link = e.target.closest('.error-summary a[href^="#"]');
    if (!link) return;
    var field = document.getElementById(link.getAttribute('href').slice(1));
    if (!field) return;
    e.preventDefault();
    if (window.tinymce && field.id === 'post-body' && tinymce.get('post-body')) {
      tinymce.get('post-body').focus();
      return;
    }
    if (field.type === 'radio' || field.type === 'checkbox') {
      field.focus();
    } else {
      var label = document.querySelector('label[for="' + field.id + '"]');
      (label || field).scrollIntoView({ block: 'center' });
      field.focus();
    }
  });

  // Command+S or Control+S saves the form on editing screens.
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 's') {
      var form = document.activeElement && document.activeElement.closest('form[data-editor-form]');
      form = form || document.querySelector('form[data-editor-form]');
      if (form) {
        e.preventDefault();
        window.saveEditorForm(form);
      }
    }
  });

  window.saveEditorForm = function (form) {
    var button = form.querySelector('button[type="submit"]:not([formaction])');
    if (window.tinymce) tinymce.triggerSave();
    form.dataset.saving = '1';
    if (form.requestSubmit) {
      form.requestSubmit(button);
    } else {
      button.click();
    }
  };

  // Warn before leaving a screen with unsaved changes.
  var forms = document.querySelectorAll('form[data-editor-form]');
  forms.forEach(function (form) {
    form.addEventListener('input', function () { form.dataset.dirty = '1'; });
    form.addEventListener('change', function () { form.dataset.dirty = '1'; });
    form.addEventListener('submit', function (e) {
      // Preview opens in a new tab, so the form is still unsaved.
      var submitter = e.submitter;
      if (!submitter || !submitter.hasAttribute('formaction')) {
        form.dataset.saving = '1';
      }
    });
  });
  window.addEventListener('beforeunload', function (e) {
    var unsaved = Array.prototype.some.call(forms, function (f) {
      var editorDirty = window.tinymce && tinymce.activeEditor && tinymce.activeEditor.isDirty() && f.contains(tinymce.activeEditor.getElement());
      return (f.dataset.dirty === '1' || editorDirty) && f.dataset.saving !== '1';
    });
    if (unsaved) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // "Copy address" buttons on the Images and files screen.
  var copyStatus = document.getElementById('copy-status');
  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-copy]');
    if (!button) return;
    var text = button.getAttribute('data-copy');
    var done = function () {
      if (copyStatus) {
        copyStatus.textContent = '';
        setTimeout(function () { copyStatus.textContent = 'Copied ' + text; }, 50);
      }
    };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this address:', text); });
    } else {
      window.prompt('Copy this address:', text);
    }
  });
})();
