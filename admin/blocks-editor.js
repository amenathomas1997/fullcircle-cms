// The Blocks editor: a way to write posts and pages that works fully with JAWS, NVDA and VoiceOver.
//
// Instead of one editing area that behaves differently in every screen reader, the content is a
// list of blocks (paragraphs, headings, lists, quotes, images), each an ordinary form field with a
// label. Bold, italic, links and code are typed as plain marks, or added with buttons that wrap the
// selected text: **bold**, *italic*, [link text](https://example.com), `code`.
// Section blocks (hero, features, call to action, contact details, two columns) are sets of labelled
// fields that write a fixed shape of HTML, so a home page never needs hand-written HTML.
// Anything the blocks can't describe (a table, a shortcode inside a layout, nested lists) becomes
// an HTML block and is kept exactly as it was, so nothing is ever lost by switching editors.
(function () {
  'use strict';

  var TYPES = {
    p: 'Paragraph', h2: 'Heading 2', h3: 'Heading 3', h4: 'Heading 4',
    ul: 'Bulleted list', ol: 'Numbered list', quote: 'Quote', faq: 'Question and answer', image: 'Image',
    hero: 'Hero (page opening)', features: 'Features or services list', cta: 'Call to action',
    contact: 'Contact details', columns: 'Two columns', html: 'HTML'
  };
  var INLINE_OK = { STRONG: 1, B: 1, EM: 1, I: 1, A: 1, BR: 1, CODE: 1 };
  var CONTACT_KEYS = ['phone', 'email', 'address', 'hours'];
  var CONTACT_LABELS = { en: { phone: 'Phone:', email: 'Email:', address: 'Address:', hours: 'Hours:' },
    es: { phone: 'Teléfono:', email: 'Correo:', address: 'Dirección:', hours: 'Horario:' } };

  var state = { textarea: null, root: null, blocks: [], live: null, form: null, csrf: '',
    // Alt text help from Claude (lib/features/alt-text.php): filled in by init() once the feature answers.
    altText: { loaded: false, available: false, known: {} }, altButtons: [] };

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

  // The element children of a node, or null when it also holds text that isn't just spacing.
  function elementChildren(n) {
    var out = [];
    for (var k = 0; k < n.childNodes.length; k++) {
      var c = n.childNodes[k];
      if (c.nodeType === 3) { if (c.nodeValue.trim() !== '') return null; continue; }
      if (c.nodeType === 1) out.push(c);
    }
    return out;
  }

  // Is this element the given tag with no attributes, or with exactly class="className"?
  function isTag(c, tag, className) {
    if (!c || c.tagName !== tag) return false;
    if (className === undefined) return !c.attributes.length;
    return c.attributes.length === 1 && c.getAttribute('class') === className;
  }

  // Paragraphs and simple lists inside a container, as marked-up text, or null if there is anything else.
  // Used by the Question and answer block (skipping its <summary>) and both columns of a Two columns block.
  function parseRich(container, skip) {
    var out = [];
    var ok = true;
    Array.prototype.forEach.call(container.childNodes, function (c) {
      if (c === skip) return;
      if (c.nodeType === 3) { if (c.nodeValue.trim() !== '') ok = false; return; }
      if (c.nodeType !== 1) return;
      if (c.tagName === 'P' && !c.attributes.length && simpleInline(c)) {
        out.push(inlineToMarks(c).trim());
      } else if ((c.tagName === 'UL' || c.tagName === 'OL') && !c.attributes.length && !c.querySelector('ul, ol') &&
          Array.prototype.every.call(c.children, function (li) { return li.tagName === 'LI' && !li.attributes.length && simpleInline(li); })) {
        out.push(Array.prototype.map.call(c.children, function (li, k) { return (c.tagName === 'UL' ? '- ' : (k + 1) + '. ') + inlineToMarks(li).trim(); }).join('\n'));
      } else {
        ok = false;
      }
    });
    return ok ? out.join('\n\n') : null;
  }

  // A <details><summary>question</summary>answer</details> entry, when the answer is paragraphs and simple lists.
  function parseDetails(n) {
    var summary = n.firstElementChild;
    if (!summary || summary.tagName !== 'SUMMARY' || summary.attributes.length || !simpleInline(summary)) return null;
    var answer = parseRich(n, summary);
    return answer === null ? null : { type: 'faq', question: inlineToMarks(summary).trim(), answer: answer };
  }

  // <p class="buttons"> holding one or two plain links: the first class="button", the second class="button button-outline".
  function parseButtons(p, max) {
    if (!isTag(p, 'P', 'buttons')) return null;
    var links = elementChildren(p);
    if (!links || !links.length || links.length > max) return null;
    var out = [];
    for (var k = 0; k < links.length; k++) {
      var a = links[k];
      if (a.tagName !== 'A' || a.attributes.length !== 2 || !a.getAttribute('href') || a.children.length) return null;
      if (a.getAttribute('class') !== (k === 0 ? 'button' : 'button button-outline')) return null;
      out.push({ label: a.textContent.trim(), url: a.getAttribute('href') });
    }
    return out;
  }

  // <section class="hero">: an h1 or h2, an optional opening paragraph, and up to two buttons.
  function parseHero(n) {
    var kids = elementChildren(n);
    if (!kids || !kids.length) return null;
    var b = { type: 'hero', level: 'h2', heading: '', lede: '', buttons: [{ label: '', url: '' }, { label: '', url: '' }] };
    var k = 0, buttons;
    if ((isTag(kids[k], 'H1') || isTag(kids[k], 'H2')) && simpleInline(kids[k])) { b.level = kids[k].tagName.toLowerCase(); b.heading = inlineToMarks(kids[k]).trim(); k++; }
    if (k < kids.length && isTag(kids[k], 'P', 'lede') && simpleInline(kids[k])) { b.lede = inlineToMarks(kids[k]).trim(); k++; }
    if (k < kids.length && (buttons = parseButtons(kids[k], 2))) { buttons.forEach(function (x, j) { b.buttons[j] = x; }); k++; }
    return k === kids.length ? b : null;
  }

  // <section class="features">: an optional h2, then <ul class="feature-list"> of <li><h3>Title</h3><p>Description</p></li>.
  function parseFeatures(n) {
    var kids = elementChildren(n);
    if (!kids || !kids.length) return null;
    var b = { type: 'features', heading: '', items: [] };
    var k = 0;
    if (isTag(kids[k], 'H2') && simpleInline(kids[k])) { b.heading = inlineToMarks(kids[k]).trim(); k++; }
    if (k !== kids.length - 1 || !isTag(kids[k], 'UL', 'feature-list')) return null;
    var items = elementChildren(kids[k]);
    if (!items || !items.length) return null;
    for (var j = 0; j < items.length; j++) {
      if (!isTag(items[j], 'LI')) return null;
      var parts = elementChildren(items[j]);
      if (!parts || !parts.length || parts.length > 2 || !isTag(parts[0], 'H3') || !simpleInline(parts[0])) return null;
      if (parts.length === 2 && (!isTag(parts[1], 'P') || !simpleInline(parts[1]))) return null;
      b.items.push({ title: inlineToMarks(parts[0]).trim(), text: parts.length === 2 ? inlineToMarks(parts[1]).trim() : '' });
    }
    return b;
  }

  // <section class="cta">: a short h2, one paragraph and one button.
  function parseCta(n) {
    var kids = elementChildren(n);
    if (!kids || !kids.length) return null;
    var b = { type: 'cta', heading: '', text: '', button: { label: '', url: '' } };
    var k = 0, buttons;
    if (isTag(kids[k], 'H2') && simpleInline(kids[k])) { b.heading = inlineToMarks(kids[k]).trim(); k++; }
    if (k < kids.length && isTag(kids[k], 'P') && simpleInline(kids[k])) { b.text = inlineToMarks(kids[k]).trim(); k++; }
    if (k < kids.length && (buttons = parseButtons(kids[k], 1))) { b.button = buttons[0]; k++; }
    return k === kids.length ? b : null;
  }

  // <section class="contact-details">: an optional h2 and a list of phone, email, address and hours lines.
  function parseContact(n) {
    var kids = elementChildren(n);
    if (!kids || !kids.length) return null;
    var b = { type: 'contact', heading: '', phone: '', email: '', address: '', hours: '', labels: {} };
    var k = 0;
    if (isTag(kids[k], 'H2') && simpleInline(kids[k])) { b.heading = inlineToMarks(kids[k]).trim(); k++; }
    if (k !== kids.length - 1 || !isTag(kids[k], 'UL')) return null;
    var items = elementChildren(kids[k]);
    if (!items || !items.length) return null;
    var seen = {};
    for (var j = 0; j < items.length; j++) {
      var li = items[j];
      var key = li.attributes.length === 1 ? (li.getAttribute('class') || '').replace(/^contact-/, '') : '';
      if (li.tagName !== 'LI' || CONTACT_KEYS.indexOf(key) === -1 || seen[key]) return null;
      seen[key] = true;
      var copy = li.cloneNode(true);
      var label = copy.firstElementChild;
      if (label && isTag(label, 'SPAN', 'contact-label') && !label.children.length) { b.labels[key] = label.textContent.trim(); copy.removeChild(label); }
      else b.labels[key] = '';
      if (key === 'phone' || key === 'email') {
        var rest = elementChildren(copy);
        var a = rest && rest.length === 1 ? rest[0] : null;
        if (!a || a.tagName !== 'A' || a.attributes.length !== 1 || a.children.length || (a.getAttribute('href') || '').indexOf(key === 'phone' ? 'tel:' : 'mailto:') !== 0) return null;
        b[key] = a.textContent.trim();
      } else {
        if (copy.querySelector('*:not(br)')) return null;
        b[key] = inlineToMarks(copy).trim();
      }
    }
    return b;
  }

  // <div class="columns"> holding exactly two <div>s of paragraphs and simple lists.
  function parseColumns(n) {
    var kids = elementChildren(n);
    if (!kids || kids.length !== 2 || !isTag(kids[0], 'DIV') || !isTag(kids[1], 'DIV')) return null;
    var left = parseRich(kids[0]), right = parseRich(kids[1]);
    return left === null || right === null ? null : { type: 'columns', left: left, right: right };
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
      var tag = n.tagName, img, found;
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
      } else if (tag === 'DETAILS' && !n.attributes.length && (found = parseDetails(n))) {
        blocks.push(found);
      } else if (isTag(n, 'SECTION', 'hero') && (found = parseHero(n))) {
        blocks.push(found);
      } else if (isTag(n, 'SECTION', 'features') && (found = parseFeatures(n))) {
        blocks.push(found);
      } else if (isTag(n, 'SECTION', 'cta') && (found = parseCta(n))) {
        blocks.push(found);
      } else if (isTag(n, 'SECTION', 'contact-details') && (found = parseContact(n))) {
        blocks.push(found);
      } else if (isTag(n, 'DIV', 'columns') && (found = parseColumns(n))) {
        blocks.push(found);
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

  // Answer text to HTML: blank lines separate paragraphs; lines starting "- " make a bulleted list, "1. " a numbered one.
  function answerToHtml(text) {
    return text.split(/\n\s*\n/).map(function (chunk) {
      var lines = chunk.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
      if (!lines.length) return '';
      if (lines.every(function (l) { return /^- /.test(l); })) return '<ul>' + lines.map(function (l) { return '<li>' + marksToHtml(l.slice(2).trim()) + '</li>'; }).join('') + '</ul>';
      if (lines.every(function (l) { return /^\d+\. /.test(l); })) return '<ol>' + lines.map(function (l) { return '<li>' + marksToHtml(l.replace(/^\d+\. /, '').trim()) + '</li>'; }).join('') + '</ol>';
      return '<p>' + marksToHtml(lines.join('\n')) + '</p>';
    }).filter(Boolean).join('');
  }

  // One or two button links; a button without a label is left out.
  function buttonsHtml(buttons) {
    var links = [];
    buttons.forEach(function (x) {
      if (!x || x.label.trim() === '') return;
      links.push('<a class="' + (links.length ? 'button button-outline' : 'button') + '" href="' + escapeHtml(safeUrl(x.url || '')) + '">' + escapeHtml(x.label.trim()) + '</a>');
    });
    return links.length ? '<p class="buttons">' + links.join(' ') + '</p>' : '';
  }

  // The language of the page being edited, from its address: pages named after the site's second
  // language ("es" or "es-...") are in that language. window.fcLanguages comes from editor_scripts().
  function pageLang() {
    var slug = document.getElementById('slug');
    var v = slug ? slug.value.trim() : '';
    var langs = window.fcLanguages || { primary: 'en', second: 'es' };
    return langs.second && (v === langs.second || v.indexOf(langs.second + '-') === 0) ? langs.second : langs.primary;
  }

  function contactLabel(b, key) {
    return b.labels && b.labels[key] !== undefined ? b.labels[key] : (CONTACT_LABELS[pageLang()] || CONTACT_LABELS.en)[key];
  }

  function sectionHtml(className, parts, tag) {
    var inner = parts.filter(Boolean);
    return inner.length ? '<' + (tag || 'section') + ' class="' + className + '">\n' + inner.join('\n') + '\n</' + (tag || 'section') + '>' : '';
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
        case 'faq':
          if (b.question.trim() === '' && b.answer.trim() === '') return '';
          return '<details>\n<summary>' + marksToHtml(b.question.trim()) + '</summary>\n' + answerToHtml(b.answer) + '\n</details>';
        case 'image':
          if (!b.src.trim()) return '';
          var img = '<img src="' + escapeHtml(b.src.trim()) + '" alt="' + (b.decorative ? '' : escapeHtml(b.alt.trim())) + '">';
          return b.caption.trim() ? '<figure>' + img + '<figcaption>' + marksToHtml(b.caption.trim()) + '</figcaption></figure>' : '<p>' + img + '</p>';
        case 'hero':
          return sectionHtml('hero', [
            b.heading.trim() !== '' ? '<' + b.level + '>' + marksToHtml(b.heading.trim()) + '</' + b.level + '>' : '',
            b.lede.trim() !== '' ? '<p class="lede">' + marksToHtml(b.lede.trim()) + '</p>' : '',
            buttonsHtml(b.buttons)]);
        case 'features':
          var lis = b.items.filter(function (it) { return it.title.trim() !== '' || it.text.trim() !== ''; }).map(function (it) {
            return '<li><h3>' + marksToHtml(it.title.trim()) + '</h3>' + (it.text.trim() !== '' ? '<p>' + marksToHtml(it.text.trim()) + '</p>' : '') + '</li>';
          });
          if (!lis.length) return '';
          return sectionHtml('features', [b.heading.trim() !== '' ? '<h2>' + marksToHtml(b.heading.trim()) + '</h2>' : '', '<ul class="feature-list">\n' + lis.join('\n') + '\n</ul>']);
        case 'cta':
          return sectionHtml('cta', [
            b.heading.trim() !== '' ? '<h2>' + marksToHtml(b.heading.trim()) + '</h2>' : '',
            b.text.trim() !== '' ? '<p>' + marksToHtml(b.text.trim()) + '</p>' : '',
            buttonsHtml([b.button])]);
        case 'contact':
          var lines = [];
          CONTACT_KEYS.forEach(function (key) {
            var v = (b[key] || '').trim();
            if (v === '') return;
            var label = contactLabel(b, key);
            var body = key === 'phone' ? '<a href="tel:' + escapeHtml(v.replace(/[^0-9+]/g, '')) + '">' + escapeHtml(v) + '</a>'
              : key === 'email' ? '<a href="mailto:' + escapeHtml(v) + '">' + escapeHtml(v) + '</a>'
              : v.split('\n').map(function (l) { return escapeHtml(l.trim()); }).filter(Boolean).join('<br>');
            lines.push('<li class="contact-' + key + '">' + (label !== '' ? '<span class="contact-label">' + escapeHtml(label) + '</span> ' : '') + body + '</li>');
          });
          if (!lines.length) return '';
          return sectionHtml('contact-details', [b.heading.trim() !== '' ? '<h2>' + marksToHtml(b.heading.trim()) + '</h2>' : '', '<ul>\n' + lines.join('\n') + '\n</ul>']);
        case 'columns':
          if (b.left.trim() === '' && b.right.trim() === '') return '';
          return sectionHtml('columns', ['<div>\n' + answerToHtml(b.left) + '\n</div>', '<div>\n' + answerToHtml(b.right) + '\n</div>'], 'div');
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

  // A one-line text field for a section block. opts: hint, spellcheck.
  function lineField(i, name, label, get, set, opts) {
    opts = opts || {};
    var id = fieldId(i, name);
    var attrs = { type: 'text', id: id };
    if (opts.spellcheck === false) attrs.spellcheck = 'false';
    if (opts.hint) attrs['aria-describedby'] = id + '-hint';
    var input = el('input', attrs);
    input.value = get();
    input.addEventListener('input', function () { set(input.value); sync(); });
    return el('div', { class: 'field' }, [el('label', { for: id, text: label }), opts.hint ? el('p', { class: 'hint', id: id + '-hint', text: opts.hint }) : null, input]);
  }

  // A multi-line text field for a section block, with the formatting buttons unless opts.marks is false.
  function areaField(i, name, label, rows, get, set, opts) {
    opts = opts || {};
    var id = fieldId(i, name);
    var ta = el('textarea', { id: id, rows: rows, 'aria-describedby': opts.hint ? id + '-hint' : 'blocks-help' });
    ta.value = get();
    ta.addEventListener('input', function () { set(ta.value); sync(); });
    return el('div', { class: 'field' }, [el('label', { for: id, text: label }), opts.hint ? el('p', { class: 'hint', id: id + '-hint', text: opts.hint }) : null,
      opts.marks === false ? null : markButtons(ta), ta]);
  }

  // Label and address fields for one button. btn is the object holding label and url; number names it ("Button 1") when there are two.
  function buttonFields(i, btn, name, number) {
    var prefix = number ? 'Button ' + number + ' ' : 'Button ';
    var frag = document.createDocumentFragment();
    frag.appendChild(lineField(i, name + '-label', prefix + 'label', function () { return btn.label; }, function (v) { btn.label = v; },
      { hint: number ? 'Leave the label empty to leave this button out.' : 'The words on the button, for example “Book a visit”.' }));
    frag.appendChild(lineField(i, name + '-url', prefix + 'address', function () { return btn.url; }, function (v) { btn.url = v; }, { spellcheck: false, hint: 'For example /contact/ or https://example.com' }));
    return frag;
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
    } else if (b.type === 'faq') {
      var qId = fieldId(i, 'text');
      var q = el('input', { type: 'text', id: qId });
      q.value = b.question;
      q.addEventListener('input', function () { b.question = q.value; sync(); });
      section.appendChild(el('div', { class: 'field' }, [el('label', { for: qId, text: 'Question' }), q]));
      var aId = fieldId(i, 'answer');
      var a = el('textarea', { id: aId, rows: 6, 'aria-describedby': aId + '-hint' });
      a.value = b.answer;
      a.addEventListener('input', function () { b.answer = a.value; sync(); });
      section.appendChild(el('div', { class: 'field' }, [el('label', { for: aId, text: 'Answer' }),
        el('p', { class: 'hint', id: aId + '-hint', text: 'A blank line starts a new paragraph. Start lines with "- " for a bulleted list or "1. " for a numbered list.' }),
        markButtons(a), a]));
    } else if (b.type === 'image') {
      section.appendChild(imageFields(i, b));
    } else if (b.type === 'hero') {
      section.appendChild(heroFields(i, b));
    } else if (b.type === 'features') {
      section.appendChild(featureFields(i, b));
    } else if (b.type === 'cta') {
      section.appendChild(lineField(i, 'text', 'Heading (a few words)', function () { return b.heading; }, function (v) { b.heading = v; }));
      section.appendChild(areaField(i, 'cta-text', 'One sentence', 2, function () { return b.text; }, function (v) { b.text = v; }));
      section.appendChild(buttonFields(i, b.button, 'btn'));
    } else if (b.type === 'contact') {
      section.appendChild(contactFields(i, b));
    } else if (b.type === 'columns') {
      var colHint = 'A blank line starts a new paragraph. Start lines with "- " for a bulleted list or "1. " for a numbered list.';
      section.appendChild(areaField(i, 'text', 'Column 1', 6, function () { return b.left; }, function (v) { b.left = v; }, { hint: colHint }));
      section.appendChild(areaField(i, 'col2', 'Column 2', 6, function () { return b.right; }, function (v) { b.right = v; }, { hint: colHint }));
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

  function heroFields(i, b) {
    var box = document.createDocumentFragment();
    var lvlId = fieldId(i, 'level');
    var lvl = el('select', { id: lvlId, 'aria-describedby': lvlId + '-hint' });
    [['h2', 'Heading 2 (the page title is the main heading)'], ['h1', 'Heading 1 (only when “Don’t show the page title as a heading” is ticked)']].forEach(function (o) {
      var opt = el('option', { value: o[0], text: o[1] });
      if (o[0] === b.level) opt.selected = true;
      lvl.appendChild(opt);
    });
    lvl.addEventListener('change', function () { b.level = lvl.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: lvlId, text: 'Heading level' }),
      el('p', { class: 'hint', id: lvlId + '-hint', text: 'Every page needs exactly one heading level 1. On the home page that is usually this hero heading.' }), lvl]));
    box.appendChild(lineField(i, 'text', 'Heading', function () { return b.heading; }, function (v) { b.heading = v; }));
    box.appendChild(areaField(i, 'lede', 'Opening paragraph (shown larger)', 3, function () { return b.lede; }, function (v) { b.lede = v; }));
    box.appendChild(buttonFields(i, b.buttons[0], 'btn0', 1));
    box.appendChild(buttonFields(i, b.buttons[1], 'btn1', 2));
    return box;
  }

  function featureFields(i, b) {
    var box = document.createDocumentFragment();
    box.appendChild(lineField(i, 'text', 'Heading (optional)', function () { return b.heading; }, function (v) { b.heading = v; }));
    var list = el('fieldset', { class: 'block-items' }, [el('legend', { text: b.items.length === 1 ? 'Items (1)' : 'Items (' + b.items.length + ')' })]);
    b.items.forEach(function (it, k) {
      var item = el('fieldset', { class: 'block-item' }, [el('legend', { text: 'Item ' + (k + 1) + ' of ' + b.items.length })]);
      item.appendChild(lineField(i, 'item' + k + '-title', 'Title', function () { return it.title; }, function (v) { it.title = v; }));
      item.appendChild(areaField(i, 'item' + k + '-text', 'Description', 3, function () { return it.text; }, function (v) { it.text = v; }));
      var remove = el('button', { type: 'button', class: 'button button-small button-outline', text: 'Remove item ' + (k + 1) });
      remove.addEventListener('click', function () {
        if ((it.title + it.text).trim() !== '' && !window.confirm('Remove item ' + (k + 1) + ' (' + (it.title.trim() || 'no title') + ')?')) return;
        b.items.splice(k, 1);
        var next = b.items.length ? 'item' + Math.min(k, b.items.length - 1) + '-title' : 'add-item';
        render(i, next);
        announce('Item ' + (k + 1) + ' removed. ' + (b.items.length === 1 ? '1 item left.' : b.items.length + ' items left.'));
      });
      item.appendChild(remove);
      list.appendChild(item);
    });
    box.appendChild(list);
    var add = el('button', { type: 'button', class: 'button button-small button-outline', id: fieldId(i, 'add-item'), text: 'Add item' });
    add.addEventListener('click', function () {
      b.items.push({ title: '', text: '' });
      render(i, 'item' + (b.items.length - 1) + '-title');
      announce('Item ' + b.items.length + ' added. Type its title.');
    });
    box.appendChild(el('div', { class: 'block-actions' }, [add]));
    return box;
  }

  function contactFields(i, b) {
    var box = document.createDocumentFragment();
    box.appendChild(lineField(i, 'text', 'Heading (optional)', function () { return b.heading; }, function (v) { b.heading = v; }));
    box.appendChild(el('p', { class: 'hint', text: 'Fill in the details you want to show. Empty ones are left out.' }));
    box.appendChild(lineField(i, 'phone', 'Phone number', function () { return b.phone; }, function (v) { b.phone = v; }, { spellcheck: false, hint: 'Shown as a link that phones can dial.' }));
    box.appendChild(lineField(i, 'email', 'Email address', function () { return b.email; }, function (v) { b.email = v; }, { spellcheck: false }));
    box.appendChild(areaField(i, 'address', 'Address (one line per line)', 3, function () { return b.address; }, function (v) { b.address = v; }, { marks: false }));
    box.appendChild(areaField(i, 'hours', 'Hours', 2, function () { return b.hours; }, function (v) { b.hours = v; }, { marks: false, hint: 'For example: Monday to Friday, 9 am to 5 pm' }));
    return box;
  }

  function imageFields(i, b) {
    var box = document.createDocumentFragment();
    var srcId = fieldId(i, 'src'), altId = fieldId(i, 'alt'), decId = fieldId(i, 'dec'), capId = fieldId(i, 'cap'), fileId = fieldId(i, 'file');
    var alt = el('input', { type: 'text', id: altId });
    var src = el('input', { type: 'text', id: srcId, spellcheck: 'false' });
    src.value = b.src;
    src.addEventListener('input', function () { b.src = src.value; sync(); });
    src.addEventListener('change', function () { prefillAlt(b, alt); });
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
    alt.value = b.alt;
    alt.disabled = !!b.decorative;
    alt.addEventListener('input', function () { b.alt = alt.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: altId, text: 'Alt text: what the picture shows, in a sentence' }), alt, suggestButton(i, b, alt)]));
    var dec = el('input', { type: 'checkbox', id: decId });
    dec.checked = !!b.decorative;
    dec.addEventListener('change', function () { b.decorative = dec.checked; alt.disabled = dec.checked; sync(); });
    box.appendChild(el('div', { class: 'field checkbox' }, [dec, el('label', { for: decId, text: 'Decorative image (adds nothing a reader needs; screen readers skip it)' })]));
    var cap = el('input', { type: 'text', id: capId });
    cap.value = b.caption;
    cap.addEventListener('input', function () { b.caption = cap.value; sync(); });
    box.appendChild(el('div', { class: 'field' }, [el('label', { for: capId, text: 'Caption (optional, shown under the picture)' }), cap]));
    prefillAlt(b, alt);
    return box;
  }

  /* ---------- Alt text help from Claude ---------- */

  // A description saved on the Images and files screen fills an empty Alt text field.
  function prefillAlt(b, altInput) {
    var known = state.altText.known[(b.src || '').trim()];
    if (!known || b.decorative || b.alt.trim() !== '') return;
    b.alt = known;
    altInput.value = known;
    sync();
    announce('Alt text filled in from Images and files: ' + known + ' You can change it.');
  }

  // "Suggest a description": hidden until the alt text feature says it is available.
  function suggestButton(i, b, altInput) {
    var wrapper = el('div', { class: 'block-actions alt-suggest' });
    var btn = el('button', { type: 'button', class: 'button button-small button-outline', id: fieldId(i, 'suggest'), text: 'Suggest a description' });
    var out = el('p', { class: 'hint', id: fieldId(i, 'suggest-out') });
    if (!state.altText.available) wrapper.hidden = true;
    state.altButtons.push(wrapper);
    btn.addEventListener('click', function () {
      if (!b.src.trim()) { announce('Add an image address first.'); document.getElementById(fieldId(i, 'src')).focus(); return; }
      if (b.decorative) { announce('This image is marked decorative, so it has no alt text. Untick Decorative image first.'); return; }
      btn.disabled = true;
      out.textContent = '';
      announce('Asking Claude to describe the image. This takes a few seconds.');
      fetch('/admin/?a=alt_text', { method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': state.csrf, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ url: b.src.trim(), lang: pageLang() }) })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.alt) throw new Error(j.error || 'No description came back. Try again in a moment.'); return j; }); })
        .then(function (j) {
          b.alt = j.alt;
          altInput.value = j.alt;
          sync();
          out.textContent = j.description ? 'Longer description, if you want more detail: ' + j.description : '';
          announce('Suggested alt text: ' + j.alt + ' It is in the Alt text field; change it if it is not right.');
          altInput.focus();
        })
        .catch(function (e) { out.textContent = e.message; announce(e.message); })
        .then(function () { btn.disabled = false; });
    });
    wrapper.appendChild(btn);
    wrapper.appendChild(out);
    return wrapper;
  }

  // Ask the alt text feature whether it can help (it answers 404 or available: false when it can't).
  function loadAltText() {
    if (!window.fetch) return;
    fetch('/admin/?a=alt_text', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { available: false }; })
      .then(function (j) {
        state.altText = { loaded: true, available: !!j.available, known: j.known || {} };
        if (!state.root) return;
        if (state.altText.available) state.altButtons.forEach(function (w) { w.hidden = false; });
        state.blocks.forEach(function (b, i) {
          var input = b.type === 'image' ? document.getElementById(fieldId(i, 'alt')) : null;
          if (input) prefillAlt(b, input);
        });
      })
      .catch(function () { state.altText.loaded = true; });
  }

  /* ---------- Changing, adding, moving and deleting blocks ---------- */

  function render(focusIndex, focusWhat) {
    var list = state.root.querySelector('.block-list');
    list.innerHTML = '';
    state.altButtons = [];
    state.blocks.forEach(function (b, i) { list.appendChild(renderBlock(b, i)); });
    sync();
    if (focusIndex !== undefined) {
      var target = document.getElementById(fieldId(Math.min(focusIndex, state.blocks.length - 1), focusWhat || 'type'));
      if (target) target.focus();
    }
  }

  // All the words in a block, for converting it to another type and for the delete confirmation.
  function blockText(b) {
    var keep = function (s) { return s && s.trim(); };
    switch (b.type) {
      case 'faq': return (b.question + '\n\n' + b.answer).trim();
      case 'ul': case 'ol': return b.items.join('\n');
      case 'image': return b.caption || '';
      case 'html': return b.html.replace(/<[^>]+>/g, '').trim();
      case 'hero': return [b.heading, b.lede].concat(b.buttons.map(function (x) { return x.label; })).filter(keep).join('\n\n');
      case 'features': return [b.heading].concat(b.items.map(function (it) { return (it.title + '\n' + it.text).trim(); })).filter(keep).join('\n\n');
      case 'cta': return [b.heading, b.text, b.button.label].filter(keep).join('\n\n');
      case 'contact': return [b.heading, b.phone, b.email, b.address, b.hours].filter(keep).join('\n');
      case 'columns': return (b.left + '\n\n' + b.right).trim();
      default: return b.text || '';
    }
  }

  function hasContent(b) {
    return blockText(b).trim() !== '' || !!(b.src && b.src.trim());
  }

  function changeType(i, type) {
    var b = state.blocks[i], text = blockText(b);
    var isHeading = b.type === 'h2' || b.type === 'h3' || b.type === 'h4';
    var nb;
    if (type === 'ul' || type === 'ol') nb = { type: type, items: text.split('\n') };
    else if (type === 'image') nb = { type: 'image', src: '', alt: '', decorative: false, caption: '' };
    else if (type === 'faq') nb = { type: 'faq', question: isHeading ? text : '', answer: isHeading ? '' : text };
    else if (type === 'hero') nb = { type: 'hero', level: 'h2', heading: isHeading ? text : '', lede: isHeading ? '' : text, buttons: [{ label: '', url: '' }, { label: '', url: '' }] };
    else if (type === 'features') nb = { type: 'features', heading: isHeading ? text : '', items: [{ title: '', text: isHeading ? '' : text }] };
    else if (type === 'cta') nb = { type: 'cta', heading: isHeading ? text : '', text: isHeading ? '' : text, button: { label: '', url: '' } };
    else if (type === 'contact') nb = { type: 'contact', heading: isHeading ? text : '', phone: '', email: '', address: '', hours: '', labels: {} };
    else if (type === 'columns') nb = { type: 'columns', left: text, right: '' };
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
    if (hasContent(b) && !window.confirm('Delete block ' + (i + 1) + ' (' + TYPES[b.type].toLowerCase() + ')? This can’t be undone.')) return;
    state.blocks.splice(i, 1);
    if (!state.blocks.length) state.blocks.push({ type: 'p', text: '' });
    render(Math.min(i, state.blocks.length - 1), 'type');
    announce(TYPES[b.type] + ' deleted. ' + state.blocks.length + ' blocks left.');
  }

  /* ---------- Start and stop ---------- */

  function init(textarea) {
    state.textarea = textarea;
    state.form = textarea.form;
    var meta = document.querySelector('meta[name="csrf-token"]');
    var hidden = state.form && state.form.querySelector('input[name="csrf"]');
    state.csrf = meta ? meta.content : (hidden ? hidden.value : '');
    state.blocks = parse(textarea.value);

    var root = el('div', { class: 'blocks-editor', role: 'region', 'aria-label': 'Content blocks' });
    root.appendChild(el('p', { id: 'blocks-help', class: 'hint', html:
      'Each block is a form field. Use the Block type list to make it a heading, list, quote, question and answer or image, ' +
      'or a whole section: a hero (the opening of a page: heading, opening paragraph and up to two buttons), a features or services list, ' +
      'a call to action, contact details or two columns. The buttons under each block add, move or delete it. ' +
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
    if (!state.altText.loaded) loadAltText();

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
