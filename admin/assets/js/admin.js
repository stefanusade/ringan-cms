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
