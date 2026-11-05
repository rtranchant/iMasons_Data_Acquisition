// Big screen: polls the event and renders the live slide. Rebuilds the layout only
// when the slide changes; otherwise updates numbers, dots and statements in place.
(function () {
  'use strict';
  var code = window.EVENT_CODE;
  var root = document.getElementById('screen');
  var POLL_MS = 1500, WALL_SWAP_MS = 6000, ANSWER_MIN_MS = 10000, FADE_MS = 800, LAYER_FADE_MS = 250;
  var WALL_SIZE = 8;
  // Export mode (?preview=slide id&tab=n): show that one slide with its final data, no animations,
  // ask the server once, then set window.__ready so the export page can take the picture.
  var params = new URLSearchParams(location.search);
  var PREVIEW = params.get('preview'), PREVIEW_TAB = +params.get('tab') || 0;
  if (PREVIEW) { document.documentElement.classList.add('preview'); FADE_MS = 0; }
  var layoutKey = null, ui = {}, timers = [], stage = null, currentLayer = null, shownIsImage = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function add(parent) { for (var i = 1; i < arguments.length; i++) if (arguments[i]) parent.appendChild(arguments[i]); return parent; }
  function clearTimers() {
    timers.forEach(clearInterval); timers = [];
    // Leaving a video slide: stop it now, not when its page has finished fading out.
    if (ui.yt && ui.yt.player && ui.yt.player.stopVideo) { try { ui.yt.player.stopVideo(); } catch (e) {} }
  }
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
    if (s && s.kind === 'video') return buildVideo(s, state.event);
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
    } else if (s.kind === 'results' && SV[s.module]) {
      buildSv(s);
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

  // ---------------------------------------------------------------- YouTube video slides
  // Full screen like an uploaded slide. Plays from the start time and pauses on the frame at the stop time
  // (so YouTube's suggestions never appear). Browsers only allow sound once someone has clicked or pressed
  // a key on this page; until then the video plays muted and the first click turns the sound on.
  var ytWaiters = null;
  function loadYouTube(done) {
    if (window.YT && window.YT.Player) return done(true);
    if (ytWaiters) { ytWaiters.push(done); return; }
    ytWaiters = [done];
    var prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = function () { if (prev) prev(); var w = ytWaiters; ytWaiters = null; w.forEach(function (f) { f(true); }); };
    var sc = document.createElement('script');
    sc.src = 'https://www.youtube.com/iframe_api';
    sc.onerror = function () { var w = ytWaiters || []; ytWaiters = null; w.forEach(function (f) { f(false); }); };
    document.head.appendChild(sc);
  }
  function buildVideo(s, eventName) {
    clearTimers();
    ui = {};
    var cut = shownIsImage, key = layoutKey;
    shownIsImage = true;
    var L = newLayer(eventName, false);
    stage = L.content;
    var box = el('div', 'yt-box');
    stage.appendChild(box);
    reveal(L, cut);
    if (PREVIEW) {   // export picture: the thumbnail and the title, no player
      if (/^media\//.test(s.thumb || '')) { var im = el('img', 'yt-thumb'); im.src = s.thumb; im.alt = ''; box.appendChild(im); }
      else add(box, add(el('div', 'yt-card'), el('div', 'kicker', 'Video'), el('h1', null, s.title)));
      return;
    }
    var holder = el('div');
    var note = el('div', 'yt-note');
    note.hidden = true;
    add(box, holder, note);
    ui.yt = { player: null, muted: false, note: note };
    var stopAt = s.end || null;
    var failTimer = setTimeout(function () { if (!ui.yt || ui.yt.player) return; showNote('The video could not load. Check the internet connection of this computer.'); }, 10000);
    function showNote(t) { note.textContent = t; note.hidden = false; }
    loadYouTube(function (ok) {
      if (layoutKey !== key || !ui.yt) return;   // moved on meanwhile
      if (!ok) return showNote('The video could not load. Check the internet connection of this computer.');
      ui.yt.player = new window.YT.Player(holder, {
        videoId: s.youtube_id, width: '100%', height: '100%',
        playerVars: { autoplay: 1, start: s.start || 0, end: s.end || undefined, controls: 0, rel: 0, modestbranding: 1,
                      playsinline: 1, iv_load_policy: 3, disablekb: 1, fs: 0, cc_load_policy: 0 },
        events: {
          onReady: function (e) {
            clearTimeout(failTimer);
            e.target.playVideo();
            // Blocked from playing with sound: play muted and say how to turn it on.
            setTimeout(function () {
              if (!ui.yt || ui.yt.player !== e.target) return;
              var st = e.target.getPlayerState();
              if (st !== 1 && st !== 3) {
                e.target.mute(); e.target.playVideo(); ui.yt.muted = true;
                showNote('Playing without sound: click anywhere on this screen to turn the sound on.');
              }
            }, 1500);
          },
          onError: function () { showNote('This video cannot be played here (it may be private, removed, or not allowed in other sites).'); }
        }
      });
      // Pause on the last frame just before the stop time (or the end of the video).
      timers.push(setInterval(function () {
        var p = ui.yt && ui.yt.player;
        if (!p || !p.getCurrentTime || p.getPlayerState() !== 1) return;
        var end = stopAt || (p.getDuration() ? p.getDuration() - 0.4 : 0);
        if (end && p.getCurrentTime() >= end - 0.25) p.pauseVideo();
      }, 200));
    });
  }
  function unmuteVideo() {
    if (!ui.yt || !ui.yt.muted || !ui.yt.player) return false;
    ui.yt.player.unMute(); ui.yt.player.setVolume(100); ui.yt.muted = false; ui.yt.note.hidden = true;
    return true;
  }

  function buildCollect(state, s) {
    add(stage, el('h1', 'title', s.title));
    var live = el('div', 'live');
    ui.closed = el('div', 'closed-tag', 'Closed');
    add(live, ui.closed);
    if (s.module === 'm4') add(live, el('div', 'pledge', s.pledge));
    if (s.module === 'm3') add(live, el('div', 'intro', s.intro));
    if (SV[s.module]) add(live, el('div', 'prompt-big', s.prompt));
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
    if (!s || s.kind === 'image' || s.kind === 'video') return;
    if (ui.closed) ui.closed.style.display = s.is_open ? 'none' : '';
    if (s.dots && ui.dots) drawDots(s.dots, s.module);
    if (s.dots && ui.rot) drawCount(s.dots);
    if (ui.num) ui.num.textContent = String(s.total_answers || 0);
    if (ui.rot) feedRotator(s.statements || []);
    if (ui.cols) drawResults(s);
    if (ui.slots) feedWall(s.statements || []);
    if (ui.qfLive && s.live) drawQfLive(s.live, s.min_n);
    if (ui.sv && s.results) drawSv(s);
    if (ui.qfRes && s.results) { ui.qfRes.data = s.results; if (!ui.qfRes.until || Date.now() > ui.qfRes.until) ui.qfRes.tab = s.tab; drawQfResults(); }
  }

  // ---------------------------------------------------------------- survey answers: scale, multiple choice, number

  var SV = { m6: true, m7: true, m8: true };

  function fmtNum(x, d) {
    if (x === null || x === undefined || isNaN(x)) return '–';
    return Number(x).toLocaleString('en-US', { maximumFractionDigits: d, minimumFractionDigits: 0 }).replace('-', '−');
  }

  function buildSv(s) {
    add(stage, el('div', 'kicker', s.title), el('h1', 'title sv-q', s.prompt));
    ui.sv = { sig: '', module: s.module, head: el('div', 'sv-head'), body: el('div', 'sv-body') };
    add(stage, ui.sv.head, ui.sv.body);
  }

  function drawSv(s) {
    var R = s.results, sig = JSON.stringify(R) + '|' + root.clientWidth + 'x' + root.clientHeight;
    ui.sv.last = s;
    if (sig === ui.sv.sig) return;
    ui.sv.sig = sig;
    ui.sv.body.innerHTML = '';
    var n = s.module === 'm7' ? R.people : R.stats.n;
    ui.sv.head.textContent = (n === 1 ? '1 answer' : n + ' answers') + (s.module === 'm7' && R.multi ? ' · people could pick more than one' : '') +
      (s.module === 'm8' && R.unit ? ' · in ' + R.unit : '');
    if (!n) { add(ui.sv.body, el('p', 'sv-empty', 'Answers will appear here as they come in.')); return; }
    if (s.module === 'm7') svChoices(R, ui.sv.body); else svCandle(R, ui.sv.body, s.module);
  }

  // Scale and number: five numbers in large type, then a horizontal candlestick:
  // whisker = lowest to highest answer, box = 10th to 90th percentile, white line = average.
  function svCandle(R, body, module) {
    var st = R.stats, d = module === 'm6' ? 1 : Math.min(3, (R.decimals || 0) + 1);
    var unit = R.unit ? ' ' + R.unit : '';
    var tiles = el('div', 'sv-tiles');
    [['mean', 'Average', 'avg'], ['min', 'Lowest', ''], ['p10', '10th percentile', 'box'], ['p90', '90th percentile', 'box'], ['max', 'Highest', '']].forEach(function (t) {
      add(tiles, add(el('div', 'sv-tile ' + t[2]), el('b', null, fmtNum(st[t[0]], d)), el('span', null, t[1])));
    });
    add(body, tiles);
    // Very long numbers: smaller type until each fits its tile (never under 40px).
    Array.prototype.forEach.call(tiles.querySelectorAll('b'), function (b) {
      for (var fs = parseFloat(getComputedStyle(b).fontSize); b.scrollWidth > b.clientWidth + 1 && fs > 40; fs -= 4) b.style.fontSize = fs + 'px';
    });

    // Axis range: the whole scale; for numbers, the answers' range with a margin, kept inside the limits set.
    var lo, hi;
    if (module === 'm6') { lo = R.min; hi = R.max; }
    else {
      var span = st.max - st.min, pad = span ? span * 0.08 : Math.max(1, Math.abs(st.max) * 0.1);
      lo = st.min - pad; hi = st.max + pad;
      if (R.min !== null && R.min !== undefined) lo = Math.max(lo, R.min);
      if (R.max !== null && R.max !== undefined) hi = Math.min(hi, R.max);
      if (hi <= lo) { hi = lo + 1; }
    }
    var X = function (v) { return 100 * (v - lo) / (hi - lo); };
    var chart = el('div', 'sv-chart'), above = el('div', 'sv-labels above'), track = el('div', 'sv-track'),
        below1 = el('div', 'sv-labels'), below2 = el('div', 'sv-labels'), axis = el('div', 'sv-axis');
    var whisk = el('i', 'sv-whisker'); whisk.style.left = X(st.min) + '%'; whisk.style.width = Math.max(0, X(st.max) - X(st.min)) + '%';
    var capL = el('i', 'sv-cap'); capL.style.left = X(st.min) + '%';
    var capR = el('i', 'sv-cap'); capR.style.left = X(st.max) + '%';
    var box = el('i', 'sv-box'); box.style.left = X(st.p10) + '%'; box.style.width = Math.max(0, X(st.p90) - X(st.p10)) + '%';
    var mean = el('i', 'sv-mean'); mean.style.left = X(st.mean) + '%';
    add(track, whisk, capL, capR, box, mean);
    function label(row, v, text, cls) { var l = el('span', 'sv-label ' + (cls || ''), text); l.dataset.x = X(v); row.appendChild(l); }
    label(above, st.mean, fmtNum(st.mean, d) + unit, 'avg');
    label(below1, st.p10, fmtNum(st.p10, d), 'box');
    label(below1, st.p90, fmtNum(st.p90, d), 'box');
    label(below2, st.min, fmtNum(st.min, d), '');
    label(below2, st.max, fmtNum(st.max, d), '');
    // Axis ticks: every point of a short scale, otherwise about six round numbers.
    var ticks = [];
    if (module === 'm6' && hi - lo <= 10) { for (var t = lo; t <= hi; t++) ticks.push(t); }
    else {
      var raw = (hi - lo) / 5, mag = Math.pow(10, Math.floor(Math.log10(raw))), stepV = [1, 2, 2.5, 5, 10].map(function (m) { return m * mag; }).filter(function (m) { return m >= raw; })[0];
      for (var v = Math.ceil(lo / stepV) * stepV; v <= hi + 1e-9; v += stepV) ticks.push(v);
    }
    ticks.forEach(function (t) { var k = el('span', 'sv-tick', fmtNum(t, 3)); k.style.left = X(t) + '%'; axis.appendChild(k); });
    add(chart, above, track, below1, below2, axis);
    if (module === 'm6' && (R.low_label || R.high_label)) add(chart, add(el('div', 'sv-ends'), el('span', null, R.low_label || ''), el('span', null, R.high_label || '')));
    add(body, chart);
    [above, below1, below2].forEach(placeLabels);
  }

  // Put each label centred on its value, pushing neighbours apart so they never overlap.
  function placeLabels(row) {
    var W = row.clientWidth, gap = 24, ls = Array.prototype.slice.call(row.children);
    var items = ls.map(function (l) { return { l: l, w: l.offsetWidth, c: W * l.dataset.x / 100 }; }).sort(function (a, b) { return a.c - b.c; });
    items.forEach(function (it, i) {
      it.left = Math.max(0, Math.min(W - it.w, it.c - it.w / 2));
      if (i && it.left < items[i - 1].left + items[i - 1].w + gap) it.left = items[i - 1].left + items[i - 1].w + gap;
    });
    for (var i = items.length - 1; i >= 0; i--) {
      var maxLeft = i === items.length - 1 ? W - items[i].w : items[i + 1].left - gap - items[i].w;
      if (items[i].left > maxLeft) items[i].left = Math.max(0, maxLeft);
    }
    items.forEach(function (it) { it.l.style.left = it.left + 'px'; });
  }

  // Multiple choice: most chosen first, with the number of people who chose each option.
  // Approved "Other" texts are listed underneath.
  function svChoices(R, body) {
    var max = Math.max(1, Math.max.apply(null, R.choices.map(function (c) { return c.count; })));
    var list = el('div', 'sv-list'), wrap = null;
    R.choices.forEach(function (c) {
      var bar = el('i'); bar.style.width = (100 * c.count / max) + '%';
      var pctTxt = R.people ? Math.round(100 * c.count / R.people) + '%' : '';
      add(list, add(el('div', 'sv-row' + (c.other ? ' other' : '')),
        add(el('div', 'sv-row-main'), el('div', 'sv-opt', c.label), add(el('div', 'sv-bar'), bar)),
        add(el('div', 'sv-count'), el('b', null, String(c.count)), el('span', null, pctTxt))));
    });
    add(body, list);
    if (R.other_texts && R.other_texts.length) {
      var box = el('div', 'sv-others');
      add(box, el('div', 'sv-others-title', (R.choices.filter(function (c) { return c.other; })[0] || { label: 'Other' }).label + ' answers'));
      wrap = el('div', 'sv-chips');
      R.other_texts.forEach(function (t) { add(wrap, el('span', 'sv-chip', t)); });
      add(box, wrap);
      add(body, box);
    }
    // Short screens: the standard answers come first, so drop "Other" texts (keeping one) before shrinking them.
    var overflowing = function () { return list.scrollHeight > list.clientHeight + 1; };
    if (wrap) { while (overflowing() && wrap.children.length > 1) wrap.removeChild(wrap.lastChild); }
    // Long option lists: make the rows smaller (never under 24px text) until they fit, then hide what is left.
    var steps = [1, 0.85, 0.72, 0.62];
    for (var k = 0; k < steps.length; k++) {
      list.style.setProperty('--k', steps[k]);
      if (list.scrollHeight <= list.clientHeight + 1) break;
    }
    var rows = list.children, hidden = 0;
    while (list.scrollHeight > list.clientHeight + 1 && rows.length > 1) { list.removeChild(rows[rows.length - 1]); hidden++; }
    if (hidden) {
      list.removeChild(rows[rows.length - 1]); hidden++;
      add(list, el('div', 'sv-more', '+ ' + hidden + ' more option' + (hidden > 1 ? 's' : '') + ' with fewer answers'));
    }
    // Other texts: keep only the chips that fit.
    if (wrap) { while (wrap.scrollHeight > wrap.clientHeight + 1 && wrap.children.length > 1) wrap.removeChild(wrap.lastChild); }
  }
  function svRefit() { if (ui.sv && ui.sv.last) { ui.sv.sig = ''; drawSv(ui.sv.last); } }
  window.addEventListener('resize', function () { setTimeout(svRefit, 350); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(svRefit);

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

  // Page B: results for the panel, built to be read from the back of the room.
  // Keys on this page: 1–4 tabs · → / ← next / previous tab (then slide) · ↓ / ↑ next / previous 5 rows ·
  // Enter: the top row alone, full screen · Esc: back to the list.
  var QF_TABS = ['Top problems', 'Where it breaks', 'Blind spots', 'Side by side'];
  var QF_PER = { 1: 5, 2: 4, 3: 5, 4: 3 };   // rows per page (tab 4: per column); fewer only if the screen is too short
  var QF_WHO = { hiring: 'the hiring side', candidate: 'the candidate side', education: 'the education side', other: 'the other side', all: 'the room' };
  var QF_THEY = { hiring: 'employers', candidate: 'candidates', education: 'educators', other: 'they' };

  function qfQuestion(tab, side) {
    if (tab === 1) return 'What does ' + (QF_WHO[side] || 'the room') + ' see as the biggest problems?';
    if (tab === 2) return 'Where in the journey are the strongest problems?';
    if (tab === 3) return side === 'all' ? "What can't the room see from where it sits?" : "What can't " + (QF_THEY[side] || 'they') + ' see from where they sit?';
    return 'Where do employers and candidates disagree?';
  }

  function buildQfResults(s) {
    ui.qfRes = { tab: s.tab || 1, data: s.results, sig: '', page: 0, focus: false, per: null };
    ui.qfRes.tabs = el('div', 'qf-tabs');
    ui.qfRes.q = el('h1', 'qf-question');
    ui.qfRes.head = el('div', 'qf-head');
    ui.qfRes.body = el('div', 'qf-body');
    ui.qfRes.pager = el('div', 'qf-pager');
    add(stage, ui.qfRes.tabs, ui.qfRes.q, ui.qfRes.head, ui.qfRes.body, ui.qfRes.pager);
  }
  function setQfTab(t, send) {
    if (!ui.qfRes || t < 1 || t > 4) return false;
    ui.qfRes.tab = t;
    ui.qfRes.page = 0;
    ui.qfRes.focus = false;
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
  function qfRowsFor(tab, d) {
    if (tab === 1) return d.top || [];
    if (tab === 3) return d.blind || [];
    return null;   // tabs 2 and 4 have a fixed number of rows
  }
  function setQfPage(dir) {
    var R = ui.qfRes, rows = qfRowsFor(R.tab, R.data);
    if (!rows || R.focus) return;
    var pages = Math.max(1, Math.ceil(rows.length / qfPer(R)));
    var p = Math.max(0, Math.min(pages - 1, R.page + dir));
    if (p !== R.page) { R.page = p; drawQfResults(); }
  }
  function setQfFocus(on) {
    if (!ui.qfRes) return;
    ui.qfRes.focus = on;
    drawQfResults();
  }

  function qfPer(R) { return R.per || QF_PER[R.tab]; }
  function drawQfResults() {
    var R = ui.qfRes, d = R.data;
    if (R.tab !== R.lastTab) { R.page = 0; R.per = null; R.lastTab = R.tab; }
    var sig = [R.tab, R.page, R.focus, qfPer(R), JSON.stringify(d)].join('|');
    if (sig === R.sig) return;
    R.sig = sig;
    R.tabs.innerHTML = '';
    QF_TABS.forEach(function (t, i) { add(R.tabs, el('span', 'qf-tab' + (R.tab === i + 1 ? ' on' : ''), (i + 1) + ' ' + t)); });
    // Keep the event name in the top bar clear of the tab buttons, whatever the screen width.
    var top = R.tabs.closest('.layer') && R.tabs.closest('.layer').querySelector('.top');
    if (top) top.style.paddingRight = (R.tabs.offsetWidth + 24 + window.innerWidth * 0.04) + 'px';
    R.q.textContent = qfQuestion(R.tab, d.side);
    var when = d.as_of ? new Date(d.as_of.replace(' ', 'T') + 'Z') : null;
    var parts = [d.side_label, d.included + ' people'];
    if (d.flag_mode === 'exclude') parts.push(d.excluded + ' excluded');
    else if (d.flag_mode === 'half') parts.push(d.half + ' at half weight');
    parts.push(when ? 'updated ' + when.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) : 'no answers yet');
    R.head.textContent = parts.join(' · ');
    R.body.innerHTML = '';
    R.pager.textContent = '';
    if (R.focus) return qfFocus(R, d);
    ({ 1: qfTop, 2: qfStages, 3: qfBlind, 4: qfCompare })[R.tab](d, R.body, R);
    // On a short screen, show fewer rows per page rather than shrink the text or let it overlap.
    var last = R.body.lastElementChild;
    var over = R.body.scrollHeight > R.body.clientHeight + 1 ||
      (last && last.getBoundingClientRect().bottom > R.body.getBoundingClientRect().bottom + 0.5);
    if (over && qfPer(R) > 1) {
      R.per = qfPer(R) - 1;
      R.page = 0;
      R.sig = '';
      drawQfResults();
    }
  }
  function qfRefit() { if (ui.qfRes) { ui.qfRes.per = null; ui.qfRes.sig = ''; drawQfResults(); } }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(qfRefit);
  window.addEventListener('resize', function () { setTimeout(qfRefit, 350); });

  function liftText(x) { if (x === null || x === undefined) return '–'; var r = Math.round(x); return (r > 0 ? '+' : r < 0 ? '−' : '') + Math.abs(r); }
  function nBox(n, low) {
    return add(el('span', 'qf-nbox'), el('span', null, 'n ' + n), low ? el('span', 'qf-low', 'low n') : null);
  }
  function qfRow(big, text, frac, barCls, n, low) {
    var bar = el('div', 'qf-thin ' + barCls), fill = el('i');
    fill.style.width = (100 * Math.max(0, Math.min(1, frac))) + '%';
    bar.appendChild(fill);
    return add(el('div', 'qf-row'), el('div', 'qf-num', big), add(el('div', 'qf-mid'), el('p', 'qf-text', text), bar), nBox(n, low));
  }
  function qfPage(R, rows, build) {
    var per = qfPer(R), start = R.page * per, page = rows.slice(start, start + per);
    var list = el('div', 'qf-rows');
    page.forEach(function (r) { list.appendChild(build(r)); });
    R.body.appendChild(list);
    if (rows.length > per) R.pager.textContent = (start + 1) + '–' + (start + page.length) + ' of ' + rows.length;
    return page;
  }
  function qfEmpty(body, text) { add(body, el('p', 'qf-text qf-empty', text)); }

  function qfTop(d, body, R) {
    if (!d.top.length) return qfEmpty(body, 'Not enough answers yet.');
    var max = Math.max(1, d.top[0].lift || 0);
    R.focusRow = qfPage(R, d.top, function (r) { return qfRow(liftText(r.lift), r.statement, (r.lift || 0) / max, 'lime', r.n, r.n < 10); })[0];
    R.focusBig = R.focusRow ? liftText(R.focusRow.lift) : null;
  }
  function qfBlind(d, body, R) {
    if (!d.blind.length) return qfEmpty(body, 'No "Don\'t know" answers yet.');
    var max = Math.max(0.01, d.blind[0].dk_share || 0);
    R.focusRow = qfPage(R, d.blind, function (r) { return qfRow(Math.round(100 * r.dk_share) + '%', r.statement, r.dk_share / max, 'purple', r.total, r.total < 10); })[0];
    R.focusBig = R.focusRow ? Math.round(100 * R.focusRow.dk_share) + '%' : null;
    if (R.focusRow) R.focusRow = { statement: R.focusRow.statement, n: R.focusRow.total };
  }
  function qfStages(d, body, R) {
    var strip = el('div', 'qf-blocks');
    d.stages.forEach(function (s) {
      var k = s.strong || 0;
      var b = el('div', 'qf-block' + (!s.enough ? ' none' : k >= 2 ? ' two' : k === 1 ? ' one' : ''));
      add(b, el('span', 'qf-block-name', s.stage));
      if (s.enough) add(b, el('b', 'qf-block-num', String(k)), el('span', 'qf-block-sub', k === 1 ? 'strong problem' : 'strong problems'));
      else add(b, el('span', 'qf-block-sub', 'not enough answers'));
      strip.appendChild(b);
    });
    body.appendChild(strip);
    var strong = d.stages.filter(function (s) { return s.enough && (s.strong || 0) >= 1 && s.top; })
      .sort(function (a, b) { return b.top.lift - a.top.lift; }).slice(0, qfPer(R));
    var list = el('div', 'qf-rows four');
    strong.forEach(function (s) {
      list.appendChild(add(el('div', 'qf-row'), el('div', 'qf-num', liftText(s.top.lift)),
        add(el('div', 'qf-mid'), add(el('p', 'qf-text'), el('b', null, s.stage + ': '), document.createTextNode(s.top.statement))), nBox(s.top.n, s.top.n < 10)));
    });
    if (!strong.length) list.appendChild(el('p', 'qf-text qf-empty', 'No stage has a strong problem (lift +20 or more) yet.'));
    body.appendChild(list);
    R.focusRow = strong[0] ? { statement: strong[0].stage + ': ' + strong[0].top.statement, n: strong[0].top.n } : null;
    R.focusBig = strong[0] ? liftText(strong[0].top.lift) : null;
  }
  function qfCompare(d, body, R) {
    var c = d.compare;
    if (!c.ok) {
      add(body, add(el('div', 'qf-refuse'),
        el('p', 'qf-text', 'Not enough candidate-side answers to compare reliably. Showing the hiring-side view only.'),
        el('p', 'qf-small', 'Hiring side: ' + c.hiring_n + ' people · candidate side: ' + c.candidate_n + ' people.')));
      R.focusRow = null;
      return;
    }
    var cand = c.rows.filter(function (r) { return r.gap > 0; }).sort(function (a, b) { return b.gap - a.gap; }).slice(0, qfPer(R));
    var emp = c.rows.filter(function (r) { return r.gap < 0; }).sort(function (a, b) { return a.gap - b.gap; }).slice(0, qfPer(R));
    function col(title, cls, rows) {
      var box = add(el('div', 'qf-col ' + cls), el('h2', 'qf-col-title', title));
      rows.forEach(function (r) {
        box.appendChild(add(el('div', 'qf-card4'), el('p', 'qf-text4', r.statement),
          add(el('div', 'qf-card4-nums'), el('b', 'qf-num', liftText(r.gap)),
            el('span', 'qf-small', 'candidates ' + liftText(r.candidate_lift) + ' · employers ' + liftText(r.hiring_lift)))));
      });
      if (!rows.length) box.appendChild(el('p', 'qf-small', 'None so far.'));
      return box;
    }
    add(body, add(el('div', 'qf-cols'), col('Candidates see it more', 'cand', cand), col('Employers see it more', 'emp', emp)));
    var shown = cand.concat(emp), ns = shown.map(function (r) { return r.candidate_n; });
    var range = ns.length ? (Math.min.apply(null, ns) === Math.max.apply(null, ns) ? String(ns[0]) : Math.min.apply(null, ns) + '–' + Math.max.apply(null, ns)) : '–';
    add(body, el('p', 'qf-small qf-basis', 'Based on ' + c.hiring_n + ' hiring-side and ' + c.candidate_n + ' candidate-side people. Candidate answers per statement: ' +
      range + ', so read the pattern, not the exact numbers.'));
    var top = shown.sort(function (a, b) { return Math.abs(b.gap) - Math.abs(a.gap); })[0];
    R.focusRow = top ? { statement: top.statement, n: top.candidate_n + ' candidates · ' + top.hiring_n + ' employers' } : null;
    R.focusBig = top ? liftText(top.gap) : null;
  }
  // Focus mode: the top row alone, full screen.
  function qfFocus(R, d) {
    // Work out the current top row by drawing the tab off-screen first.
    var scratch = el('div');
    var keep = R.body; R.body = scratch;
    ({ 1: qfTop, 2: qfStages, 3: qfBlind, 4: qfCompare })[R.tab](d, scratch, R);
    R.body = keep;
    R.pager.textContent = '';
    if (!R.focusRow) { R.focus = false; return drawQfResults(); }
    var n = 'n ' + R.focusRow.n;
    add(R.body, add(el('div', 'qf-focus'), el('b', 'qf-focus-num', R.focusBig), el('p', 'qf-focus-text', R.focusRow.statement), el('span', 'qf-focus-n', n)));
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
    var noun = { m1: 'answering', m2: 'contributed', m3: 'asked for the materials', m4: 'committed', m6: 'answered', m7: 'answered', m8: 'answered' }[module];
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

  // Short requests only: ask, wait for the answer, then wait 5 s before asking again (never two at once).
  // Laptop key presses do not wait: they switch slides here at once and ask the server straight after.
  // Unchanged replies are tiny ({same:true}); errors make it wait longer; a hidden tab asks rarely.
  var POLL_VISIBLE_MS = window.SCREEN_CHECK_MS || 5000, POLL_HIDDEN_MS = 60000, MAX_BACKOFF_MS = 30000;
  var offline = document.getElementById('offline'), lastHash = '', failures = 0, pollTimer = null, polling = false, again = false;
  var preloaded = {}, nav = [], eventName = document.title.replace(/ – Screen$/, '');
  var currentSlide = null;
  var pending = null;   // {id, until}: a slide this screen switched to itself and is waiting for the server to confirm
  function previewDone() {
    // Let fonts, images and the last fade-ins settle before the picture is taken.
    (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () {
      var imgs = Array.prototype.slice.call(document.images).filter(function (im) { return !im.complete; });
      return Promise.all(imgs.map(function (im) { return new Promise(function (ok) { im.onload = im.onerror = ok; }); }));
    }).then(function () { setTimeout(function () { window.__ready = true; }, 1400); });
  }
  function poll() {
    // A check is already on its way: run another one as soon as it returns, never skip it.
    if (polling) { again = true; return; }
    polling = true;
    again = false;
    clearTimeout(pollTimer);
    var url = 'api.php?a=screen&c=' + encodeURIComponent(code) + (lastHash ? '&h=' + lastHash : '') +
      (PREVIEW ? '&preview=' + encodeURIComponent(PREVIEW) : '');
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
        if (PREVIEW && PREVIEW_TAB && state.slide) state.slide.tab = PREVIEW_TAB;
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
        if (PREVIEW) { previewDone(); return; }
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
  function onQfResultsPage() { return ui.qfRes && currentSlide && currentSlide.module === 'm5' && currentSlide.kind === 'results'; }
  document.addEventListener('keydown', function (e) {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    if (onQfResultsPage()) {
      var R = ui.qfRes;
      if (/^[1-4]$/.test(e.key)) {
        e.preventDefault();
        if (!window.CSRF) { step('next'); return; }   // shows the log-in hint
        setQfTab(parseInt(e.key, 10), true);
        return;
      }
      if (e.key === 'Enter') { e.preventDefault(); setQfFocus(!R.focus); return; }
      if (e.key === 'Escape') { e.preventDefault(); if (R.focus) setQfFocus(false); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setQfPage(1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); setQfPage(-1); return; }
    }
    var action = { ArrowRight: 'next', ArrowDown: 'next', PageDown: 'next', ' ': 'next', Enter: 'next',
                   ArrowLeft: 'prev', ArrowUp: 'prev', PageUp: 'prev', Backspace: 'prev' }[e.key];
    if (!action) return;
    e.preventDefault();
    // On the results page the clicker goes through the four tabs first, then on to the next slide.
    if (onQfResultsPage() && window.CSRF && setQfTab(ui.qfRes.tab + (action === 'next' ? 1 : -1), true)) return;
    step(action);
  });
  document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.target.closest('#fs, #hint, a, button')) return;
    if (unmuteVideo()) return;   // a video playing without sound: the first click turns the sound on
    if (onQfResultsPage() && window.CSRF && setQfTab(ui.qfRes.tab + 1, true)) return;
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
