(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    // Toggle grup opsi field berdasarkan tipe field (content-types/fields)
    var typeSelect = document.getElementById('field_type');
    if (typeSelect) {
      var toggle = function () {
        var t = typeSelect.value;
        document.querySelectorAll('.opt-group').forEach(function (el) {
          var forTypes = (el.dataset.for || '').split(',');
          el.style.display = forTypes.indexOf(t) !== -1 ? '' : 'none';
        });
      };
      typeSelect.addEventListener('change', toggle);
      toggle();
    }

    // Auto-generate field key dari label (sampai user mengedit manual)
    var labelInput = document.getElementById('field_label');
    var keyInput = document.getElementById('field_key');
    if (labelInput && keyInput) {
      var manual = false;
      keyInput.addEventListener('input', function () { manual = true; });
      labelInput.addEventListener('input', function () {
        if (!manual) {
          keyInput.value = labelInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[0-9]+/, 'field_');
        }
      });
    }

    // Toggle kotak restriksi content type di form API key
    var allTypes = document.getElementById('all_types');
    if (allTypes) {
      var box = document.getElementById('restriction_box');
      var sync = function () { if (box) box.style.display = allTypes.checked ? 'none' : ''; };
      allTypes.addEventListener('change', sync);
      sync();
    }

    // Konfirmasi submit form dengan data-confirm
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (!window.confirm(form.dataset.confirm || 'Yakin?')) {
          e.preventDefault();
        }
      });
    });

    // Copy URL (pustaka media)
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.dataset.copy || '';
        var done = function () {
          var old = btn.textContent;
          btn.textContent = 'Tersalin!';
          setTimeout(function () { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
          var ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    });

    // ===== Bulk entri: pilih semua & konfirmasi =====
    var checkAll = document.querySelector('[data-check-all]');
    if (checkAll) {
      checkAll.addEventListener('change', function () {
        document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
          cb.checked = checkAll.checked;
        });
      });
    }

    document.querySelectorAll('button[data-confirm]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (!window.confirm(btn.dataset.confirm || 'Yakin?')) {
          e.preventDefault();
        }
      });
    });

    var bulkForm = document.querySelector('form[data-bulk]');
    if (bulkForm) {
      bulkForm.addEventListener('submit', function (e) {
        var checked = bulkForm.querySelectorAll('input[name="ids[]"]:checked');
        if (checked.length === 0) {
          e.preventDefault();
          window.alert('Pilih minimal satu entri.');
          return;
        }
        var select = bulkForm.querySelector('select[name="bulk_action"]');
        var action = select ? select.value : '';
        if (!action) {
          e.preventDefault();
          window.alert('Pilih aksi massal terlebih dahulu.');
          return;
        }
        var message = '';
        if (action === 'delete') {
          message = 'Hapus permanen ' + checked.length + ' entri? Tindakan ini tidak bisa dibatalkan.';
        } else if (action === 'trash') {
          message = 'Buang ' + checked.length + ' entri ke Trash?';
        }
        if (message && !window.confirm(message)) {
          e.preventDefault();
        }
      });
    }

    // ===== Quick Edit: expand/collapse baris entri =====
    function setQuickEdit(rowId, open) {
      var row = document.getElementById(rowId);
      if (!row || !row.classList.contains('quick-edit-row')) {
        return;
      }
      row.hidden = !open;
      document.querySelectorAll('[data-quick-edit-toggle="' + rowId + '"]').forEach(function (btn) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (open) {
        var first = row.querySelector('input:not([type="hidden"]), select');
        if (first) {
          first.focus({ preventScroll: true });
          if (row.scrollIntoView) {
            row.scrollIntoView({ block: 'nearest' });
          }
        }
      }
    }

    document.addEventListener('click', function (e) {
      var toggle = e.target.closest('[data-quick-edit-toggle]');
      if (toggle) {
        var rowId = toggle.dataset.quickEditToggle || '';
        var row = document.getElementById(rowId);
        setQuickEdit(rowId, !row || row.hidden);
        return;
      }
      var close = e.target.closest('[data-quick-edit-close]');
      if (close) {
        setQuickEdit(close.dataset.quickEditClose || '', false);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') {
        return;
      }
      document.querySelectorAll('.quick-edit-row:not([hidden])').forEach(function (row) {
        setQuickEdit(row.id, false);
      });
    });

    // Setelah simpan/gagal simpan, redirect membawa #entry-N → buka baris itu.
    if (/^#entry-\d+$/.test(window.location.hash)) {
      setQuickEdit(window.location.hash.slice(1), true);
    }

    // ===== Sidebar hide/show (mobile-friendly) =====
    var sidebar = document.getElementById('sidebar');
    var toggleBtn = document.getElementById('sidebar-toggle');
    var backdrop = document.getElementById('sidebar-backdrop');
    var collapseBtn = document.getElementById('sidebar-collapse');

    function isMobile() {
      return window.matchMedia('(max-width: 767px)').matches;
    }

    if (sidebar) {
      function closeSidebar() {
        document.body.classList.remove('sidebar-open');
        if (toggleBtn) {
          toggleBtn.setAttribute('aria-expanded', 'false');
          toggleBtn.textContent = '☰';
        }
      }

      // Mobile: off-canvas open/close
      if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
          var open = document.body.classList.toggle('sidebar-open');
          toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
          toggleBtn.textContent = open ? '✕' : '☰';
        });
      }
      if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
      }
      // Tutup otomatis setelah klik link menu (mobile)
      sidebar.addEventListener('click', function (e) {
        if (isMobile() && e.target.closest('a[href]')) {
          closeSidebar();
        }
      });

      // Desktop: collapse/hide penuh, preferensi disimpan di localStorage
      if (collapseBtn) {
        try {
          if (localStorage.getItem('rcm_sidebar_hidden') === '1') {
            document.body.classList.add('sidebar-hidden');
            collapseBtn.textContent = '»';
          }
        } catch (e) {}
        collapseBtn.addEventListener('click', function () {
          var hidden = document.body.classList.toggle('sidebar-hidden');
          collapseBtn.textContent = hidden ? '»' : '«';
          try {
            localStorage.setItem('rcm_sidebar_hidden', hidden ? '1' : '0');
          } catch (e) {}
        });
      }

      // ===== Submenu expand/collapse =====
      var menuStateKey = 'rcm_menu_state';
      var menuState = {};
      try { menuState = JSON.parse(localStorage.getItem(menuStateKey) || '{}'); } catch (e) {}
      var saveMenuState = function () {
        try { localStorage.setItem(menuStateKey, JSON.stringify(menuState)); } catch (e) {}
      };

      document.querySelectorAll('.menu-item.has-children').forEach(function (li) {
        var key = li.dataset.menuKey || '';
        var isCurrent = li.classList.contains('active') || !!li.querySelector('.submenu-item.active');
        var toggle = li.querySelector('.menu-toggle');

        var collapsed;
        if (isCurrent) {
          collapsed = false;
        } else if (menuState[key] === '1') {
          collapsed = true;
        } else if (menuState[key] === '0') {
          collapsed = false;
        } else {
          collapsed = true; // default: bagian non-aktif tertutup
        }
        li.classList.toggle('collapsed', collapsed);
        if (toggle) {
          toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
          toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var isCollapsed = li.classList.toggle('collapsed');
            menuState[key] = isCollapsed ? '1' : '0';
            saveMenuState();
            toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
          });
        }
      });
    }
  });
})();
