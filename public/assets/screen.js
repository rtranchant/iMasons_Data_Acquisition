// Big screen: polls the event and renders the live slide. Rebuilds the layout only
// when the slide changes; otherwise updates numbers, dots and statements in place.
(function () {
  'use strict';
  var code = window.EVENT_CODE;
  var stage = document.getElementById('stage');
  var POLL_MS = 1500, ROTATE_MS = 6000, WALL_SWAP_MS = 5000;
  var layoutKey = null, ui = {}, timers = [];

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function add(parent) { for (var i = 1; i < arguments.length; i++) if (arguments[i]) parent.appendChild(arguments[i]); return parent; }
  function clearTimers() { timers.forEach(clearInterval); timers = []; }
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }

  function qrBlock(state) {
    var q = el('div', 'qr');
    try {
      var qr = qrcode(0, 'M');  // bundled qrcode-generator (MIT), no network needed
      qr.addData(state.join_url);
      qr.make();
      q.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 0, scalable: true });
    } catch (e) { q.textContent = 'QR unavailable'; q.style.color = '#010203'; }
    var t = el('div', 'qrtext');
    t.appendChild(document.createTextNode('Scan to take part, or go to '));
    t.appendChild(el('b', null, state.join_url.replace(/^https?:\/\//, '')));
    return add(el('div', 'qrblock'), q, t);
  }

  // ---------------------------------------------------------------- layouts

  function build(state) {
    var s = state.slide;
    if (s && s.kind === 'image') return buildImage(s);
    clearTimers();
    document.body.classList.remove('fullbleed');
    stage.innerHTML = '';
    ui = {};
    if (!s) {
      add(stage, add(el('div', 'hold'),
        add(el('div'), el('div', 'kicker', 'Welcome'), el('h1', null, state.event), el('p', null, 'Scan the code to join on your phone and keep the page open.')),
        qrBlock(state)));
      return;
    }
    if (s.kind === 'collect') return buildCollect(state, s);
    if (s.kind === 'results') return buildResults(s);
    if (s.kind === 'wall') return buildWall(s);
  }

  // Uploaded slide: swap in only once the image is decoded, so there is no black flash.
  function buildImage(s) {
    var key = layoutKey, img = new Image();
    img.className = 'slideimg';
    img.alt = s.title || '';
    img.src = s.image;
    (img.decode ? img.decode() : Promise.resolve()).catch(function () {}).then(function () {
      if (layoutKey !== key) return; // moved on meanwhile
      clearTimers();
      ui = {};
      stage.innerHTML = '';
      document.body.classList.add('fullbleed');
      stage.appendChild(img);
    });
  }

  function buildCollect(state, s) {
    add(stage, el('h1', 'title', s.title));
    var live = el('div', 'live');
    ui.closed = el('div', 'closed-tag', 'Closed');
    add(live, ui.closed);
    if (s.module === 'm4') add(live, el('div', 'pledge', s.pledge));
    if (s.module === 'm3') add(live, el('div', 'intro', s.intro));
    if (s.module === 'm1') {
      ui.num = el('b', null, '0');
      add(live, add(el('div', 'bignum'), ui.num, el('span', null, 'statements answered by the room')));
    }
    ui.dots = el('div', 'dots');
    ui.legend = el('div', 'legend');
    add(live, add(el('div', 'dotsbox'), ui.dots, ui.legend));
    if (s.module === 'm2') {
      ui.rq = el('q');
      add(live, add(el('div', 'rotator'), el('div', 'prompt', s.prompt), ui.rq));
      ui.rot = { list: [], seen: {}, queue: [], i: -1 };
      timers.push(setInterval(rotate, ROTATE_MS));
    }
    add(stage, add(el('div', 'collect'), qrBlock(state), live));
    ui.prevDots = { done: 0, watching: 0 };
  }

  function buildResults(s) {
    add(stage, el('h1', 'title', s.title));
    ui.cols = {};
    var wrap = el('div', 'results');
    [['yes', 'Top Yes'], ['no', 'Top No'], ['revise', 'Needs revision']].forEach(function (c) {
      var count = el('small');
      var col = add(el('div', 'col ' + c[0]), add(el('h2', null, c[1] + ' '), count), el('div', 'items'));
      ui.cols[c[0]] = { items: col.querySelector('.items'), count: count, sig: '' };
      add(wrap, col);
    });
    ui.foot = el('div', 'footnote');
    add(stage, wrap, ui.foot);
  }

  function buildWall(s) {
    add(stage, el('div', 'kicker', s.prompt || ''), el('h1', 'title', s.title));
    ui.wall = el('div', 'wall');
    add(stage, ui.wall);
    // Capacity depends on the space left on screen.
    var w = ui.wall.clientWidth, h = ui.wall.clientHeight;
    var cols = w > 1500 ? 4 : (w > 900 ? 3 : 2);
    var rows = Math.max(2, Math.min(5, Math.round(h / 170)));
    ui.wall.style.setProperty('--cols', cols);
    ui.wall.style.gridTemplateRows = 'repeat(' + rows + ', minmax(0, 1fr))';
    ui.slots = [];
    for (var i = 0; i < cols * rows; i++) {
      var card = el('div', 'card fade'), p = el('p');
      card.appendChild(p);
      ui.wall.appendChild(card);
      ui.slots.push({ id: null, card: card, p: p });
    }
    ui.texts = {}; ui.everShown = {};
    timers.push(setInterval(swapOne, WALL_SWAP_MS));
  }

  // ---------------------------------------------------------------- updates

  function update(state) {
    var s = state.slide;
    if (!s || s.kind === 'image') return;
    if (ui.closed) ui.closed.style.display = s.is_open ? 'none' : '';
    if (s.dots) drawDots(s.dots, s.module);
    if (ui.num) ui.num.textContent = String(s.total_answers || 0);
    if (ui.rot) feedRotator(s.statements || []);
    if (ui.cols) drawResults(s);
    if (ui.slots) feedWall(s.statements || []);
  }

  function drawDots(d, module) {
    var prev = ui.prevDots;
    if (prev.done === d.done && prev.watching === d.watching && ui.dots.childNodes.length) return;
    var total = Math.max(d.done + d.watching, 1);
    var w = ui.dots.clientWidth, h = ui.dots.clientHeight, size = 64, gap;
    for (; size > 6; size -= 2) {
      gap = Math.max(3, Math.round(size * 0.3));
      var cols = Math.floor((w + gap) / (size + gap)), rows = Math.floor((h + gap) / (size + gap));
      if (cols * rows >= total) break;
    }
    ui.dots.style.setProperty('--size', size + 'px');
    ui.dots.style.setProperty('--gap', gap + 'px');
    ui.dots.innerHTML = '';
    for (var i = 0; i < d.done; i++) ui.dots.appendChild(el('span', 'dot' + (i >= prev.done ? ' new' : '')));
    for (var j = 0; j < d.watching; j++) ui.dots.appendChild(el('span', 'dot watch'));
    var noun = { m1: 'answering', m2: 'contributed', m3: 'asked for the materials', m4: 'committed' }[module];
    ui.legend.innerHTML = '';
    var a = el('span'); a.appendChild(el('i')); a.appendChild(document.createTextNode(d.done + ' ' + noun));
    add(ui.legend, a);
    if (d.watching) { var b = el('span'); b.appendChild(el('i', 'watch')); b.appendChild(document.createTextNode(d.watching + ' on the page')); add(ui.legend, b); }
    ui.prevDots = { done: d.done, watching: d.watching };
  }

  // Module 2 collect: show one statement at a time, newest unseen first.
  function feedRotator(list) {
    var r = ui.rot;
    r.list = list;
    list.forEach(function (st) { if (!r.seen[st.id]) { r.seen[st.id] = true; r.queue.push(st); } });
    if (!ui.rq.textContent && list.length) rotate();
  }
  function rotate() {
    var r = ui.rot;
    if (!r || !r.list.length) { if (ui.rq) ui.rq.textContent = ''; return; }
    var next = r.queue.length ? r.queue.shift() : r.list[(r.i = (r.i + 1) % r.list.length)];
    ui.rq.classList.add('fade');
    setTimeout(function () { ui.rq.textContent = next.text; ui.rq.classList.remove('fade'); }, 800);
  }

  function drawResults(s) {
    ['yes', 'no', 'revise'].forEach(function (k) {
      var c = ui.cols[k], items = s[k] || [];
      var sig = JSON.stringify(items);
      if (sig === c.sig) return;
      c.sig = sig;
      c.count.textContent = items.length ? String(items.length) : '';
      c.items.innerHTML = '';
      if (!items.length) { c.items.appendChild(el('div', 'empty', k === 'revise' ? 'None so far' : 'Nothing decided yet')); return; }
      items.forEach(function (it) {
        var bar = el('div', 'split');
        [['y', it.yes], ['n', it.no], ['d', it.dk]].forEach(function (p) { var i = el('i', p[0]); i.style.width = (100 * p[1] / it.n) + '%'; bar.appendChild(i); });
        var pct = function (x) { return Math.round(100 * x / it.n) + '%'; };
        add(c.items, add(el('div', 'item'), el('p', null, it.text), bar,
          el('div', 'meta', 'Yes ' + pct(it.yes) + ' · No ' + pct(it.no) + ' · Don’t know ' + pct(it.dk) + ' · ' + it.n + ' answers')));
      });
      // Drop what doesn't fit and say how many more there are.
      var hidden = 0;
      while (c.items.scrollHeight > c.items.clientHeight + 2 && c.items.children.length > 1) { c.items.removeChild(c.items.lastChild); hidden++; }
      if (hidden) {
        c.items.removeChild(c.items.lastChild); hidden++;
        c.items.appendChild(el('div', 'meta', '+ ' + hidden + ' more'));
      }
    });
    ui.foot.textContent = s.pending ? s.pending + ' statement' + (s.pending === 1 ? ' is' : 's are') + ' still collecting answers.' : '';
  }

  // Module 2 wall: fill empty cards, then swap one card at a time with a fade.
  function feedWall(list) {
    var ids = {};
    list.forEach(function (st) { ui.texts[st.id] = st.text; ids[st.id] = true; });
    ui.slots.forEach(function (sl) { if (sl.id !== null && !ids[sl.id]) setSlot(sl, null); });  // un-approved meanwhile
    ui.slots.forEach(function (sl) { if (sl.id === null) { var n = pickUnshown(list); if (n) setSlot(sl, n); } });
  }
  function onWall() { var m = {}; ui.slots.forEach(function (s) { if (s.id !== null) m[s.id] = true; }); return m; }
  function pickUnshown(list) {
    var on = onWall();
    var fresh = list.filter(function (s) { return !on[s.id] && !ui.everShown[s.id]; });
    if (fresh.length) return fresh[0];
    var rest = list.filter(function (s) { return !on[s.id]; });
    return rest.length ? rest[Math.floor(Math.random() * rest.length)] : null;
  }
  function setSlot(sl, st) {
    sl.card.classList.add('fade');
    setTimeout(function () {
      sl.id = st ? st.id : null;
      sl.p.textContent = st ? st.text : '';
      if (st) { ui.everShown[st.id] = true; sl.card.classList.remove('fade'); }
    }, sl.id === null ? 0 : 1000);
    if (st) sl.id = st.id; // reserve immediately so it isn't picked twice
  }
  function swapOne() {
    if (!ui.slots) return;
    var list = Object.keys(ui.texts).map(function (id) { return { id: +id, text: ui.texts[id] }; });
    var filled = ui.slots.filter(function (s) { return s.id !== null; });
    if (filled.length < ui.slots.length) return;
    var next = pickUnshown(list);
    if (!next) return;
    var sl = shuffle(filled.slice())[0];
    sl.card.classList.add('fade');
    var target = sl;
    setTimeout(function () { target.id = next.id; target.p.textContent = next.text; ui.everShown[next.id] = true; target.card.classList.remove('fade'); }, 1000);
  }

  // ---------------------------------------------------------------- loop

  var offline = document.getElementById('offline'), shownRaw = null, preloaded = {};
  function poll() {
    var url = 'api.php?a=screen&c=' + encodeURIComponent(code) + (shownRaw !== null ? '&wait=' + shownRaw : '');
    var failed = false;
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (state) {
        offline.hidden = true;
        shownRaw = state.raw_slide_id;
        var key = state.slide ? state.slide.id + ':' + state.slide.kind : 'hold';
        if (key !== layoutKey) { layoutKey = key; build(state); }
        update(state);
        if (state.preload && !preloaded[state.preload]) { var im = new Image(); im.src = state.preload; preloaded[state.preload] = im; }
      })
      .catch(function () { offline.hidden = false; failed = true; })
      // The server holds the request while nothing changes, so poll again straight away (back off on errors).
      .then(function () { setTimeout(poll, failed ? POLL_MS * 2 : 50); });
  }
  poll();

  // Laptop control: click or → / ↓ / PageDown / Space = next, ← / ↑ / PageUp = previous.
  // Works when the host is logged in in this browser; otherwise a hint explains how.
  var chain = Promise.resolve(), hintTimer;
  function hint(html) {
    var h = document.getElementById('hint');
    if (!h) { h = document.createElement('div'); h.id = 'hint'; h.className = 'hint'; document.body.appendChild(h); }
    h.innerHTML = html;
    h.hidden = false;
    clearTimeout(hintTimer);
    hintTimer = setTimeout(function () { h.hidden = true; }, 5000);
  }
  function step(action) {
    if (!window.CSRF) {
      hint('To move the slides from this computer, <a href="login.php?next=' +
        encodeURIComponent(location.pathname + location.search) + '">log in as admin</a> in this browser first.');
      return;
    }
    chain = chain.then(function () {
      return fetch('api.php?a=' + action + '&c=' + encodeURIComponent(code), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF }, body: '{}'
      }).then(function (r) {
        if (r.status === 401) { window.CSRF = null; step(action); }
      });
    }).catch(function () { hint('Could not reach the server – try again.'); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    var action = { ArrowRight: 'next', ArrowDown: 'next', PageDown: 'next', ' ': 'next', Enter: 'next',
                   ArrowLeft: 'prev', ArrowUp: 'prev', PageUp: 'prev', Backspace: 'prev' }[e.key];
    if (!action) return;
    e.preventDefault();
    step(action);
  });
  document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.target.closest('#fs, #hint, a, button')) return;
    step('next');
  });

  // Rebuild on resize so the dots and wall fit the new size.
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { layoutKey = null; }, 300); });

  document.getElementById('fs').addEventListener('click', function () {
    var d = document.documentElement;
    (d.requestFullscreen || d.webkitRequestFullscreen || function () {}).call(d);
  });
})();
