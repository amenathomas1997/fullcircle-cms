// The content editor for posts, pages and cheat sheets. Three ways to write, chosen with the
// "Editor" radio buttons above the content and remembered in this browser:
//   Visual   TinyMCE, what you see is what you get. Alt+F9 menu bar, Alt+F10 toolbar, Alt+0 shortcuts.
//   Blocks   every paragraph, heading, list and image is its own labelled form field (admin/blocks-editor.js).
//            Built for JAWS, NVDA and VoiceOver: no editing area that behaves differently in each one.
//   HTML     the raw HTML in a plain text box.
(function () {
  'use strict';

  var textarea = document.getElementById('post-body');
  if (!textarea) return;

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  var form = textarea.form;
  var modes = [['visual', 'Visual (what you see is what you get)'], ['blocks', 'Blocks (best with a screen reader)'], ['html', 'HTML']];
  var mode = 'visual';
  try { mode = localStorage.getItem('fc-editor') || 'visual'; } catch (e) { /* ignore */ }
  if (!window.tinymce && mode === 'visual') mode = 'blocks';
  if (!window.FCBlocks && mode === 'blocks') mode = 'html';

  /* ---------- The switch ---------- */

  var field = textarea.closest('.field') || textarea.parentNode;
  var fs = document.createElement('fieldset');
  fs.className = 'editor-switch';
  var legend = document.createElement('legend');
  legend.textContent = 'Editor';
  fs.appendChild(legend);
  modes.forEach(function (m) {
    if (m[0] === 'visual' && !window.tinymce) return;
    if (m[0] === 'blocks' && !window.FCBlocks) return;
    var id = 'editor-mode-' + m[0];
    var wrap = document.createElement('div');
    wrap.className = 'option';
    var input = document.createElement('input');
    input.type = 'radio';
    input.name = 'editor_mode';
    input.id = id;
    input.value = m[0];
    input.checked = m[0] === mode;
    input.addEventListener('change', function () { switchTo(m[0], true); });
    var label = document.createElement('label');
    label.htmlFor = id;
    label.textContent = m[1];
    wrap.appendChild(input);
    wrap.appendChild(label);
    fs.appendChild(wrap);
  });
  var status = document.createElement('p');
  status.className = 'visually-hidden';
  status.setAttribute('aria-live', 'polite');
  fs.appendChild(status);
  field.parentNode.insertBefore(fs, field);

  function switchTo(next, remember) {
    // Leave the current editor, with its content in the text box.
    if (window.tinymce && tinymce.get(textarea.id)) {
      textarea.value = tinymce.get(textarea.id).getContent();
      tinymce.remove(tinymce.get(textarea.id));
    }
    if (window.FCBlocks && FCBlocks.active()) FCBlocks.destroy();
    textarea.hidden = false;
    mode = next;
    if (remember) {
      try { localStorage.setItem('fc-editor', next); } catch (e) { /* ignore */ }
    }
    if (next === 'visual') initVisual();
    if (next === 'blocks') FCBlocks.init(textarea);
    var name = modes.filter(function (m) { return m[0] === next; })[0][1];
    if (remember) status.textContent = 'Now using the ' + name + ' editor. Your content came with you.';
    if (remember && next === 'html') textarea.focus();
    if (remember && next === 'blocks') {
      var first = document.querySelector('.blocks-editor select');
      if (first) first.focus();
    }
  }

  /* ---------- Visual: TinyMCE ---------- */

  function initVisual() {
    tinymce.init({
      target: textarea,
      license_key: 'gpl',
      promotion: false,
      branding: false,
      height: 600,
      resize: false,
      skin: dark ? 'oxide-dark' : 'oxide',
      content_css: '/assets/css/site.css',
      content_style: 'body { padding: 1rem 1.25rem; max-width: 62rem; margin: 0 auto; }',
      iframe_aria_text: 'Post content. Press Alt and 0 for keyboard help.',

      plugins: 'lists advlist link autolink image table code charmap searchreplace wordcount autosave help fullscreen',
      menubar: 'edit insert format table tools help',
      toolbar: 'undo redo | blocks | bold italic | bullist numlist | link image table blockquote | removeformat | code fullscreen | help',
      toolbar_mode: 'wrap',
      block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
      help_accessibility: true,

      // Images: alt text is asked for every time; tick "Decorative image" only for images that add nothing.
      a11y_advanced_options: true,
      image_caption: true,
      image_title: false,
      image_dimensions: true,
      automatic_uploads: true,
      paste_data_images: true,
      images_file_types: 'jpg,jpeg,png,gif,webp',
      images_upload_handler: function (blobInfo) {
        var data = new FormData();
        data.append('file', blobInfo.blob(), blobInfo.filename());
        return fetch('/admin/?a=upload_image', {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf }
        }).then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok || !json.location) {
              throw { message: json.error || 'The image could not be uploaded.', remove: true };
            }
            return json.location;
          });
        });
      },

      // Links
      link_assume_external_targets: 'https',
      link_target_list: false,
      relative_urls: false,
      remove_script_host: true,
      convert_urls: true,

      // Keep a draft in this browser in case the page is closed before saving.
      autosave_ask_before_unload: false,
      autosave_restore_when_empty: false,
      autosave_prefix: 'fcat-post-{path}{query}-',
      autosave_retention: '1440m',

      setup: function (editor) {
        editor.addShortcut('meta+s', 'Save post', function () {
          window.saveEditorForm(form);
        });
        editor.on('init', function () {
          // Clicking the "Post content" label moves focus into the editor.
          var label = document.querySelector('label[for="post-body"]');
          if (label) {
            label.addEventListener('click', function (e) {
              e.preventDefault();
              editor.focus();
            });
            // The editing area sits in its own frame, so give it the field's name directly.
            editor.getBody().setAttribute('aria-label', label.textContent);
          }
          if (textarea.getAttribute('aria-invalid') === 'true') {
            editor.getBody().setAttribute('aria-invalid', 'true');
          }
        });
        editor.on('change input', function () {
          form.dataset.dirty = '1';
        });
      }
    });
  }

  switchTo(mode, false);
})();
