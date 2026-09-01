/*!
 * Ringan CMS — Editor Richtext WYSIWYG (vanilla JS, tanpa dependency).
 * Mengubah <textarea class="richtext"> menjadi editor WYSIWYG ringan.
 * HTML disinkronkan kembali ke textarea; konten tetap disanitasi server.
 * Tag yang didukung toolbar sesuai whitelist sanitize_richtext().
 */
(function () {
  'use strict';

  var ALLOWED_TAGS = ['P','BR','STRONG','B','EM','I','U','S','UL','OL','LI','BLOCKQUOTE','PRE','CODE','H2','H3','H4','H5','H6','A','IMG','TABLE','THEAD','TBODY','TR','TH','TD','FIGURE','FIGCAPTION','SPAN','HR','DIV'];

  function cleanPaste(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var frag = document.createDocumentFragment();
    Array.from(doc.body.childNodes).forEach(function (node) {
      frag.appendChild(cleanNode(node));
    });
    var out = document.createElement('div');
    out.appendChild(frag);
    return out.innerHTML;
  }

  function cleanNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      return document.createTextNode(node.nodeValue || '');
    }
    var tag = node.nodeName.toUpperCase();
    if (ALLOWED_TAGS.indexOf(tag) === -1) {
      var frag = document.createDocumentFragment();
      Array.from(node.childNodes).forEach(function (c) {
        frag.appendChild(cleanNode(c));
      });
      return frag;
    }
    var el = document.createElement(node.nodeName);
    if (node.attributes) {
      Array.from(node.attributes).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        var value = attr.value.trim();
        if (name === 'href' || name === 'src') {
          if (/^(javascript|vbscript|data|file)\s*:/i.test(value)) return;
          el.setAttribute(attr.name, value);
        }
      });
    }
    Array.from(node.childNodes).forEach(function (c) {
      el.appendChild(cleanNode(c));
    });
    return el;
  }

  function init(textarea) {
    if (textarea.dataset.rteReady) return;
    textarea.dataset.rteReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'rte-wrap';
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(textarea);

    var toolbar = document.createElement('div');
    toolbar.className = 'rte-toolbar';
    wrap.appendChild(toolbar);

    var editor = document.createElement('div');
    editor.className = 'rte-editor';
    editor.setAttribute('contenteditable', 'true');
    editor.innerHTML = textarea.value || '';
    wrap.appendChild(editor);

    var groups = [
      [
        { cmd: 'bold', html: '<b>B</b>', title: 'Tebal' },
        { cmd: 'italic', html: '<i>I</i>', title: 'Miring' },
        { cmd: 'underline', html: '<u>U</u>', title: 'Garis bawah' },
        { cmd: 'strikeThrough', html: '<s>S</s>', title: 'Coret' }
      ],
      [
        { cmd: 'formatBlock', value: '<h2>', html: 'H2', title: 'Heading 2' },
        { cmd: 'formatBlock', value: '<h3>', html: 'H3', title: 'Heading 3' },
        { cmd: 'formatBlock', value: '<h4>', html: 'H4', title: 'Heading 4' },
        { cmd: 'formatBlock', value: '<p>', html: '¶', title: 'Paragraf' },
        { cmd: 'formatBlock', value: '<blockquote>', html: '❝', title: 'Kutipan' },
        { cmd: 'formatBlock', value: '<pre>', html: '&lt;/&gt;', title: 'Kode' }
      ],
      [
        { cmd: 'insertUnorderedList', html: 'UL', title: 'Daftar bullet' },
        { cmd: 'insertOrderedList', html: 'OL', title: 'Daftar angka' }
      ],
      [
        { cmd: 'link', html: 'Link', title: 'Sisipkan tautan' },
        { cmd: 'unlink', html: 'Unlink', title: 'Hapus tautan' }
      ],
      [
        { cmd: 'insertHorizontalRule', html: '—', title: 'Garis horizontal' },
        { cmd: 'undo', html: '↶', title: 'Undo' },
        { cmd: 'redo', html: '↷', title: 'Redo' },
        { cmd: 'source', html: 'HTML', title: 'Mode HTML' }
      ]
    ];

    groups.forEach(function (group, gi) {
      if (gi > 0) {
        var sep = document.createElement('span');
        sep.className = 'rte-sep';
        toolbar.appendChild(sep);
      }
      group.forEach(function (btnDef) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.cmd = btnDef.cmd;
        if (btnDef.value) btn.dataset.value = btnDef.value;
        btn.title = btnDef.title || '';
        btn.innerHTML = btnDef.html;
        toolbar.appendChild(btn);
      });
    });

    function exec(cmd, value) {
      editor.focus();
      document.execCommand(cmd, false, value);
      sync();
    }

    function insertLink() {
      var sel = window.getSelection();
      var text = sel ? sel.toString() : '';
      if (!text) {
        alert('Pilih teks terlebih dahulu, lalu klik Link.');
        return;
      }
      var url = window.prompt('URL tautan (contoh: https://example.com atau /halaman):');
      if (!url) return;
      url = url.trim();
      if (/^(javascript|vbscript|data|file)\s*:/i.test(url)) {
        alert('URL tidak diizinkan.');
        return;
      }
      exec('createLink', url);
    }

    function toggleSource() {
      var on = wrap.classList.toggle('rte-source');
      var btn = toolbar.querySelector('[data-cmd="source"]');
      if (btn) btn.classList.toggle('active', on);
      if (on) {
        textarea.value = editor.innerHTML;
        textarea.focus();
      } else {
        editor.innerHTML = textarea.value || '';
        editor.focus();
      }
    }

    function sync() {
      textarea.value = editor.innerHTML;
    }

    function updateToolbar() {
      ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].forEach(function (cmd) {
        var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
        if (btn) btn.classList.toggle('active', document.queryCommandState(cmd));
      });
    }

    toolbar.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-cmd]');
      if (!btn) return;
      var cmd = btn.dataset.cmd;
      if (cmd === 'source') { toggleSource(); return; }
      if (cmd === 'link') { insertLink(); return; }
      exec(cmd, btn.dataset.value);
      updateToolbar();
    });

    editor.addEventListener('input', sync);
    editor.addEventListener('blur', sync);
    editor.addEventListener('keyup', updateToolbar);
    editor.addEventListener('mouseup', updateToolbar);

    editor.addEventListener('paste', function (e) {
      e.preventDefault();
      var cb = e.clipboardData || window.clipboardData;
      var html = cb ? cb.getData('text/html') : '';
      var text = cb ? cb.getData('text/plain') : '';
      if (html) {
        document.execCommand('insertHTML', false, cleanPaste(html));
      } else if (text) {
        document.execCommand('insertText', false, text);
      }
      sync();
    });

    var form = textarea.form;
    if (form) {
      form.addEventListener('submit', function () { sync(); });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('textarea.richtext').forEach(init);
  });
})();
