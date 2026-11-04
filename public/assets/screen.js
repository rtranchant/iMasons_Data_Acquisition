// Big screen: polls the event and renders the live slide. Rebuilds the layout only
// when the slide changes; otherwise updates numbers, dots and statements in place.
(function () {
  'use strict';
  var code = window.EVENT_CODE;
  var root = document.getElementById('screen');
  var POLL_MS = 1500, WALL_SWAP_MS = 6000, ANSWER_MIN_MS = 10000, FADE_MS = 800, LAYER_FADE_MS = 250;
  var WALL_SIZE = 8;
  var layoutKey = null, ui = {}, timers = [], stage = null, currentLayer = null, shownIsImage = false;

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

  // ---------------------------------------------------------------- layers (smooth page changes)
  // Each page is built in a new layer on top of the current one and faded in; the old layer is then removed.

  function newLayer(eventName, withHeader, light) {
    var layer = el('div', 'layer' + (light ? ' light' : ''));
    if (withHeader) {
      add(layer, el('div', 'stripe'), add(el('header', 'top'), el('span', 'brand', 'iMasons'), el('span', 'event', eventName)));
    }
    var content = el('main', 'content' + (withHeader ? '' : ' bare'));
    layer.appendChild(content);
    root.appendChild(layer);
    return { layer: layer, content: content };
  }

  // cut = true: show at once (slide to slide, like PowerPoint). Otherwise a short fade.
  function reveal(L, cut) {
    var old = currentLayer;
    currentLayer = L.layer;
    if (cut) {
      L.layer.classList.add('cut', 'in');
      if (old && old.parentNode) old.parentNode.removeChild(old);
      return;
    }
    requestAnimationFrame(function () { requestAnimationFrame(function () { L.layer.classList.add('in'); }); });
    if (old) setTimeout(function () { if (old.parentNode) old.parentNode.removeChild(old); }, LAYER_FADE_MS + 50);
  }

  // ---------------------------------------------------------------- layouts

  function build(state) {
    var s = state.slide;
    if (s && s.kind === 'image') return buildImage(s.image, state.event);
    clearTimers();
    ui = {};
    shownIsImage = false;
    var L = newLayer(state.event, true, s && s.module === 'm5');
    stage = L.content;
    if (s && s.module === 'm5') {
      if (s.kind === 'collect') buildQfLive(state, s); else buildQfResults(s);
    } else if (!s) {
      add(stage, add(el('div', 'hold'),
        add(el('div'), el('div', 'kicker', 'Welcome'), el('h1', null, state.event), el('p', null, 'Scan the code to join on your phone and keep the page open.')),
        qrBlock(state)));
    } else if (s.kind === 'collect') {
      if (s.module === 'm2') buildAnswers(state, s); else buildCollect(state, s);
    } else if (s.kind === 'results') {
      buildResults(s);
    } else if (s.kind === 'wall') {
      buildWall(s);
    }
    reveal(L);
  }

  // Uploaded slide: shown once the image is decoded (preloaded ones are ready at once), so there is no flash.
  // From one uploaded slide to the next it is an instant cut; to or from a data collection page, a short fade.
  function buildImage(src, eventName) {
    var key = layoutKey, img = new Image();
    img.className = 'slideimg';
    img.alt = '';
    img.src = src;
    function show() {
      if (layoutKey !== key) return; // moved on meanwhile
      clearTimers();
      ui = {};
      var cut = shownIsImage;
      shownIsImage = true;
      var L = newLayer(eventName, false);
      stage = L.content;
      stage.appendChild(img);
      reveal(L, cut);
    }
    if (img.complete && img.naturalWidth) show();
    else (img.decode ? img.decode() : Promise.resolve()).catch(function () {}).then(show);
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
    add(stage, add(el('div', 'collect'), qrBlock(state), live));
    ui.prevDots = { done: 0, watching: 0 };
  }

  // Text input, live page: prompt on top, then the approved answers one at a time.
  function buildAnswers(state, s) {
    add(stage, el('h1', 'title', s.title));
    var live = el('div', 'live');
    ui.closed = el('div', 'closed-tag', 'Closed');
    ui.answer = el('div', 'answer-text fade');
    ui.answerEmpty = el('div', 'answer-empty', 'Answers will appear here as they come in.');
    ui.legend = el('div', 'legend');
    add(live, ui.closed, el('div', 'prompt-big', s.prompt),
      add(el('div', 'answer-box'), ui.answerEmpty, ui.answer), ui.legend);
    add(stage, add(el('div', 'collect'), qrBlock(state), live));
    ui.rot = { list: [], seen: {}, queue: [], current: null, shownAt: 0, first: true, fading: false };
    timers.push(setInterval(tickAnswers, 500));
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

  // Text input, wall page: 8 answers; one random card at a time is swapped for another answer.
  function buildWall(s) {
    add(stage, el('h1', 'title tight', s.title));
    if (s.prompt) add(stage, el('div', 'subtitle', s.prompt));
    ui.wall = el('div', 'wall');
    add(stage, ui.wall);
    var cols = root.clientWidth >= 900 ? 4 : 2;
    ui.wall.style.setProperty('--cols', cols);
    ui.wall.style.gridTemplateRows = 'repeat(' + (WALL_SIZE / cols) + ', minmax(0, 1fr))';
    ui.slots = [];
    for (var i = 0; i < WALL_SIZE; i++) {
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
    if (s.dots && ui.dots) drawDots(s.dots, s.module);
    if (s.dots && ui.rot) drawCount(s.dots);
    if (ui.num) ui.num.textContent = String(s.total_answers || 0);
    if (ui.rot) feedRotator(s.statements || []);
    if (ui.cols) drawResults(s);
    if (ui.slots) feedWall(s.statements || []);
    if (ui.qfLive && s.live) drawQfLive(s.live, s.min_n);
    if (ui.qfRes && s.results) { ui.qfRes.data = s.results; if (!ui.qfRes.until || Date.now() > ui.qfRes.until) ui.qfRes.tab = s.tab; drawQfResults(); }
  }

  // ---------------------------------------------------------------- module 5: quick-fire pages (white house style)

  var QF_STAGES = ['Awareness', 'Readiness', 'Matching', 'Screening', 'Selection', 'Clearance', 'Start', 'Growth and retention'];
  var QF_SIDES = [['hiring', 'Hiring'], ['candidate', 'Candidates'], ['education', 'Education'], ['other', 'Other']];
  function pct(x) { return x === null || x === undefined ? '–' : Math.round(100 * x) + '%'; }
  function pts(x) { if (x === null || x === undefined) return '–'; var r = Math.round(x); return (r > 0 ? '+' : r < 0 ? '−' : '') + Math.abs(r); }

  // Page A: participation only. No statement text, no ranking.
  function buildQfLive(state, s) {
    add(stage, el('h1', 'title', s.title));
    var right = el('div', 'qf-live');
    ui.qfLive = { sig: '' };
    var people = el('section', 'qf-panel'), sides = el('div', 'qf-bars');
    ui.qfLive.peopleNum = el('b', 'qf-big', '0');
    add(people, el('h2', null, 'People taking part'), ui.qfLive.peopleNum, sides);
    ui.qfLive.sides = {};
    QF_SIDES.forEach(function (sd) {
      var fill = el('i'), count = el('span', 'qf-count', '0');
      add(sides, add(el('div', 'qf-bar-row'), el('span', 'qf-bar-label', sd[1]), add(el('div', 'qf-bar'), fill), count));
      ui.qfLive.sides[sd[0]] = { fill: fill, count: count };
    });
    var answers = el('section', 'qf-panel');
    ui.qfLive.answers = el('b', 'qf-big', '0'); ui.qfLive.lastMin = el('p', 'qf-sub', '');
    add(answers, el('h2', null, 'Answers so far'), ui.qfLive.answers, ui.qfLive.lastMin);
    var cov = el('section', 'qf-panel wide');
    ui.qfLive.covFill = el('i'); ui.qfLive.covText = el('p', 'qf-sub', '');
    add(cov, el('h2', null, 'Coverage'), add(el('div', 'qf-bar big'), ui.qfLive.covFill), ui.qfLive.covText);
    var journey = el('section', 'qf-panel wide'), strip = el('div', 'qf-strip');
    ui.qfLive.stages = {};
    QF_STAGES.forEach(function (st) {
      var fill = el('i'), n = el('b', 'qf-stage-n', '0');
      add(strip, add(el('div', 'qf-stage'), add(el('div', 'qf-stage-fill'), fill), el('span', 'qf-stage-name', st), n));
      ui.qfLive.stages[st] = { fill: fill, n: n };
    });
    add(journey, el('h2', null, 'Answers across the hiring journey (number of answers per stage)'), strip);
    add(right, add(el('div', 'qf-row2'), people, answers), cov, journey);
    add(stage, add(el('div', 'collect'), qrBlock(state), right));
  }
  function drawQfLive(L, minN) {
    var sig = JSON.stringify(L);
    if (sig === ui.qfLive.sig) return;
    ui.qfLive.sig = sig;
    ui.qfLive.peopleNum.textContent = String(L.people);
    var maxSide = Math.max(1, Math.max.apply(null, QF_SIDES.map(function (sd) { return L.by_side[sd[0]] || 0; })));
    QF_SIDES.forEach(function (sd) {
      var n = L.by_side[sd[0]] || 0, u = ui.qfLive.sides[sd[0]];
      u.fill.style.width = (100 * n / maxSide) + '%'; u.count.textContent = String(n);
    });
    ui.qfLive.answers.textContent = String(L.answers);
    ui.qfLive.lastMin.textContent = L.last_minute + ' in the last minute';
    var c = L.active ? L.reached / L.active : 0;
    ui.qfLive.covFill.style.width = (100 * c) + '%';
    ui.qfLive.covText.textContent = Math.round(100 * c) + '% of statements have ' + minN + ' or more answers (' + L.reached + ' of ' + L.active + ')';
    var maxStage = Math.max(1, Math.max.apply(null, QF_STAGES.map(function (st) { return L.stages[st] || 0; })));
    QF_STAGES.forEach(function (st) {
      var n = L.stages[st] || 0, u = ui.qfLive.stages[st];
      u.fill.style.height = (100 * n / maxStage) + '%'; u.n.textContent = String(n);
    });
  }

  // Re-fit the results rows once the brand font has loaded (it changes line heights).
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { if (ui.qfRes) { ui.qfRes.sig = ''; drawQfResults(); } });

  // Page B: four tabs for the panel.
  var QF_TABS = ['Top problems', 'Where it breaks', 'Blind spots', 'Side by side'];
  function buildQfResults(s) {
    ui.qfRes = { tab: s.tab || 1, data: s.results, sig: '' };
    ui.qfRes.head = el('div', 'qf-head');
    ui.qfRes.tabs = el('div', 'qf-tabs');
    ui.qfRes.body = el('div', 'qf-body');
    add(stage, add(el('div', 'qf-titlebar'), el('h1', 'title qf-title', s.title), ui.qfRes.tabs), ui.qfRes.head, ui.qfRes.body);
  }
  function setQfTab(t, send) {
    if (!ui.qfRes || t < 1 || t > 4) return false;
    ui.qfRes.tab = t;
    ui.qfRes.until = Date.now() + 2000;   // don't let an older reply switch it back
    drawQfResults();
    if (send && window.CSRF && currentSlide) {
      fetch('api.php?a=qf_tab&lite=1&c=' + encodeURIComponent(code), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF },
        body: JSON.stringify({ instance_id: currentSlide.instance_id, tab: t })
      }).catch(function () {});
    }
    return true;
  }
  function drawQfResults() {
    var R = ui.qfRes, d = R.data, sig = R.tab + JSON.stringify(d);
    if (sig === R.sig) return;
    R.sig = sig;
    R.tabs.innerHTML = '';
    QF_TABS.forEach(function (t, i) { add(R.tabs, el('span', 'qf-tab' + (R.tab === i + 1 ? ' on' : ''), (i + 1) + ' ' + t)); });
    var when = d.as_of ? new Date(d.as_of.replace(' ', 'T') + 'Z') : null;
    var stamp = when ? when.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'no answers yet';
    var flagged = d.flag_mode === 'exclude' ? d.excluded + ' excluded as straight-liners'
      : d.flag_mode === 'half' ? d.half + ' straight-liners at half weight' : 'straight-liners included';
    R.head.innerHTML = '';
    [d.side_label, d.included + ' people included', flagged, 'Data as of ' + stamp].concat(d.filter ? [d.filter] : [])
      .forEach(function (t, i) { add(R.head, el('span', i === 0 ? 'qf-head-side' : null, t)); });
    R.body.innerHTML = '';
    ({ 1: qfTop, 2: qfStages, 3: qfBlind, 4: qfCompare })[R.tab](d, R.body);
    // Drop rows that don't fit and say how many more there are.
    var list = R.body.querySelector('.qf-list');
    if (list) {
      var hidden = 0;
      while (list.scrollHeight > list.clientHeight + 2 && list.children.length > 1) { list.removeChild(list.lastChild); hidden++; }
      if (hidden) add(R.body, el('p', 'qf-note', '+ ' + hidden + ' more not shown'));
    }
  }
  function nTag(n) { return el('span', 'qf-n' + (n < 10 ? ' low' : ''), 'n ' + n + (n < 10 ? ' · low n' : '')); }
  function qfTop(d, body) {
    if (!d.top.length) { add(body, el('p', 'qf-empty', 'Not enough answers yet: statements appear once they have ' + d.min_n + ' Yes or No answers.')); return; }
    var list = el('div', 'qf-list');
    d.top.forEach(function (r) {
      var bar = add(el('div', 'qf-ybar'), el('i')), mark = el('b', 'qf-mark');
      bar.firstChild.style.width = (100 * r.yes_pct) + '%';
      if (d.room_yes !== null) { mark.style.left = (100 * d.room_yes) + '%'; bar.appendChild(mark); }
      add(list, add(el('div', 'qf-item'), el('p', 'qf-st', r.statement),
        add(el('div', 'qf-meta'), add(el('div', 'qf-ywrap'), bar), el('span', 'qf-pct', pct(r.yes_pct) + ' Yes'), el('span', 'qf-lift', pts(r.lift)), nTag(r.n))));
    });
    add(body, list);
    add(body, el('p', 'qf-note', 'Ranked by lift (+ points above these people\'s usual Yes rate). Black mark: the room\'s average Yes rate, ' + pct(d.room_yes) + '.'));
  }
  function qfStages(d, body) {
    var strip = el('div', 'qf-strip results');
    var lifts = d.stages.filter(function (s) { return s.enough; }).map(function (s) { return Math.max(0, s.lift); });
    var maxL = Math.max(1, Math.max.apply(null, lifts.concat([1])));
    d.stages.forEach(function (s) {
      var b = el('div', 'qf-stage' + (s.enough ? '' : ' none'));
      if (s.enough) b.style.background = 'rgba(196,229,0,' + (0.15 + 0.85 * Math.max(0, s.lift) / maxL).toFixed(2) + ')';
      add(b, el('span', 'qf-stage-name', s.stage), el('span', 'qf-stage-lift', s.enough ? pts(s.lift) : 'not enough answers'));
      add(strip, b);
    });
    add(body, strip);
    var list = el('div', 'qf-list');
    d.stages.forEach(function (s) {
      if (!s.enough) return;
      add(list, add(el('div', 'qf-item'), add(el('p', 'qf-st'), el('b', null, s.stage + ': '), document.createTextNode(s.top.statement)),
        add(el('div', 'qf-meta'), el('span', 'qf-lift', pts(s.top.lift)), nTag(s.top.n))));
    });
    add(body, list);
    add(body, el('p', 'qf-note', 'Each stage shows the average lift of its statements with ' + d.min_n + '+ answers, and its top statement. Stages with fewer than 3 such statements say "not enough answers".'));
  }
  function qfBlind(d, body) {
    if (!d.blind.length) { add(body, el('p', 'qf-empty', 'No "Don\'t know" answers yet on statements with ' + d.min_n + '+ answers.')); return; }
    var list = el('div', 'qf-list');
    d.blind.forEach(function (r) {
      var bar = add(el('div', 'qf-ybar dk'), el('i'));
      bar.firstChild.style.width = (100 * r.dk_share) + '%';
      add(list, add(el('div', 'qf-item'), el('p', 'qf-st', r.statement),
        add(el('div', 'qf-meta dk'), add(el('div', 'qf-ywrap'), bar), el('span', 'qf-pct', pct(r.dk_share) + " Don't know"), el('span', 'qf-n', 'n ' + r.total))));
    });
    add(body, list);
    add(body, el('p', 'qf-note', d.side === 'hiring' ? 'What employers can\'t see from where they sit.' : 'Share of "Don\'t know" among all answers to the statement.'));
  }
  function qfCompare(d, body) {
    var c = d.compare;
    if (!c.ok) {
      add(body, add(el('div', 'qf-refuse'),
        el('p', 'qf-st', 'Not enough candidate-side answers to compare reliably. Showing the hiring-side view only.'),
        el('p', 'qf-sub', 'Hiring side: ' + c.hiring_n + ' people · Candidate side: ' + c.candidate_n + ' people (each side needs at least 8, and statements need ' + d.min_n + '+ answers on both sides).')));
      return;
    }
    var list = el('div', 'qf-list cmp-grid'), maxAbs = 1;
    c.rows.forEach(function (r) { maxAbs = Math.max(maxAbs, Math.abs(r.hiring_lift), Math.abs(r.candidate_lift)); });
    function lbar(v, cls) {
      var b = el('div', 'qf-lbar ' + cls), i = el('i');
      var w = 50 * Math.abs(v) / maxAbs;
      i.style.width = w + '%'; i.style.left = (v >= 0 ? 50 : 50 - w) + '%';
      b.appendChild(i); return b;
    }
    c.rows.forEach(function (r) {
      add(list, add(el('div', 'qf-item cmp'), el('p', 'qf-st', r.statement),
        add(el('div', 'qf-cmp'),
          add(el('div', 'qf-cmp-row'), el('span', 'qf-bar-label', 'Employers'), lbar(r.hiring_lift, 'h'), el('span', 'qf-lift sm', pts(r.hiring_lift)), el('span', 'qf-n', 'n ' + r.hiring_n)),
          add(el('div', 'qf-cmp-row'), el('span', 'qf-bar-label', 'Candidates'), lbar(r.candidate_lift, 'c'), el('span', 'qf-lift sm', pts(r.candidate_lift)), el('span', 'qf-n', 'n ' + r.candidate_n))),
        el('span', 'qf-gap', 'Gap ' + pts(r.gap) + ' · ' + (r.gap > 0 ? 'Candidates see it more' : 'Employers see it more'))));
    });
    add(body, list);
    add(body, el('p', 'qf-note', 'Compared on lift only, never on raw % Yes. Hiring side: ' + c.hiring_n + ' people · candidate side: ' + c.candidate_n + ' people.'));
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

  function drawCount(d) {
    var txt = d.done + ' contribution' + (d.done === 1 ? '' : 's') + (d.watching ? ' · ' + d.watching + ' writing now' : '');
    if (ui.legend.textContent === txt) return;
    ui.legend.innerHTML = '';
    var a = el('span'); a.appendChild(el('i')); a.appendChild(document.createTextNode(txt));
    ui.legend.appendChild(a);
  }

  // Text input, live page: a newly approved answer is shown next, but every answer stays up
  // for at least ANSWER_MIN_MS; with nothing new, cycle through all answers. Fade out, then in.
  function feedRotator(list) {
    var r = ui.rot, ids = {};
    r.list = list;
    list.forEach(function (st) { ids[st.id] = true; });
    if (r.first) {
      r.first = false;
      list.forEach(function (st) { r.seen[st.id] = true; });
      if (list.length) showAnswer(list[list.length - 1]);   // newest first
      return;
    }
    list.forEach(function (st) { if (!r.seen[st.id]) { r.seen[st.id] = true; r.queue.push(st); } });
    r.queue = r.queue.filter(function (st) { return ids[st.id]; });
    if (r.current && !ids[r.current.id]) r.shownAt = 0;   // removed by the host: replace it now
  }
  function tickAnswers() {
    var r = ui.rot;
    if (!r || r.fading || r.first) return;
    var ids = {};
    r.list.forEach(function (st) { ids[st.id] = true; });
    var currentOk = r.current && ids[r.current.id];
    if (!r.list.length) { if (r.current) showAnswer(null); return; }
    if (currentOk && Date.now() - r.shownAt < ANSWER_MIN_MS) return;
    if (r.queue.length) return showAnswer(r.queue.shift());
    if (!currentOk) return showAnswer(r.list[0]);
    if (r.list.length > 1) {
      var i = r.list.findIndex(function (st) { return st.id === r.current.id; });
      showAnswer(r.list[(i + 1) % r.list.length]);
    }
  }
  function showAnswer(st) {
    var r = ui.rot, a = ui.answer;
    r.fading = true;
    var delay = r.current ? FADE_MS : 0;
    a.classList.add('fade');
    setTimeout(function () {
      if (ui.answer !== a) return;   // page changed meanwhile
      r.current = st;
      r.shownAt = Date.now();
      r.fading = false;
      ui.answerEmpty.style.display = st ? 'none' : '';
      if (!st) { a.textContent = ''; return; }
      a.textContent = st.text;
      a.className = 'answer-text fade' + (st.text.length > 140 ? ' long' : st.text.length > 70 ? ' mid' : '');
      void a.offsetWidth;   // restart the transition
      a.classList.remove('fade');
    }, delay);
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

  // Short requests only: ask, get the answer at once, ask again a moment later.
  // Unchanged replies are tiny ({same:true}); errors make it wait longer; a hidden tab asks rarely.
  var POLL_VISIBLE_MS = 400, POLL_HIDDEN_MS = 10000, MAX_BACKOFF_MS = 30000;
  var offline = document.getElementById('offline'), lastHash = '', failures = 0, pollTimer = null, polling = false, again = false;
  var preloaded = {}, nav = [], eventName = document.title.replace(/ – Screen$/, '');
  var currentSlide = null;
  var pending = null;   // {id, until}: a slide this screen switched to itself and is waiting for the server to confirm
  function poll() {
    // A check is already on its way: run another one as soon as it returns, never skip it.
    if (polling) { again = true; return; }
    polling = true;
    again = false;
    clearTimeout(pollTimer);
    var url = 'api.php?a=screen&c=' + encodeURIComponent(code) + (lastHash ? '&h=' + lastHash : '');
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (state) {
        failures = 0;
        offline.hidden = true;
        if (state.same) return;
        // Right after a change made on this laptop, an older reply may still be on its way: ignore it.
        if (pending && Date.now() < pending.until && state.raw_slide_id !== pending.id) return;
        pending = null;
        lastHash = state.h || '';
        nav = state.nav || nav;
        currentSlide = state.slide;
        eventName = state.event || eventName;
        var key = state.slide ? state.slide.id + ':' + state.slide.kind : 'hold';
        if (key !== layoutKey) { layoutKey = key; build(state); }
        update(state);
        // Keep only the neighbouring slide images in memory.
        var keep = {};
        (state.preload || []).concat(state.slide && state.slide.image ? [state.slide.image] : []).forEach(function (src) {
          keep[src] = true;
          if (!preloaded[src]) { var im = new Image(); im.src = src; if (im.decode) im.decode().catch(function () {}); preloaded[src] = im; }
        });
        Object.keys(preloaded).forEach(function (src) { if (!keep[src]) delete preloaded[src]; });
      })
      .catch(function () { failures++; if (failures >= 2) offline.hidden = false; })
      .then(function () {
        polling = false;
        if (again) { poll(); return; }
        var wait = document.hidden ? POLL_HIDDEN_MS : POLL_VISIBLE_MS;
        if (failures) wait = Math.min(MAX_BACKOFF_MS, 1000 * Math.pow(2, failures));
        pollTimer = setTimeout(poll, wait);
      });
  }
  poll();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

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
  // A key press or click moves at once: uploaded slides are switched here immediately (they are
  // preloaded), and the server is told in the background. Data collection pages appear as soon as
  // the server has answered, since they need its live data.
  var sendTarget = null, sending = false;
  function step(action) {
    if (!window.CSRF) {
      hint('To move the slides from this computer, <a href="login.php?next=' +
        encodeURIComponent(location.pathname + location.search) + '">log in as admin</a> in this browser first.');
      return;
    }
    if (!nav.length) { sendCommand(action, null); return; }
    var at = -1, cur = pending ? pending.id : (layoutKey && layoutKey !== 'hold' ? parseInt(layoutKey, 10) : 0);
    nav.forEach(function (s, i) { if (s.id === cur) at = i; });
    var i = at === -1 ? (action === 'next' ? 0 : nav.length - 1) : at + (action === 'next' ? 1 : -1);
    if (i < 0 || i >= nav.length) return;   // already at the start or the end
    var target = nav[i];
    pending = { id: target.id, until: Date.now() + 5000 };
    if (target.image) {
      layoutKey = target.id + ':image';
      buildImage(target.image, eventName);
      // Warm up the slides around the new position for the next presses.
      [i + 1, i + 2, i - 1].forEach(function (j) {
        var n = nav[j];
        if (n && n.image && !preloaded[n.image]) { var im = new Image(); im.src = n.image; if (im.decode) im.decode().catch(function () {}); preloaded[n.image] = im; }
      });
    }
    sendCommand('goto', target.id);
  }
  // Only the latest target matters: quick presses are combined into one request.
  function sendCommand(action, slideId) {
    sendTarget = { action: action, id: slideId };
    if (sending) return;
    sending = true;
    var t = sendTarget; sendTarget = null;
    fetch('api.php?a=' + t.action + '&lite=1&c=' + encodeURIComponent(code), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF },
      body: JSON.stringify(t.id ? { slide_id: t.id } : {})
    }).then(function (r) {
      if (r.status === 401) { window.CSRF = null; pending = null; step(t.action); return; }
      if (!r.ok) { pending = null; layoutKey = null; }   // refused: show whatever the server says is live
    }, function () { pending = null; hint('Could not reach the server – try again.'); })
      .then(function () {
        sending = false;
        if (sendTarget) { var n = sendTarget; sendTarget = null; sendCommand(n.action, n.id); return; }
        poll();
      });
  }
  document.addEventListener('keydown', function (e) {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    var onQfResults = ui.qfRes && currentSlide && currentSlide.module === 'm5' && currentSlide.kind === 'results';
    if (onQfResults && /^[1-4]$/.test(e.key)) {
      e.preventDefault();
      if (!window.CSRF) { step('next'); return; }   // shows the log-in hint
      setQfTab(parseInt(e.key, 10), true);
      return;
    }
    var action = { ArrowRight: 'next', ArrowDown: 'next', PageDown: 'next', ' ': 'next', Enter: 'next',
                   ArrowLeft: 'prev', ArrowUp: 'prev', PageUp: 'prev', Backspace: 'prev' }[e.key];
    if (!action) return;
    e.preventDefault();
    // On the results page the clicker goes through the four tabs first, then on to the next slide.
    if (onQfResults && window.CSRF && setQfTab(ui.qfRes.tab + (action === 'next' ? 1 : -1), true)) return;
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
