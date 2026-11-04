// Attendee phone. Nothing stays connected to the server:
//  - every ~10 s (and when the attendee taps, unlocks or returns to the page) it asks once what is live,
//    waits for the reply, then waits again; on errors it waits longer;
//  - each answer is sent as its own short request from a small queue that retries on bad Wi-Fi,
//    so a swipe never waits for the server;
//  - Yes/No statements are shuffled on the phone from the list it received.
(function () {
  'use strict';
  var code = window.EVENT_CODE;
  var app = document.getElementById('app');
  var CHECK_S = 10, MAX_BACKOFF_S = 60, REQ_TIMEOUT_MS = 12000;

  // ---------------------------------------------------------------- helpers

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { n.addEventListener(ev, attrs.on[ev]); });
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function store(key, val) {
    try {
      if (val === undefined) return JSON.parse(localStorage.getItem(key) || 'null');
      localStorage.setItem(key, JSON.stringify(val));
    } catch (e) { return null; }
  }
  function jitter(s) { return s * 1000 * (0.8 + Math.random() * 0.4); }   // spread phones out
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }

  // One short request. Resolves {status, data}; rejects only when the network itself fails.
  function request(action, body) {
    var opts = { credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (body) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var ctl = window.AbortController ? new AbortController() : null, timer;
    if (ctl) { opts.signal = ctl.signal; timer = setTimeout(function () { ctl.abort(); }, REQ_TIMEOUT_MS); }
    return fetch('api.php?a=' + action + '&c=' + encodeURIComponent(code), opts).then(function (r) {
      clearTimeout(timer);
      return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; });
    }, function (e) { clearTimeout(timer); throw e; });
  }
  function serverBusy(status) { return status === 0 || status === 429 || status >= 500; }

  // ---------------------------------------------------------------- what is live (checks)

  var state = null, checking = false, nextTimer = null, failures = 0, lastCheck = 0;

  function scheduleCheck(ms) {
    clearTimeout(nextTimer);
    nextTimer = setTimeout(function () { check(); }, ms);
  }
  function check(fromUser) {
    if (checking) return;
    // Taps can't flood the server: a trigger right after a check is postponed by a moment, not dropped.
    var since = Date.now() - lastCheck;
    if (fromUser && since < 2000) { scheduleCheck(2000 - since); return; }
    checking = true;
    lastCheck = Date.now();
    clearTimeout(nextTimer);
    request('phone').then(function (r) {
      if (r.status === 200 && !r.data.error) {
        failures = 0;
        setOffline(false);
        CHECK_S = r.data.check_s || CHECK_S;
        apply(r.data);
      } else if (r.status === 404) {
        failures = 0;
        app.innerHTML = '';
        app.appendChild(el('h1', { text: 'Event not found' }));
        return 'stop';
      } else {
        failures++;
      }
    }, function () { failures++; })
      .then(function (stop) {
        checking = false;
        if (stop === 'stop') return;
        if (failures) setOffline(failures >= 2);
        var wait = failures ? Math.min(MAX_BACKOFF_S, CHECK_S * Math.pow(2, failures - 1)) : CHECK_S;
        scheduleCheck(jitter(wait));
      });
  }
  function checkSoon() { check(true); }

  var offlineBox = null;
  function setOffline(on) {
    if (on && !offlineBox) {
      offlineBox = el('div', { 'class': 'offline-note', role: 'status', text: 'Reconnecting… your answers are saved on this phone and will be sent.' });
      document.body.appendChild(offlineBox);
    } else if (!on && offlineBox) { offlineBox.remove(); offlineBox = null; }
  }

  // Come back to the page, unlock the phone, regain Wi-Fi: check straight away.
  document.addEventListener('visibilitychange', function () { if (!document.hidden) checkSoon(); });
  window.addEventListener('focus', checkSoon);
  window.addEventListener('pageshow', checkSoon);
  window.addEventListener('online', function () { checkSoon(); pumpQueue(); });

  // ---------------------------------------------------------------- send queue (answers)

  var QKEY = 'imq_' + code, queue = store(QKEY) || [], pumping = false, qFailures = 0, qTimer = null;

  function enqueue(action, body) {
    queue.push({ a: action, b: body });
    store(QKEY, queue);
    pumpQueue();
  }
  function pumpQueue() {
    if (pumping || !queue.length) return;
    pumping = true;
    clearTimeout(qTimer);
    // Answers given while the previous request was on its way go together in this one.
    var first = queue[0], take = 1, body = first.b, action = first.a;
    if (first.a === 'm1_answer') {
      var group = [];
      while (take <= queue.length && group.length < 50 && queue[take - 1].a === 'm1_answer' && queue[take - 1].b.instance_id === first.b.instance_id) {
        group.push(queue[take - 1].b); take++;
      }
      take = group.length;
      body = { instance_id: first.b.instance_id, answers: group };
    }
    request(action, body).then(function (r) {
      if (serverBusy(r.status)) throw new Error('busy');
      queue.splice(0, take);         // sent, or refused for good (e.g. the module closed): either way done
      store(QKEY, queue);
      qFailures = 0;
    }).then(function () {
      pumping = false;
      if (queue.length) pumpQueue();
    }, function () {
      pumping = false;
      qFailures++;
      setOffline(qFailures >= 2);
      qTimer = setTimeout(pumpQueue, jitter(Math.min(30, 2 * Math.pow(2, qFailures - 1))));
    });
  }

  // ---------------------------------------------------------------- applying what is live

  var shownKey = null, local = store('iml_' + code) || {};   // local: submitted flags and m1 answers per instance
  function saveLocal() { store('iml_' + code, local); }

  function apply(st) {
    state = st;
    if (st.collateral && local['coll']) st.collateral.submitted = true;
    updateBanner(st);
    var i = st.instance;
    if (i && i.module === 'm1') m1Update(i);
    if (i && i.module !== 'm1' && local['s' + i.id]) { i.submitted = true; if (i.module === 'm2' && !i.mine) i.mine = local['s' + i.id]; }
    render(false);
  }

  function keyOf() {
    var i = state && state.instance;
    if (!i) return 'waiting';
    if (i.module === 'm1') return 'm1:' + i.id + ':' + (m1.current ? m1.current.id + '.' + m1.current.version : 'done');
    return i.module + ':' + i.id + ':' + (i.submitted ? 'sent' : 'form');
  }

  function render(force) {
    var key = keyOf();
    if (!force && key === shownKey) return;
    shownKey = key;
    app.innerHTML = '';
    var i = state.instance;
    if (!i) {
      app.appendChild(el('div', { 'class': 'waiting' }, [
        el('div', { 'class': 'pulse' }),
        el('h1', { text: 'Watch the screen' }),
        el('p', { 'class': 'lead', text: 'The next activity will appear here within a few seconds. Keep this page open.' }),
        checkButton()
      ]));
      return;
    }
    app.appendChild(el('div', { 'class': 'label txt', text: i.title }));
    ({ m1: renderM1, m2: renderM2, m3: renderContact, m4: renderContact })[i.module](i);
  }

  function checkButton() {
    return el('button', { 'class': 'btn light sm', type: 'button', text: 'Check for the next activity', on: { click: checkSoon } });
  }

  // ---------------------------------------------------------------- module 1: swipe cards, order chosen here

  var m1 = { id: null, pool: [], done: {}, current: null, count: 0, enter: false };

  function m1Update(i) {
    if (m1.id !== i.id) {
      m1 = { id: i.id, pool: [], done: local['m' + i.id] || {}, current: null, count: 0, enter: false };
    }
    m1.pool = (i.pool || []).filter(function (s) { return !m1.done[s.id + ':' + s.version]; });
    var answeredHere = Object.keys(m1.done).length;
    m1.count = Math.max(i.answered || 0, answeredHere, m1.count);
    // Keep the card being read unless it was decided, reworded or removed meanwhile.
    if (m1.current && !m1.pool.some(function (s) { return s.id === m1.current.id && s.version === m1.current.version; })) m1.current = null;
    if (!m1.current) m1.current = m1Pick();
  }
  function m1Pick() {
    var open = m1.pool.filter(function (s) { return s.status === 'open'; });
    var from = open.length ? open : m1.pool;
    return from.length ? shuffle(from.slice())[0] : null;
  }

  var m1Commit = null, SWIPE_PX = 110;

  function renderM1(i) {
    m1Commit = null;
    if (!m1.current) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'All done – thank you!' }),
        el('p', { 'class': 'lead', text: 'You answered ' + m1.count + ' statement' + (m1.count === 1 ? '' : 's') + '. New ones may appear here, so keep this page open.' }),
        checkButton()
      ]));
      return;
    }
    var s = m1.current;
    if (i.intro) app.appendChild(el('p', { 'class': 'muted small txt', text: i.intro }));

    var stamps = {
      yes: el('span', { 'class': 'stamp yes', text: 'Yes' }),
      no: el('span', { 'class': 'stamp no', text: 'No' }),
      dk: el('span', { 'class': 'stamp dk', text: "Don't know" })
    };
    var card = el('div', { 'class': 'swipe-card' + (m1.enter ? ' enter' : ''), role: 'group', 'aria-label': 'Statement' }, [
      el('p', { 'class': 'txt', text: s.text }), stamps.yes, stamps.no, stamps.dk
    ]);
    m1.enter = false;
    app.appendChild(el('div', { 'class': 'deck' }, [el('div', { 'class': 'swipe-card back', 'aria-hidden': 'true' }), card]));
    app.appendChild(el('p', { 'class': 'swipe-hint', text: 'Swipe → Yes   ·   ← No   ·   ↓ Don\'t know' }));
    var buttons = el('div', { 'class': 'answers3' }, [
      el('button', { 'class': 'btn no', type: 'button', on: { click: function () { commit('no'); } } }, ['← No']),
      el('button', { 'class': 'btn dk', type: 'button', on: { click: function () { commit('dk'); } } }, ["↓ Don't know"]),
      el('button', { 'class': 'btn yes', type: 'button', on: { click: function () { commit('yes'); } } }, ['Yes →'])
    ]);
    app.appendChild(buttons);
    if (m1.count) app.appendChild(el('p', { 'class': 'counter', text: m1.count + ' answered' }));

    function show(dir, amount) {
      ['yes', 'no', 'dk'].forEach(function (k) { stamps[k].style.opacity = k === dir ? Math.min(1, amount) : 0; });
      var tint = { yes: '0,80,5', no: '178,0,26', dk: '85,89,94' }[dir];
      card.style.background = dir ? 'rgba(' + tint + ',' + (0.1 * Math.min(1, amount)) + ')' : '';
    }
    function dirOf(dx, dy) {
      if (dy > 0 && dy > Math.abs(dx)) return ['dk', dy / SWIPE_PX];
      if (dx > 0) return ['yes', dx / SWIPE_PX];
      if (dx < 0) return ['no', -dx / SWIPE_PX];
      return [null, 0];
    }

    var committed = false;
    function commit(a) {
      if (committed) return;
      committed = true;
      m1Commit = null;
      Array.prototype.forEach.call(buttons.querySelectorAll('button'), function (b) { b.disabled = true; });
      if (navigator.vibrate) { try { navigator.vibrate(18); } catch (e) {} }
      // Record it here and send it in the background: the next card does not wait for the server.
      m1.done[s.id + ':' + s.version] = a;
      local['m' + i.id] = m1.done; saveLocal();
      enqueue('m1_answer', { instance_id: i.id, statement_id: s.id, version: s.version, answer: a });
      m1.count++;
      m1.pool = m1.pool.filter(function (x) { return !(x.id === s.id && x.version === s.version); });
      show(a, 1);
      var off = { yes: 'translate(140vw, -4vh) rotate(28deg)', no: 'translate(-140vw, -4vh) rotate(-28deg)', dk: 'translate(0, 110vh) rotate(4deg)' }[a];
      card.style.transition = 'transform .38s ease-in, opacity .38s ease-in';
      card.style.transform = off;
      card.style.opacity = '0.2';
      setTimeout(function () {
        if (!state || !state.instance || state.instance.id !== i.id) return;   // moved on meanwhile
        m1.current = m1Pick();
        m1.enter = true;
        render(true);
      }, 380);
    }
    m1Commit = commit;

    // Drag / swipe with pointer events (touch, pen and mouse).
    var start = null, last = null;
    card.addEventListener('pointerdown', function (e) {
      if (committed) return;
      start = { x: e.clientX, y: e.clientY, t: Date.now() };
      last = { x: e.clientX, y: e.clientY, t: start.t };
      card.setPointerCapture(e.pointerId);
      card.style.transition = 'none';
      card.classList.add('dragging');
    });
    card.addEventListener('pointermove', function (e) {
      if (!start) return;
      var dx = e.clientX - start.x, dy = e.clientY - start.y;
      card.style.transform = 'translate(' + dx + 'px,' + Math.max(dy, -40) + 'px) rotate(' + (dx * 0.06) + 'deg)';
      var d = dirOf(dx, dy);
      show(d[0], d[1]);
      last = { x: e.clientX, y: e.clientY, t: Date.now(), vx: (e.clientX - last.x) / Math.max(1, Date.now() - last.t), vy: (e.clientY - last.y) / Math.max(1, Date.now() - last.t) };
    });
    function end(e) {
      if (!start) return;
      card.classList.remove('dragging');
      var dx = e.clientX - start.x, dy = e.clientY - start.y;
      var d = dirOf(dx, dy);
      // A quick flick counts even if it is short.
      var flick = last && last.vx !== undefined && (Math.abs(last.vx) > 0.7 || last.vy > 0.7) && Math.max(Math.abs(dx), dy) > 40;
      start = null;
      if (d[0] && (d[1] >= 1 || flick)) { commit(d[0]); return; }
      card.style.transition = 'transform .25s cubic-bezier(.2,1.4,.4,1), background .25s';
      card.style.transform = '';
      show(null, 0);
    }
    card.addEventListener('pointerup', end);
    card.addEventListener('pointercancel', end);
  }

  document.addEventListener('keydown', function (e) {
    if (!m1Commit || modal || /input|textarea|select/i.test((e.target && e.target.tagName) || '')) return;
    var a = { ArrowRight: 'yes', ArrowLeft: 'no', ArrowDown: 'dk' }[e.key];
    if (a) { e.preventDefault(); m1Commit(a); }
  });

  // ---------------------------------------------------------------- forms: one short request on Send

  // Sends a form and resolves true when saved. Validation messages and connection problems are shown in err.
  function sendForm(action, payload, btn, err) {
    btn.disabled = true;
    err.hidden = true;
    return request(action, payload).then(function (r) {
      btn.disabled = false;
      if (r.status === 200 && r.data.ok) return true;
      err.textContent = r.data.message || (serverBusy(r.status)
        ? 'The server is busy right now – please wait a few seconds and tap again. Your text is still here.'
        : 'Something went wrong – please try again.');
      err.hidden = false;
      if (r.data.closed) checkSoon();
      return false;
    }, function () {
      btn.disabled = false;
      err.textContent = 'No connection – check the Wi-Fi and tap again. Your text is still here.';
      err.hidden = false;
      return false;
    });
  }

  // Module 2: free text + optional multiple choice
  function renderM2(i) {
    if (i.submitted) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'Thank you!' }),
        el('p', { 'class': 'lead', text: 'Your contribution is in. It will appear on the screen once the host approves it.' }),
        i.mine ? el('p', { 'class': 'statement txt', style: 'min-height:0', text: '“' + i.mine + '”' }) : null
      ]));
      return;
    }
    var ta = el('textarea', { id: 'body', maxlength: String(i.max_length), required: 'required' });
    var counter = el('div', { 'class': 'counter', text: '0 / ' + i.max_length });
    ta.addEventListener('input', function () { counter.textContent = ta.value.length + ' / ' + i.max_length; });
    var form = el('form', {}, [
      el('p', { 'class': 'statement txt', style: 'min-height:0', text: i.prompt }),
      el('label', { 'for': 'body', text: 'Your answer' }), ta, counter
    ]);
    (i.questions || []).forEach(function (q, qi) {
      var fs = el('fieldset', {}, [el('legend', { 'class': 'txt', text: q.q })]);
      q.options.forEach(function (o) {
        fs.appendChild(el('label', { 'class': 'opt' }, [el('input', { type: 'radio', name: 'q' + qi, value: o }), o]));
      });
      form.appendChild(fs);
    });
    var err = el('div', { 'class': 'error', role: 'alert', hidden: 'hidden' });
    var btn = el('button', { 'class': 'btn lime block', type: 'submit' }, ['Send']);
    form.appendChild(err);
    form.appendChild(el('p', {}, [btn]));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var answers = (i.questions || []).map(function (q, qi) {
        var c = form.querySelector('input[name="q' + qi + '"]:checked');
        return c ? c.value : '';
      });
      sendForm('submit', { instance_id: i.id, body: ta.value, answers: answers }, btn, err).then(function (ok) {
        if (!ok) return;
        local['s' + i.id] = ta.value.trim() || true; saveLocal();
        i.submitted = true; i.mine = ta.value.trim();
        render(true);
      });
    });
    app.appendChild(form);
  }

  // Modules 3 and 4: name, email, consent
  function renderContact(i) {
    if (i.submitted) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'Thank you!' }),
        el('p', { 'class': 'lead', text: i.module === 'm4' ? 'Your commitment is recorded. Others can now reach out to you.' : "We'll send you the materials after the session." })
      ]));
      return;
    }
    var form = el('form', {});
    if (i.pledge) form.appendChild(el('div', { 'class': 'pledge txt', text: i.pledge }));
    if (i.intro) form.appendChild(el('p', { 'class': 'lead txt', text: i.intro }));
    var name = el('input', { type: 'text', id: 'fn', autocomplete: 'name', required: 'required' });
    var email = el('input', { type: 'email', id: 'em', autocomplete: 'email', inputmode: 'email', required: 'required' });
    var consent = el('input', { type: 'checkbox', id: 'cs', required: 'required' });
    form.appendChild(el('label', { 'for': 'fn', text: 'Full name' })); form.appendChild(name);
    form.appendChild(el('label', { 'for': 'em', text: 'Email' })); form.appendChild(email);
    form.appendChild(el('label', { 'class': 'check', 'for': 'cs' }, [consent, el('span', { 'class': 'txt', text: i.consent })]));
    var err = el('div', { 'class': 'error', role: 'alert', hidden: 'hidden' });
    var btn = el('button', { 'class': 'btn lime block', type: 'submit' }, [i.module === 'm4' ? 'I commit' : 'Submit']);
    form.appendChild(err);
    form.appendChild(el('p', {}, [btn]));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      sendForm('submit', { instance_id: i.id, name: name.value, email: email.value, consent: consent.checked }, btn, err).then(function (ok) {
        if (!ok) return;
        local['s' + i.id] = true;
        if (state.collateral && state.collateral.id === i.id) { local['coll'] = true; state.collateral.submitted = true; updateBanner(state); }
        saveLocal();
        i.submitted = true;
        render(true);
      });
    });
    app.appendChild(form);
  }

  // ---------------------------------------------------------------- email banner + pop-up

  var banner = null, bannerSig = '', modal = null;
  function updateBanner(st) {
    var c = st.collateral;
    if (modal && c && !c.submitted) return;          // keep the pop-up as it is while it is open
    var sig = c ? (c.submitted ? 'done' : 'open') : '';
    if (sig === bannerSig) { if (banner) banner._coll = c; return; }
    bannerSig = sig;
    if (banner) { banner.remove(); banner = null; }
    document.body.classList.toggle('has-banner', !!c);
    if (!c) return;
    if (c.submitted) {
      banner = el('div', { 'class': 'mail-banner done', role: 'status' }, [
        el('span', { 'class': 'tick', 'aria-hidden': 'true', text: '✓' }),
        el('span', { text: "You'll receive the materials by email after the session." })
      ]);
    } else {
      banner = el('div', { 'class': 'mail-banner', role: 'region', 'aria-label': 'Session materials' }, [
        el('span', { text: 'Get the materials from this session by email' }),
        el('button', { 'class': 'btn lime sm', type: 'button', text: 'Send them to me', on: { click: function () { openModal(banner._coll); } } })
      ]);
    }
    banner._coll = c;
    document.body.appendChild(banner);
  }

  function openModal(c) {
    var name = el('input', { type: 'text', id: 'm-fn', autocomplete: 'name', required: 'required' });
    var email = el('input', { type: 'email', id: 'm-em', autocomplete: 'email', inputmode: 'email', required: 'required' });
    var consent = el('input', { type: 'checkbox', id: 'm-cs', required: 'required' });
    var err = el('div', { 'class': 'error', role: 'alert', hidden: 'hidden' });
    var btn = el('button', { 'class': 'btn lime', type: 'submit', text: 'Submit' });
    var form = el('form', {}, [
      el('h2', { style: 'margin-top:0', text: 'Session materials' }),
      el('p', { 'class': 'muted txt', text: c.intro }),
      el('label', { 'for': 'm-fn', text: 'Full name' }), name,
      el('label', { 'for': 'm-em', text: 'Email' }), email,
      el('label', { 'class': 'check', 'for': 'm-cs' }, [consent, el('span', { 'class': 'txt', text: c.consent })]),
      err,
      el('div', { 'class': 'actions', style: 'margin-top:16px' }, [
        btn,
        el('button', { 'class': 'btn light', type: 'button', text: 'Not now', on: { click: closeModal } })
      ])
    ]);
    var box = el('div', { 'class': 'modal-box', role: 'dialog', 'aria-modal': 'true' }, [form]);
    modal = el('div', { 'class': 'modal', on: { click: function (e) { if (e.target === modal) closeModal(); } } }, [box]);
    document.body.appendChild(modal);
    name.focus();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      sendForm('collateral', { name: name.value, email: email.value, consent: consent.checked }, btn, err).then(function (ok) {
        if (!ok) return;
        // Back to the page they were on; the banner turns grey to confirm.
        local['coll'] = true; saveLocal();
        if (state && state.collateral) state.collateral.submitted = true;
        closeModal();
      });
    });
  }
  function closeModal() {
    if (modal) { modal.remove(); modal = null; }
    if (state) updateBanner(state);   // the page behind keeps whatever the attendee was typing
  }

  // A tap while waiting (or after finishing the statements) is a cue to look for the next activity.
  app.addEventListener('pointerdown', function () {
    if (shownKey === 'waiting' || /:done$/.test(shownKey || '')) checkSoon();
  });

  // ---------------------------------------------------------------- start

  // Spread the first check over a second so a room scanning the QR code together doesn't arrive at once.
  scheduleCheck(Math.random() * 1000);
  pumpQueue();   // anything saved from before a reload or a dropped connection
})();
