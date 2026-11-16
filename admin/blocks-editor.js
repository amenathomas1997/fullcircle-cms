// The Blocks editor: a way to write posts and pages that works fully with JAWS, NVDA and VoiceOver.
//
// Instead of one editing area that behaves differently in every screen reader, the content is a
// list of blocks (paragraphs, headings, lists, quotes, images), each an ordinary form field with a
// label. Bold, italic, links and code are typed as plain marks, or added with buttons that wrap the
// selected text: **bold**, *italic*, [link text](https://example.com), `code`.
// Anything the blocks can't describe (a table, a shortcode inside a layout, nested lists) becomes
// an HTML block and is kept exactly as it was, so nothing is ever lost by switching editors.
(function () {
  'use strict';

  var TYPES = {
    p: 'Paragraph', h2: 'Heading 2', h3: 'Heading 3', h4: 'Heading 4',
    ul: 'Bulleted list', ol: 'Numbered list', quote: 'Quote', image: 'Image', html: 'HTML'
  };
  var INLINE_OK = { STRONG: 1, B: 1, EM: 1, I: 1, A: 1, BR: 1, CODE: 1 };

  var state = { textarea: null, root: null, blocks: [], live: null, form: null, csrf: '' };

  /* ---------- HTML to blocks ---------- */

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Is this element made only of text and the few inline tags the marks can express?
  function simpleInline(el) {
    var ok = true;
    el.querySelectorAll('*').forEach(function (child) {
      if (!INLINE_OK[child.tagName]) ok = false;
      if (child.tagName === 'A' && (child.attributes.length > 1 || !child.getAttribute('href'))) ok = false;
      if (child.tagName !== 'A' && child.attributes.length) ok = false;
    });
    return ok;
  }

  // Inline HTML to marks: <strong>x</strong> becomes **x**, and so on.
  function inlineToMarks(el) {
    var out = '';
    el.childNodes.forEach(function (n) {
      if (n.nodeType === 3) {
        out += n.nodeValue.replace(/\s+/g, ' ');
      } else if (n.nodeType === 1) {
        var inner = inlineToMarks(n);
        switch (n.tagName) {
          case 'STRONG': case 'B': out += '**' + inner + '**'; break;
          case 'EM': case 'I': out += '*' + inner + '*'; break;
          case 'CODE': out += '`' + inner + '`'; break;
          case 'A': out += '[' + inner + '](' + n.getAttribute('href') + ')'; break;
          case 'BR': out += '\n'; break;
          default: out += inner;
        }
      }
    });
    return out;
  }

  function onlyImage(el) {
    var img = null, other = false;
    el.childNodes.forEach(function (n) {
      if (n.nodeType === 3 && n.nodeValue.trim() === '') return;
      if (n.nodeType === 1 && n.tagName === 'IMG' && !img) { img = n; return; }
      if (n.nodeType === 1 && n.tagName === 'FIGCAPTION') return;
      other = true;
    });
    return other ? null : img;
  }

  function imageBlock(img, caption) {
    return { type: 'image', src: img.getAttribute('src') || '', alt: img.getAttribute('alt') || '',
      decorative: img.getAttribute('alt') === '', caption: caption || '' };
  }

  function parse(html) {
    var doc = new DOMParser().parseFromString('<div id="r">' + html + '</div>', 'text/html');
    var root = doc.getElementById('r');
    var blocks = [];
    root.childNodes.forEach(function (n) {
      if (n.nodeType === 3) {
        if (n.nodeValue.trim() !== '') blocks.push({ type: 'p', text: n.nodeValue.trim() });
        return;
      }
      if (n.nodeType !== 1) return;
      var tag = n.tagName, img;
      if ((tag === 'H2' || tag === 'H3' || tag === 'H4') && !n.attributes.length && simpleInline(n)) {
        blocks.push({ type: tag.toLowerCase(), text: inlineToMarks(n).trim() });
      } else if (tag === 'P' && !n.attributes.length && (img = onlyImage(n)) && img.attributes.length <= 4) {
        blocks.push(imageBlock(img, ''));
      } else if (tag === 'FIGURE' && (img = onlyImage(n))) {
        var cap = n.querySelector('figcaption');
        if (cap && simpleInline(cap)) blocks.push(imageBlock(img, inlineToMarks(cap).trim()));
        else if (!cap) blocks.push(imageBlock(img, ''));
        else blocks.push({ type: 'html', html: n.outerHTML });
      } else if (tag === 'IMG') {
        blocks.push(imageBlock(n, ''));
      } else if (tag === 'P' && (!n.attributes.length || (n.attributes.length === 1 && n.className === 'lede')) && simpleInline(n)) {
        var text = inlineToMarks(n).trim();
        if (text !== '') blocks.push({ type: 'p', text: text, lede: n.className === 'lede' });
      } else if ((tag === 'UL' || tag === 'OL') && !n.attributes.length && !n.querySelector('ul, ol') &&
          Array.prototype.every.call(n.children, function (li) { return li.tagName === 'LI' && !li.attributes.length && simpleInline(li); })) {
        blocks.push({ type: tag.toLowerCase(), items: Array.prototype.map.call(n.children, function (li) { return inlineToMarks(li).trim(); }) });
      } else if (tag === 'BLOCKQUOTE' && !n.attributes.length && simpleInline(n) &&
          Array.prototype.every.call(n.children, function (c) { return c.tagName === 'P' || INLINE_OK[c.tagName]; })) {
        var paras = n.querySelectorAll('p');
        blocks.push({ type: 'quote', text: paras.length ? Array.prototype.map.call(paras, function (p) { return inlineToMarks(p).trim(); }).join('\n\n') : inlineToMarks(n).trim() });
      } else {
        blocks.push({ type: 'html', html: n.outerHTML });
      }
    });
    if (!blocks.length) blocks.push({ type: 'p', text: '' });
    return blocks;
  }

  /* ---------- Blocks to HTML ---------- */

  function safeUrl(url) {
    url = url.trim();
    return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url) ? url : 'https://' + url;
  }

  function marksToHtml(text) {
    var s = escapeHtml(text);
    s = s.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (m, label, url) { return '<a href="' + escapeHtml(safeUrl(url)) + '">' + label + '</a>'; });
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    s = s.replace(/\*\*(\S(?:[^*\n]*?\S)?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*\w])\*(\S(?:[^*\n]*?\S)?)\*(?!\w)/g, '$1<em>$2</em>');
    return s.replace(/\n/g, '<br>');
  }

  function serialize() {
    return state.blocks.map(function (b) {
      switch (b.type) {
        case 'h2': case 'h3': case 'h4':
          return b.text.trim() === '' ? '' : '<' + b.type + '>' + marksToHtml(b.text.trim()) + '</' + b.type + '>';
        case 'p':
          return b.text.trim() === '' ? '' : '<p' + (b.lede ? ' class="lede"' : '') + '>' + marksToHtml(b.text.trim()) + '</p>';
        case 'ul': case 'ol':
          var items = b.items.filter(function (i) { return i.trim() !== ''; });
          return items.length ? '<' + b.type + '>' + items.map(function (i) { return '<li>' + marksToHtml(i.trim()) + '</li>'; }).join('') + '</' + b.type + '>' : '';
        case 'quote':
          var paras = b.text.split(/\n\s*\n/).filter(function (p) { return p.trim() !== ''; });
          return paras.length ? '<blockquote>' + paras.map(function (p) { return '<p>' + marksToHtml(p.trim()) + '</p>'; }).join('') + '</blockquote>' : '';
        case 'image':
          if (!b.src.trim()) return '';
          var img = '<img src="' + escapeHtml(b.src.trim()) + '" alt="' + (b.decorative ? '' : escapeHtml(b.alt.trim())) + '">';
          return b.caption.trim() ? '<figure>' + img + '<figcaption>' + marksToHtml(b.caption.trim()) + '</figcaption></figure>' : '<p>' + img + '</p>';
        default:
          return b.html.trim();
      }
    }).filter(Boolean).join('\n');
  }

  function sync() {
    state.textarea.value = serialize();
    if (state.form) state.form.dataset.dirty = '1';
  }

  /* ---------- The form ---------- */

  function announce(msg) {
    state.live.textContent = '';
    setTimeout(function () { state.live.textContent = msg; }, 50);
  }

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }

  function fieldId(i, name) { return 'blk-' + i + '-' + name; }

  // Wrap the selected text of a textarea in marks, or insert a placeholder to type into.
  function wrap(ta, before, after, placeholder) {
    var start = ta.selectionStart, end = ta.selectionEnd;
    var selected = ta.value.slice(start, end) || placeholder;
    ta.setRangeText(before + selected + after, start, end, 'select');
    ta.setSelectionRange(start + before.length, start + before.length + selected.length);
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function markButtons(ta) {
    var bar = el('div', { class: 'block-marks', role: 'group', 'aria-label': 'Formatting' });
    [['Bold', '**', '**', 'bold text'], ['Italic', '*', '*', 'italic text'], ['Code', '`', '`', 'code']].forEach(function (b) {
      var btn = el('button', { type: 'button', class: 'button button-small button-outline', text: b[0] });
      btn.addEventListener('click', function () { wrap(ta, b[1], b[2], b[3]); });
      bar.appendChild(btn);
    });
    var link = el('button', { type: 'button', class: 'button button-small button-outline', text: 'Link' });
    link.addEventListener('click', function () {
      var url = window.prompt('Link address (for example https://example.com or /about/)');
      if (url === null || url.trim() === '') { ta.focus(); return; }
      var start = ta.selectionStart, end = ta.selectionEnd;
      var label = ta.value.slice(start, end) || 'link text';
      ta.setRangeText('[' + label + '](' + url.trim() + ')', start, end, 'end');
      ta.focus();
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      announce('Link added.');
    });
    bar.appendChild(link);
    return bar;
  }

  function textControl(i, b, label, rows, get, set) {
    var id = fieldId(i, 'text');
    var ta = el('textarea', { id: id, rows: rows, 'aria-describedby': 'blocks-help' });
    ta.value = get();
    ta.addEventListener('input', function () { set(ta.value); sync(); });
    return el('div', { class: 'field' }, [el('label', { for: id, text: label }), markButtons(ta), ta]);
  }

  function renderBlock(b, i) {
    var n = state.blocks.length;
    var titleId = fieldId(i, 'title');
    var section = el('section', { class: 'block', 'aria-labelledby': titleId, 'data-index': i });
    section.appendChild(el('h3', { id: titleId, class: 'block-title', text: 'Block ' + (i + 1) + ' of ' + n + ': ' + TYPES[b.type] }));

    var selId = fieldId(i, 'type');
    var sel = el('select', { id: selId });
    Object.keys(TYPES).forEach(function (t) {
      var o = el('option', { value: t, text: TYPES[t] });
      if (t === b.type) o.selected = true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', function () { changeType(i, sel.value); });
    section.appendChild(el('div', { class: 'field field-inline' }, [el('label', { for: selId, text: 'Block type' }), sel]));

    if (b.type === 'p' || b.type === 'h2' || b.type === 'h3' || b.type === 'h4') {
      section.appendChild(textControl(i, b, b.type === 'p' ? 'Paragraph text' : TYPES[b.type] + ' text', b.type === 'p' ? 5 : 2,
        function () { return b.text; }, function (v) { b.text = v; }));
      if (b.type === 'p') {
        var ledeId = fieldId(i, 'lede');
        var cb = el('input', { type: 'checkbox', id: ledeId });
        cb.checked = !!b.lede;
        cb.addEventListener('change', function () { b.lede = cb.checked; sync(); });
        section.appendChild(el('div', { class: 'field checkbox' }, [cb, el('label', { for: ledeId, text: 'Opening paragraph (larger text)' })]));
      }
    } else if (b.type === 'ul' || b.type === 'ol') {
      section.appendChild(textControl(i, b, 'List items, one per line', Math.max(4, b.items.length + 1),
        function () { return b.items.join('\n'); }, function (v) { b.items = v.split('\n'); }));
    } else if (b.type === 'quote') {
      section.appendChild(textControl(i, b, 'Quote text (a blank line starts a new paragraph)', 5,
        function () { return b.text; }, function (v) { b.text = v; }));
    } else if (b.type === 'image') {
      section.appendChild(imageFields(i, b));
    } else {
      var hid = fieldId(i, 'html');
      var hta = el('textarea', { id: hid, rows: 8, spellcheck: 'false', class: 'code' });
      hta.value = b.html;
      hta.addEventListener('input', function () { b.html = hta.value; sync(); });
      section.appendChild(el('div', { class: 'field' }, [el('label', { for: hid, text: 'HTML' }),
        el('p', { class: 'hint', text: 'Kept exactly as written. Used for tables, shortcodes and anything the other block types can’t describe.' }), hta]));
    }

    var actions = el('div', { class: 'block-actions', role: 'group', 'aria-label': 'Block ' + (i + 1) + ' actions' });
    [['Add block below', function () { insertBlock(i + 1); }],
     ['Move up', function () { moveBlock(i, -1); }, i === 0],
     ['Move down', function () { moveBlock(i, 1); }, i === n - 1],
     ['Delete block', function () { deleteBlock(i); }]].forEach(function (a) {
      var btn = el('button', { type: 'button', class: 'button button-small button-outline', text: a[0] });
      if (a[2]) btn.disabled = true;
      btn.addEventListener('click', a[1]);
      actions.appendChild(btn);
    });
    section.appendChild(actions);
    return section;
  }

  function imageFields(i, b) {
    var box = document.createDocumentFragment();
    var srcId = fieldId(i, 'src'), altId = fieldId(i, 'alt'), decId = fieldId(i, 'dec'), capId = fieldId(i, 'cap'), fileId = fieldId(i, 'file');
    var src = el('input', { type: 'text', id: srcId, spellcheck: 'false' });
    src.value = b.src;
    src.addEventListener('input', function () { b.src = src.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: srcId, text: 'Image address' }),
      el('p', { class: 'hint', id: srcId + '-hint', text: 'Upload a picture below, or paste the address of one from Images and files.' }), src]));
    var file = el('input', { type: 'file', id: fileId, accept: 'image/jpeg,image/png,image/gif,image/webp' });
    file.addEventListener('change', function () {
      if (!file.files[0]) return;
      announce('Uploading the image.');
      var data = new FormData();
      data.append('file', file.files[0], file.files[0].name);
      fetch('/admin/?a=upload_image', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': state.csrf } })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.location) throw new Error(j.error || 'The image could not be uploaded.'); return j.location; }); })
        .then(function (url) { b.src = url; src.value = url; sync(); announce('Image uploaded. Now describe it in the Alt text field.'); document.getElementById(altId).focus(); })
        .catch(function (e) { announce(e.message); window.alert(e.message); });
    });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: fileId, text: 'Upload an image' }), file]));
    var alt = el('input', { type: 'text', id: altId });
    alt.value = b.alt;
    alt.disabled = !!b.decorative;
    alt.addEventListener('input', function () { b.alt = alt.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: altId, text: 'Alt text: what the picture shows, in a sentence' }), alt]));
    var dec = el('input', { type: 'checkbox', id: decId });
    dec.checked = !!b.decorative;
    dec.addEventListener('change', function () { b.decorative = dec.checked; alt.disabled = dec.checked; sync(); });
    box.appendChild(el('div', { class: 'field checkbox' }, [dec, el('label', { for: decId, text: 'Decorative image (adds nothing a reader needs; screen readers skip it)' })]));
    var cap = el('input', { type: 'text', id: capId });
    cap.value = b.caption;
    cap.addEventListener('input', function () { b.caption = cap.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: capId, text: 'Caption (optional, shown under the picture)' }), cap]));
    return box;
  }

  function render(focusIndex, focusWhat) {
    var list = state.root.querySelector('.block-list');
    list.innerHTML = '';
    state.blocks.forEach(function (b, i) { list.appendChild(renderBlock(b, i)); });
    sync();
    if (focusIndex !== undefined) {
      var target = document.getElementById(fieldId(Math.min(focusIndex, state.blocks.length - 1), focusWhat || 'type'));
      if (target) target.focus();
    }
  }

  function changeType(i, type) {
    var b = state.blocks[i], text = '';
    if (b.text !== undefined) text = b.text;
    else if (b.items) text = b.items.join('\n');
    else if (b.type === 'image') text = b.caption || '';
    else if (b.type === 'html') text = b.html.replace(/<[^>]+>/g, '').trim();
    var nb;
    if (type === 'ul' || type === 'ol') nb = { type: type, items: text.split('\n') };
    else if (type === 'image') nb = { type: 'image', src: '', alt: '', decorative: false, caption: '' };
    else if (type === 'html') nb = { type: 'html', html: b.type === 'html' ? b.html : serializeOne(b) };
    else nb = { type: type, text: text, lede: b.lede };
    state.blocks[i] = nb;
    render(i, 'type');
    announce('Block ' + (i + 1) + ' is now a ' + TYPES[type].toLowerCase() + '.');
  }

  function serializeOne(b) {
    var keep = state.blocks;
    state.blocks = [b];
    var out = serialize();
    state.blocks = keep;
    return out;
  }

  function insertBlock(at) {
    state.blocks.splice(at, 0, { type: 'p', text: '' });
    render(at, 'text');
    announce('New paragraph added as block ' + (at + 1) + ' of ' + state.blocks.length + '. Type its text.');
  }

  function moveBlock(i, dir) {
    var j = i + dir;
    if (j < 0 || j >= state.blocks.length) return;
    var b = state.blocks.splice(i, 1)[0];
    state.blocks.splice(j, 0, b);
    render();
    var btn = state.root.querySelectorAll('.block')[j].querySelector('.block-actions button:nth-child(' + (dir < 0 ? 2 : 3) + ')');
    (btn && !btn.disabled ? btn : document.getElementById(fieldId(j, 'type'))).focus();
    announce(TYPES[b.type] + ' moved ' + (dir < 0 ? 'up' : 'down') + ' to block ' + (j + 1) + ' of ' + state.blocks.length + '.');
  }

  function deleteBlock(i) {
    var b = state.blocks[i];
    var hasContent = (b.text && b.text.trim()) || (b.items && b.items.join('').trim()) || (b.src && b.src.trim()) || (b.html && b.html.trim());
    if (hasContent && !window.confirm('Delete block ' + (i + 1) + ' (' + TYPES[b.type].toLowerCase() + ')? This can’t be undone.')) return;
    state.blocks.splice(i, 1);
    if (!state.blocks.length) state.blocks.push({ type: 'p', text: '' });
    render(Math.min(i, state.blocks.length - 1), 'type');
    announce(TYPES[b.type] + ' deleted. ' + state.blocks.length + ' blocks left.');
  }

  /* ---------- Start and stop ---------- */

  function init(textarea) {
    state.textarea = textarea;
    state.form = textarea.form;
    state.csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    state.blocks = parse(textarea.value);

    var root = el('div', { class: 'blocks-editor', role: 'region', 'aria-label': 'Content blocks' });
    root.appendChild(el('p', { id: 'blocks-help', class: 'hint', html:
      'Each block is a form field. Use the Block type list to make it a heading, list, quote or image, and the buttons under each block to add, move or delete it. ' +
      'For emphasis, select words and press Bold, Italic or Link, or type the marks yourself: <code>**bold**</code>, <code>*italic*</code>, <code>[link text](https://example.com)</code>.' }));
    var top = el('button', { type: 'button', class: 'button button-small button-outline', text: 'Add a block at the top' });
    top.addEventListener('click', function () { insertBlock(0); });
    root.appendChild(top);
    root.appendChild(el('div', { class: 'block-list' }));
    state.live = el('div', { class: 'visually-hidden', 'aria-live': 'polite', 'aria-atomic': 'true' });
    root.appendChild(state.live);
    state.root = root;

    textarea.hidden = true;
    textarea.insertAdjacentElement('afterend', root);
    render();

    // The error summary links to the hidden field; send that focus to the first block instead.
    document.querySelectorAll('a[href="#' + textarea.id + '"]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (!textarea.hidden) return;
        e.preventDefault();
        var first = root.querySelector('textarea, input[type="text"]');
        if (first) first.focus();
      });
    });
    if (state.form) state.form.addEventListener('submit', sync);
  }

  function destroy() {
    if (!state.root) return;
    sync();
    state.root.remove();
    state.textarea.hidden = false;
    state.root = null;
  }

  window.FCBlocks = { init: init, destroy: destroy, active: function () { return !!state.root; } };
})();
