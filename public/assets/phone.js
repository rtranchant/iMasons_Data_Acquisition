// Attendee phone: polls the event and shows whatever data collection is live.
(function () {
  'use strict';
  var code = window.EVENT_CODE;
  var app = document.getElementById('app');
  var shownKey = null;       // what is on screen now, to avoid re-rendering while typing
  var busy = false;
  var POLL_MS = 2500;

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

  function api(action, body) {
    var opts = { credentials: 'same-origin', headers: {} };
    if (body) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch('api.php?a=' + action + '&c=' + encodeURIComponent(code), opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.message || 'Something went wrong'); e.data = j; throw e; } return j; });
    });
  }

  function keyOf(state) {
    var i = state.instance;
    if (!i) return 'waiting';
    if (i.module === 'm1') return 'm1:' + i.id + ':' + (i.statement ? i.statement.id + '.' + i.statement.version : 'done');
    return i.module + ':' + i.id + ':' + (i.submitted ? 'sent' : 'form');
  }

  // ---- Email banner + pop-up for the session materials.
  // Always shown while the collateral request is on; greyed out once this person has signed up.
  var banner = null, bannerSig = '', modal = null;
  function updateBanner(state) {
    var c = state.collateral;
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
    var form = el('form', {}, [
      el('h2', { style: 'margin-top:0', text: 'Session materials' }),
      el('p', { 'class': 'muted txt', text: c.intro }),
      el('label', { 'for': 'm-fn', text: 'Full name' }), name,
      el('label', { 'for': 'm-em', text: 'Email' }), email,
      el('label', { 'class': 'check', 'for': 'm-cs' }, [consent, el('span', { 'class': 'txt', text: c.consent })]),
      err,
      el('div', { 'class': 'actions', style: 'margin-top:16px' }, [
        el('button', { 'class': 'btn lime', type: 'submit', text: 'Submit' }),
        el('button', { 'class': 'btn light', type: 'button', text: 'Not now', on: { click: closeModal } })
      ])
    ]);
    var box = el('div', { 'class': 'modal-box', role: 'dialog', 'aria-modal': 'true' }, [form]);
    modal = el('div', { 'class': 'modal', on: { click: function (e) { if (e.target === modal) closeModal(); } } }, [box]);
    document.body.appendChild(modal);
    name.focus();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true; err.hidden = true;
      api('collateral', { name: name.value, email: email.value, consent: consent.checked })
        .then(function (st) {
          if (st.notice) { err.textContent = st.notice; err.hidden = false; return; }
          // Back to the page they were on; the banner turns grey to confirm.
          lastState = st;
          closeModal();
        })
        .catch(function (e2) { err.textContent = e2.message || 'Connection problem – please try again.'; err.hidden = false; btn.disabled = false; });
    });
  }
  function closeModal() {
    if (modal) { modal.remove(); modal = null; }
    // Not forced: keeps whatever the attendee was typing in the page behind the pop-up.
    if (lastState) { render(lastState, false); }
  }

  var lastState = null;
  function render(state, force) {
    lastState = state;
    updateBanner(state);
    var key = keyOf(state);
    if (!force && key === shownKey) return;
    // Keep the current m1 statement until it is answered, even if the server would now pick another.
    if (!force && shownKey && state.instance && state.instance.module === 'm1' && state.instance.statement &&
        shownKey.indexOf('m1:' + state.instance.id + ':') === 0 && shownKey.slice(-5) !== ':done') return;
    shownKey = key;
    app.innerHTML = '';
    if (state.notice) app.appendChild(el('div', { 'class': 'flash', text: state.notice }));
    var i = state.instance;
    if (!i) {
      app.appendChild(el('div', { 'class': 'waiting' }, [
        el('div', { 'class': 'pulse' }),
        el('h1', { text: 'Watch the screen' }),
        el('p', { 'class': 'lead', text: 'The next activity will appear here automatically. Keep this page open.' })
      ]));
      return;
    }
    app.appendChild(el('div', { 'class': 'label txt', text: i.title }));
    ({ m1: renderM1, m2: renderM2, m3: renderContact, m4: renderContact })[i.module](i);
  }

  // ---- Module 1: one statement at a time, as a card to swipe.
  //      right = Yes, left = No, down = Don't know (buttons and arrow keys do the same).
  var enterNext = false, m1Commit = null;
  var SWIPE_PX = 110;

  function renderM1(i) {
    m1Commit = null;
    if (!i.statement) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'All done – thank you!' }),
        el('p', { 'class': 'lead', text: 'You answered ' + i.answered + ' statement' + (i.answered === 1 ? '' : 's') + '. New ones may appear here, so keep this page open.' })
      ]));
      return;
    }
    var s = i.statement;
    if (i.intro) app.appendChild(el('p', { 'class': 'muted small txt', text: i.intro }));

    var stamps = {
      yes: el('span', { 'class': 'stamp yes', text: 'Yes' }),
      no: el('span', { 'class': 'stamp no', text: 'No' }),
      dk: el('span', { 'class': 'stamp dk', text: "Don't know" })
    };
    var card = el('div', { 'class': 'swipe-card' + (enterNext ? ' enter' : ''), role: 'group', 'aria-label': 'Statement' }, [
      el('p', { 'class': 'txt', text: s.text }), stamps.yes, stamps.no, stamps.dk
    ]);
    enterNext = false;
    app.appendChild(el('div', { 'class': 'deck' }, [el('div', { 'class': 'swipe-card back', 'aria-hidden': 'true' }), card]));
    app.appendChild(el('p', { 'class': 'swipe-hint', text: 'Swipe → Yes   ·   ← No   ·   ↓ Don\'t know' }));
    var buttons = el('div', { 'class': 'answers3' }, [
      el('button', { 'class': 'btn no', type: 'button', on: { click: function () { commit('no'); } } }, ['← No']),
      el('button', { 'class': 'btn dk', type: 'button', on: { click: function () { commit('dk'); } } }, ["↓ Don't know"]),
      el('button', { 'class': 'btn yes', type: 'button', on: { click: function () { commit('yes'); } } }, ['Yes →'])
    ]);
    app.appendChild(buttons);
    if (i.answered) app.appendChild(el('p', { 'class': 'counter', text: i.answered + ' answered' }));

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

    function commit(a) {
      if (busy) return;
      busy = true;
      m1Commit = null;
      Array.prototype.forEach.call(buttons.querySelectorAll('button'), function (b) { b.disabled = true; });
      if (navigator.vibrate) { try { navigator.vibrate(18); } catch (e) {} }
      show(a, 1);
      var off = { yes: 'translate(140vw, -4vh) rotate(28deg)', no: 'translate(-140vw, -4vh) rotate(-28deg)', dk: 'translate(0, 110vh) rotate(4deg)' }[a];
      card.style.transition = 'transform .38s ease-in, opacity .38s ease-in';
      card.style.transform = off;
      card.style.opacity = '0.2';
      var flown = new Promise(function (r) { setTimeout(r, 380); });
      var sent = api('m1_answer', { instance_id: i.id, statement_id: s.id, version: s.version, answer: a });
      Promise.all([sent, flown])
        .then(function (res) { enterNext = true; busy = false; render(res[0], true); })
        .catch(function () { busy = false; render({ instance: i, collateral: lastState && lastState.collateral, notice: 'Connection problem – please try again.' }, true); });
    }
    m1Commit = commit;

    // Drag / swipe with pointer events (touch, pen and mouse).
    var start = null, last = null;
    card.addEventListener('pointerdown', function (e) {
      if (busy) return;
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

  // ---- Module 2: free text + optional multiple choice
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
    form.appendChild(err);
    form.appendChild(el('p', {}, [el('button', { 'class': 'btn lime block', type: 'submit' }, ['Send'])]));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var answers = (i.questions || []).map(function (q, qi) {
        var c = form.querySelector('input[name="q' + qi + '"]:checked');
        return c ? c.value : '';
      });
      submit(form, err, { instance_id: i.id, body: ta.value, answers: answers });
    });
    app.appendChild(form);
  }

  // ---- Modules 3 and 4: name, email, consent
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
    form.appendChild(err);
    form.appendChild(el('p', {}, [el('button', { 'class': 'btn lime block', type: 'submit' }, [i.module === 'm4' ? 'I commit' : 'Submit'])]));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submit(form, err, { instance_id: i.id, name: name.value, email: email.value, consent: consent.checked });
    });
    app.appendChild(form);
  }

  function submit(form, err, payload) {
    if (busy) return;
    busy = true;
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    err.hidden = true;
    api('submit', payload)
      .then(function (st) { render(st, true); })
      .catch(function (e) { err.textContent = e.message || 'Connection problem – please try again.'; err.hidden = false; btn.disabled = false; })
      .then(function () { busy = false; });
  }

  function poll() {
    if (!busy && !document.hidden) {
      api('phone').then(function (st) { render(st, false); }).catch(function () {});
    }
    setTimeout(poll, POLL_MS);
  }
  poll();
  document.addEventListener('visibilitychange', function () { if (!document.hidden && !busy) api('phone').then(function (st) { render(st, false); }); });
})();
