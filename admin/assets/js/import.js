/**
 * Auto-lanjut import WordPress: submit batch berikutnya selama opsi aktif.
 * Dipisah ke file sendiri agar tetap patuh CSP (script-src 'self').
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-auto-import]');
  if (!form) {
    return;
  }
  var auto = form.querySelector('input[type="checkbox"][name="auto"]');
  if (!auto || !auto.checked) {
    return;
  }
  window.setTimeout(function () {
    form.submit();
  }, 500);
})();
