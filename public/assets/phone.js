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

  function render(state, force) {
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
    app.appendChild(el('div', { 'class': 'label', text: i.title }));
    ({ m1: renderM1, m2: renderM2, m3: renderContact, m4: renderContact })[i.module](i);
  }

  // ---- Module 1: one statement at a time
  function renderM1(i) {
    if (!i.statement) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'All done – thank you!' }),
        el('p', { 'class': 'lead', text: 'You answered ' + i.answered + ' statement' + (i.answered === 1 ? '' : 's') + '. New ones may appear here, so keep this page open.' })
      ]));
      return;
    }
    var s = i.statement;
    function answer(a) {
      return function () {
        if (busy) return;
        busy = true;
        Array.prototype.forEach.call(app.querySelectorAll('button'), function (b) { b.disabled = true; });
        api('m1_answer', { instance_id: i.id, statement_id: s.id, version: s.version, answer: a })
          .then(function (st) { render(st, true); })
          .catch(function () { render({ instance: i, notice: 'Connection problem – please try again.' }, true); })
          .then(function () { busy = false; });
      };
    }
    app.appendChild(el('p', { 'class': 'muted small', text: i.intro }));
    app.appendChild(el('p', { 'class': 'statement', text: s.text }));
    app.appendChild(el('div', { 'class': 'answers' }, [
      el('button', { 'class': 'btn yes', type: 'button', on: { click: answer('yes') } }, ['Yes']),
      el('button', { 'class': 'btn no', type: 'button', on: { click: answer('no') } }, ['No']),
      el('button', { 'class': 'btn dk', type: 'button', on: { click: answer('dk') } }, ["Don't know"])
    ]));
    if (i.answered) app.appendChild(el('p', { 'class': 'counter', text: i.answered + ' answered' }));
  }

  // ---- Module 2: free text + optional multiple choice
  function renderM2(i) {
    if (i.submitted) {
      app.appendChild(el('div', { 'class': 'done' }, [
        el('h1', { text: 'Thank you!' }),
        el('p', { 'class': 'lead', text: 'Your contribution is in. It will appear on the screen once the host approves it.' }),
        i.mine ? el('p', { 'class': 'statement', style: 'min-height:0', text: '“' + i.mine + '”' }) : null
      ]));
      return;
    }
    var ta = el('textarea', { id: 'body', maxlength: String(i.max_length), required: 'required' });
    var counter = el('div', { 'class': 'counter', text: '0 / ' + i.max_length });
    ta.addEventListener('input', function () { counter.textContent = ta.value.length + ' / ' + i.max_length; });
    var form = el('form', {}, [
      el('p', { 'class': 'statement', style: 'min-height:0', text: i.prompt }),
      el('label', { 'for': 'body', text: 'Your answer' }), ta, counter
    ]);
    (i.questions || []).forEach(function (q, qi) {
      var fs = el('fieldset', {}, [el('legend', { text: q.q })]);
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
    if (i.pledge) form.appendChild(el('div', { 'class': 'pledge', text: i.pledge }));
    if (i.intro) form.appendChild(el('p', { 'class': 'lead', text: i.intro }));
    var name = el('input', { type: 'text', id: 'fn', autocomplete: 'name', required: 'required' });
    var email = el('input', { type: 'email', id: 'em', autocomplete: 'email', inputmode: 'email', required: 'required' });
    var consent = el('input', { type: 'checkbox', id: 'cs', required: 'required' });
    form.appendChild(el('label', { 'for': 'fn', text: 'Full name' })); form.appendChild(name);
    form.appendChild(el('label', { 'for': 'em', text: 'Email' })); form.appendChild(email);
    form.appendChild(el('label', { 'class': 'check', 'for': 'cs' }, [consent, i.consent]));
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
