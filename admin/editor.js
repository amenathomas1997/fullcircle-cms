// The visual (what you see is what you get) editor for blog posts, using TinyMCE.
// Keyboard help: Alt+F9 menu bar, Alt+F10 toolbar, Alt+0 list of shortcuts.
(function () {
  'use strict';

  var textarea = document.getElementById('post-body');
  if (!textarea || !window.tinymce) return;

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

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
    images_upload_handler: function (blobInfo, progress) {
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
      var form = textarea.form;
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
        }
        // The editing area sits in its own frame, so give it the field's name directly.
        if (label) {
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
})();
