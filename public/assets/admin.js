// Admin event page: slide upload (PDF rendered in the browser, or images) and drag-to-reorder.
(function () {
  'use strict';
  var TARGET_W = 1920, MAX_H = 1440, THUMB_W = 320, MAX_BYTES = 1.8 * 1024 * 1024;

  // pdf.js (1.5 MB) is only loaded when a PDF is actually uploaded.
  var pdfReady = null;
  function loadPdfJs() {
    if (!pdfReady) {
      pdfReady = new Promise(function (resolve, reject) {
        var sc = document.createElement('script');
        sc.src = 'assets/vendor/pdf.min.js';
        sc.onload = function () { pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js'; resolve(window.pdfjsLib); };
        sc.onerror = function () { pdfReady = null; reject(new Error('Could not load the PDF reader – check the connection.')); };
        document.head.appendChild(sc);
      });
    }
    return pdfReady;
  }

  function post(url, body, isForm) {
    var opts = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': window.CSRF } };
    if (isForm) opts.body = body;
    else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected server response (' + r.status + ')' }; })
        .then(function (j) { if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status)); return j; });
    });
  }

  function toBlob(canvas, quality) {
    return new Promise(function (res) { canvas.toBlob(res, 'image/jpeg', quality); });
  }

  // JPEG at the best quality that stays under the per-page limit.
  function encode(canvas) {
    var qs = [0.9, 0.82, 0.72, 0.6];
    function tryQ(i) {
      return toBlob(canvas, qs[i]).then(function (b) { return (b.size <= MAX_BYTES || i === qs.length - 1) ? b : tryQ(i + 1); });
    }
    return tryQ(0);
  }

  function thumbOf(canvas) {
    var t = document.createElement('canvas');
    t.width = THUMB_W;
    t.height = Math.round(canvas.height * THUMB_W / canvas.width);
    t.getContext('2d').drawImage(canvas, 0, 0, t.width, t.height);
    return toBlob(t, 0.75);
  }

  function canvasFor(w, h) {
    var scale = Math.min(TARGET_W / w, MAX_H / h);
    var c = document.createElement('canvas');
    c.width = Math.round(w * scale);
    c.height = Math.round(h * scale);
    return { canvas: c, scale: scale };
  }

  // Returns {count, render(i) -> Promise<canvas>} for a PDF or a set of image files.
  function openSource(files) {
    var pdf = files.filter(function (f) { return f.type === 'application/pdf' || /\.pdf$/i.test(f.name); });
    if (pdf.length) {
      if (files.length > 1) return Promise.reject(new Error('Upload one PDF at a time.'));
      return Promise.all([pdf[0].arrayBuffer(), loadPdfJs()]).then(function (r) { return pdfjsLib.getDocument({ data: r[0] }).promise; }).then(function (doc) {
        return {
          count: doc.numPages,
          render: function (i) {
            return doc.getPage(i + 1).then(function (page) {
              var base = page.getViewport({ scale: 1 });
              var cv = canvasFor(base.width, base.height);
              var ctx = cv.canvas.getContext('2d');
              ctx.fillStyle = '#ffffff';
              ctx.fillRect(0, 0, cv.canvas.width, cv.canvas.height);
              return page.render({ canvasContext: ctx, viewport: page.getViewport({ scale: cv.scale }) }).promise
                .then(function () { return cv.canvas; });
            });
          }
        };
      });
    }
    var imgs = files.filter(function (f) { return /^image\/(png|jpeg)$/.test(f.type); });
    if (!imgs.length) return Promise.reject(new Error('Choose a PDF, or PNG/JPG images.'));
    // PowerPoint exports Slide1.png … Slide10.png: sort numerically.
    imgs.sort(function (a, b) { return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }); });
    return Promise.resolve({
      count: imgs.length,
      render: function (i) {
        return createImageBitmap(imgs[i]).then(function (bmp) {
          var cv = canvasFor(bmp.width, bmp.height);
          cv.canvas.getContext('2d').drawImage(bmp, 0, 0, cv.canvas.width, cv.canvas.height);
          return cv.canvas;
        });
      }
    });
  }

  function uploadPage(deck, i, canvas) {
    return Promise.all([encode(canvas), thumbOf(canvas)]).then(function (blobs) {
      function attempt(n) {
        var fd = new FormData();
        fd.append('deck_id', deck.deck_id);
        fd.append('batch', deck.batch);
        fd.append('page_no', i + 1);
        fd.append('image', blobs[0], 'page' + (i + 1) + '.jpg');
        fd.append('thumb', blobs[1], 'thumb' + (i + 1) + '.jpg');
        return post('upload.php?do=page', fd, true).catch(function (e) {
          if (n >= 3) throw e;
          return new Promise(function (r) { setTimeout(r, 1000 * n); }).then(function () { return attempt(n + 1); });
        });
      }
      return attempt(1);
    });
  }

  function makeStatus(el) {
    el.innerHTML = '';
    var text = document.createElement('div');
    var bar = document.createElement('div');
    bar.className = 'progress';
    var fill = document.createElement('i');
    bar.appendChild(fill);
    el.appendChild(text);
    el.appendChild(bar);
    return {
      set: function (msg, frac) { text.textContent = msg; if (frac !== undefined) fill.style.width = Math.round(frac * 100) + '%'; },
      fail: function (msg) { el.innerHTML = ''; var e = document.createElement('div'); e.className = 'error'; e.textContent = msg; el.appendChild(e); }
    };
  }

  function uploadDeck(opts) {
    var st = makeStatus(opts.statusEl), source, deck;
    st.set('Reading file…', 0);
    return openSource(opts.files)
      .then(function (src) {
        source = src;
        if (source.count > 300) throw new Error('That has ' + source.count + ' pages; the limit is 300.');
        return post('upload.php?do=start', { event_id: opts.eventId, name: opts.name, replace_deck_id: opts.replaceDeckId || null });
      })
      .then(function (d) {
        deck = d;
        var i = 0;
        function next() {
          if (i >= source.count) return null;
          st.set('Preparing and uploading slide ' + (i + 1) + ' of ' + source.count + '…', i / source.count);
          return source.render(i).then(function (canvas) { return uploadPage(deck, i, canvas); })
            .then(function () { i++; return next(); });
        }
        return next();
      })
      .then(function () {
        st.set('Adding to the run of show…', 1);
        return post('upload.php?do=finish', { deck_id: deck.deck_id, batch: deck.batch, after_slide_id: opts.after });
      })
      .then(function (r) {
        st.set(r.pages + ' slide' + (r.pages === 1 ? '' : 's') + ' uploaded.', 1);
        setTimeout(function () { location.hash = 'ros'; location.reload(); }, 600);
      })
      .catch(function (e) { st.fail('Upload failed: ' + e.message); });
  }

  // New upload
  var box = document.getElementById('uploader');
  if (box) {
    var go = document.getElementById('up-go');
    go.addEventListener('click', function () {
      var files = Array.prototype.slice.call(document.getElementById('up-file').files);
      if (!files.length) { makeStatus(document.getElementById('up-status')).fail('Choose a file first.'); return; }
      var name = document.getElementById('up-name').value.trim() || files[0].name.replace(/\.[^.]+$/, '');
      var after = document.getElementById('up-after').value;
      go.disabled = true;
      uploadDeck({ files: files, name: name, after: after === '' ? null : +after, eventId: +box.dataset.event,
        statusEl: document.getElementById('up-status') }).then(function () { go.disabled = false; });
    });
  }

  // Revised version of an existing deck
  Array.prototype.forEach.call(document.querySelectorAll('.replace-file'), function (input) {
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files);
      if (!files.length) return;
      var deckId = +input.dataset.deck;
      uploadDeck({ files: files, replaceDeckId: deckId, eventId: +box.dataset.event,
        statusEl: document.getElementById('rp-status-' + deckId) });
    });
  });

  // Drag-to-reorder the run of show
  var list = document.getElementById('ros-list');
  if (list && window.Sortable) {
    Sortable.create(list, {
      handle: '.handle', animation: 150, ghostClass: 'sortable-ghost',
      onEnd: function () {
        var rows = list.querySelectorAll('.row[data-id]');
        var fd = new FormData();
        fd.append('csrf', list.dataset.csrf);
        fd.append('do', 'reorder');
        fd.append('event_id', list.dataset.event);
        fd.append('ajax', '1');
        Array.prototype.forEach.call(rows, function (r, i) {
          fd.append('ids[]', r.dataset.id);
          var n = r.querySelector('.num');
          if (n) n.textContent = String(i + 1);
        });
        fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error(); })
          .catch(function () { alert('Could not save the new order. The page will reload.'); location.reload(); });
      }
    });
  }
})();
