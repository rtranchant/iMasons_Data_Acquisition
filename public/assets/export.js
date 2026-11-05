// Builds the slides PDF: one 1920×1080 picture per page, taken in this browser.
(function () {
  'use strict';
  var X = window.EXPORT, W = 1920, H = 1080;
  var go = document.getElementById('go'), status = document.getElementById('status'), prog = document.getElementById('prog');
  var frame = document.getElementById('frame'), done = document.getElementById('done'), stageBox = frame.parentNode;

  function fitPreview() { frame.style.transform = 'scale(' + (stageBox.clientWidth / W) + ')'; }
  window.addEventListener('resize', fitPreview); fitPreview();

  function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

  // An uploaded slide: draw the image centred on black, as the big screen shows it.
  function imagePage(src) {
    return new Promise(function (ok, fail) {
      var im = new Image();
      im.onload = function () {
        var c = document.createElement('canvas'); c.width = W; c.height = H;
        var g = c.getContext('2d'); g.fillStyle = '#010203'; g.fillRect(0, 0, W, H);
        var k = Math.min(W / im.naturalWidth, H / im.naturalHeight), w = im.naturalWidth * k, h = im.naturalHeight * k;
        g.drawImage(im, (W - w) / 2, (H - h) / 2, w, h);
        ok(c.toDataURL('image/jpeg', 0.9));
      };
      im.onerror = function () { fail(new Error('could not load ' + src)); };
      im.src = src;
    });
  }

  // A data page: open the big screen for that one slide, wait until it says it is ready, take the picture.
  function screenPage(p) {
    return new Promise(function (ok, fail) {
      var started = Date.now();
      frame.onload = function () {
        (function check() {
          var w = frame.contentWindow;
          if (w && w.__ready && w.htmlToImage) {
            var node = w.document.getElementById('screen');
            w.htmlToImage.toJpeg(node, { width: W, height: H, quality: 0.9, pixelRatio: 1, backgroundColor: '#010203' }).then(ok, fail);
          } else if (Date.now() - started > 30000) {
            fail(new Error('the page did not finish loading'));
          } else setTimeout(check, 200);
        })();
      };
      frame.src = 'screen.php?c=' + encodeURIComponent(X.code) + '&preview=' + p.slide + (p.tab ? '&tab=' + p.tab : '') + '&t=' + Date.now();
    });
  }

  go.addEventListener('click', function () {
    go.disabled = true; done.hidden = true;
    var pdf = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'px', format: [W, H], hotfixes: ['px_scaling'], compress: true });
    var failed = [];
    var chain = Promise.resolve();
    X.pages.forEach(function (p, i) {
      chain = chain.then(function () {
        status.textContent = 'Page ' + (i + 1) + ' of ' + X.pages.length + ': ' + p.label;
        return (p.image ? imagePage(p.image) : screenPage(p)).catch(function (e) { failed.push(p.label + ' (' + e.message + ')'); return null; });
      }).then(function (jpg) {
        if (i > 0) pdf.addPage([W, H], 'landscape');
        if (jpg) pdf.addImage(jpg, 'JPEG', 0, 0, W, H, undefined, 'FAST');
        else { pdf.setFontSize(48); pdf.text('Could not capture: ' + p.label, 80, 160); }
        prog.style.width = (100 * (i + 1) / X.pages.length) + '%';
        return wait(50);
      });
    });
    chain.then(function () {
      pdf.save(X.file);
      status.textContent = 'Done: ' + X.file + ' has been downloaded.';
      if (failed.length) { done.hidden = false; done.textContent = 'Pages that could not be captured: ' + failed.join('; '); }
      frame.src = 'about:blank';
      go.disabled = false;
    });
  });
})();
