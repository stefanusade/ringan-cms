/**
 * Ringan Export — progres ekspor bertahap via AJAX.
 */
(function () {
  'use strict';

  var cfg = window.RinganExport;
  var form = document.getElementById('ringan-export-form');
  if (!cfg || !form) {
    return;
  }

  var startBtn = document.getElementById('ringan-export-start');
  var cancelBtn = document.getElementById('ringan-export-cancel');
  var progressWrap = document.getElementById('ringan-export-progress');
  var progressBar = document.getElementById('ringan-export-bar');
  var statusEl = document.getElementById('ringan-export-status');
  var logEl = document.getElementById('ringan-export-log');
  var downloadWrap = document.getElementById('ringan-export-download');
  var downloadLink = document.getElementById('ringan-export-link');
  var sizeEl = document.getElementById('ringan-export-size');

  var token = '';
  var running = false;

  function log(message, kind) {
    if (!logEl) {
      return;
    }
    var line = document.createElement('div');
    if (kind) {
      line.className = 'ringan-export-log-' + kind;
    }
    var time = new Date().toLocaleTimeString();
    line.textContent = '[' + time + '] ' + message;
    logEl.appendChild(line);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function setProgress(offset, total) {
    if (progressBar) {
      progressBar.max = Math.max(1, total);
      progressBar.value = Math.min(offset, total);
    }
  }

  function fail(message) {
    running = false;
    setStatus(message);
    log(message, 'error');
    if (startBtn) {
      startBtn.disabled = false;
    }
    if (cancelBtn) {
      cancelBtn.hidden = true;
    }
    token = '';
  }

  function request(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (key) {
      var value = data[key];
      if (Array.isArray(value)) {
        // PHP hanya menerima array bila field dikirim sebagai name[]=a&name[]=b.
        value.forEach(function (item) {
          body.append(key + '[]', String(item));
        });
        return;
      }
      body.set(key, value);
    });
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (json) {
        if (!json || !json.success) {
          var message = json && json.data && json.data.message ? json.data.message : cfg.strings.failed;
          throw new Error(message);
        }
        return json.data;
      });
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (running) {
      return;
    }

    var postTypes = Array.prototype.slice
      .call(form.querySelectorAll('input[name="post_types[]"]:checked'))
      .map(function (input) { return input.value; });
    var statuses = Array.prototype.slice
      .call(form.querySelectorAll('input[name="post_statuses[]"]:checked'))
      .map(function (input) { return input.value; });

    if (postTypes.length === 0) {
      window.alert('Pilih minimal satu post type.');
      return;
    }
    if (statuses.length === 0) {
      window.alert('Pilih minimal satu status post.');
      return;
    }

    running = true;
    token = '';
    if (startBtn) {
      startBtn.disabled = true;
    }
    if (progressWrap) {
      progressWrap.hidden = false;
    }
    if (downloadWrap) {
      downloadWrap.hidden = true;
    }
    if (logEl) {
      logEl.textContent = '';
    }
    setProgress(0, 1);
    setStatus(cfg.strings.preparing);
    log('Memulai ekspor…');

    var total = 0;
    var offset = 0;
    var stalls = 0;

    request('ringan_export_prepare', { post_types: postTypes, post_statuses: statuses })
      .then(function (data) {
        token = data.token;
        total = Number(data.total) || 0;
        setProgress(0, total);
        if (cancelBtn) {
          cancelBtn.hidden = false;
        }
        log('Ditemukan ' + total + ' post untuk diekspor.', 'info');
        return step();
      })
      .catch(function (error) {
        fail(error.message);
      });

    function step() {
      if (!token) {
        return Promise.resolve();
      }
      return request('ringan_export_batch', { token: token }).then(function (data) {
        var nextOffset = Number(data.offset) || 0;
        setProgress(nextOffset, Number(data.total) || total);
        setStatus(
          cfg.strings.processing
            .replace('%1$d', String(nextOffset))
            .replace('%2$d', String(Number(data.total) || total))
        );

        if (nextOffset <= offset && !data.finished) {
          stalls += 1;
          if (stalls >= 3) {
            throw new Error(cfg.strings.stalled);
          }
        } else {
          stalls = 0;
        }
        offset = nextOffset;

        if (data.finished) {
          return finalize();
        }
        return step();
      });
    }

    function finalize() {
      setStatus(cfg.strings.finalizing);
      log('Menyusun ZIP dan menambahkan media…', 'info');
      return request('ringan_export_finalize', { token: token }).then(function (data) {
        running = false;
        token = '';
        if (cancelBtn) {
          cancelBtn.hidden = true;
        }
        if (downloadLink) {
          downloadLink.href = data.download_url;
        }
        if (sizeEl) {
          sizeEl.textContent = ' (' + data.size_label + ')';
        }
        if (downloadWrap) {
          downloadWrap.hidden = false;
        }
        setStatus(cfg.strings.done);
        log(cfg.strings.done, 'ok');
        var stats = data.stats || {};
        log('Entry: ' + (Number(stats.entries) || 0) + ' · media: ' + (Number(stats.media) || 0) + '.', 'info');
        (stats.warnings || []).slice(0, 10).forEach(function (warning) {
          log('Peringatan: ' + warning, 'warn');
        });
        if (startBtn) {
          startBtn.disabled = false;
        }
      });
    }
  });

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      if (!token) {
        return;
      }
      if (!window.confirm(cfg.strings.confirmCancel)) {
        return;
      }
      var current = token;
      request('ringan_export_cancel', { token: current }).then(function () {
        token = '';
        fail('Ekspor dibatalkan.');
      }).catch(function (error) {
        fail(error.message);
      });
    });
  }

  window.addEventListener('beforeunload', function (event) {
    if (!running) {
      return;
    }
    event.preventDefault();
    event.returnValue = '';
    return '';
  });
})();