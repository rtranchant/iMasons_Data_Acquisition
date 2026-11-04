// Host remote: drive the run of show, open/close collections, approve statements.
(function () {
  'use strict';
  var code = window.EVENT_CODE, app = document.getElementById('app'), errBox = document.getElementById('err');
  var POLL_MS = 2000, busy = false;
  var MOD = { m1: 'Statements', m2: 'Text input', m3: 'Collateral', m4: 'Pledge', deck: 'Slides' };
  var STATUS = { open: ['grey', 'Collecting'], yes: ['green', 'Yes'], no: ['red', 'No'], revise: ['purple', 'Needs revision'] };

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { n.addEventListener(ev, attrs.on[ev]); });
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c !== null && c !== undefined) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }

  function call(action, body) {
    var opts = { credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (body) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF'] = window.CSRF; opts.body = JSON.stringify(body); }
    return fetch('api.php?a=' + action + '&c=' + encodeURIComponent(code), opts).then(function (r) {
      if (!r.ok && r.status !== 401 && r.status !== 400 && r.status !== 422) {
        return r.text().then(function () { throw new Error('HTTP ' + r.status); });
      }
      if (r.status === 401) { location.href = 'login.php?next=' + encodeURIComponent(location.pathname + location.search); throw new Error('login'); }
      return r.json().catch(function () { throw new Error('HTTP ' + r.status); })
        .then(function (j) { if (!r.ok) throw new Error((j.error || 'error') + ', HTTP ' + r.status); return j; });
    });
  }

  function act(action, body) {
    return function (e) {
      if (busy) return;
      busy = true;
      var btn = e && e.currentTarget;
      if (btn && btn.classList) btn.classList.add('pending');   // instant feedback while the server answers
      call(action, body || {}).then(function (st) { errBox.hidden = true; lastHash = st.h || ''; render(st, false); })
        .catch(function (e2) { if (e2.message !== 'login') { errBox.textContent = 'Could not reach the server (' + e2.message + ') – try again.'; errBox.hidden = false; } })
        .then(function () { busy = false; if (btn && btn.classList) btn.classList.remove('pending'); });
    };
  }

  function badge(cls, text) { return el('span', { 'class': 'badge ' + cls, text: text }); }


  // The page is split into sections; each is redrawn only when its own data changes, so the
  // run-of-show thumbnails are not rebuilt every time a number moves.
  var lastState = null, secs = null;
  function part(name, data, build) {
    var sec = secs[name], sig = JSON.stringify(data);
    if (sig === sec.sig) return;
    sec.sig = sig;
    sec.el.innerHTML = '';
    build(sec.el);
  }

  function render(st, force) {
    lastState = st;
    if (!secs) {
      app.innerHTML = '';
      secs = {};
      ['now', 'kpis', 'approve', 'stats', 'ros'].forEach(function (n) { secs[n] = { el: el('div'), sig: null }; app.appendChild(secs[n].el); });
    }
    if (force) Object.keys(secs).forEach(function (n) { secs[n].sig = null; });
    var cur = null, nxt = null, inst = null;
    st.slides.forEach(function (s) { if (s.id === st.current_slide_id) cur = s; if (s.id === st.next_slide_id) nxt = s; });
    if (cur) st.instances.forEach(function (i) { if (i.id === cur.instance_id) inst = i; });

    // Now on screen, and what comes next
    part('now', [cur, nxt], function (box) {
      box.appendChild(el('div', { 'class': 'now' }, [
        el('div', { 'class': 'label', text: 'On screen now' }),
        cur && cur.thumb ? el('img', { 'class': 'now-thumb', src: cur.thumb, alt: '' }) : null,
        el('div', { 'class': 'title', text: cur ? cur.title : 'Welcome screen' }),
        el('div', { 'class': 'small', style: 'color:#ABABAB', text: cur ? MOD[cur.module] + ' · ' + cur.label : 'Event name and QR code' })
      ]));
      box.appendChild(el('div', { 'class': 'nextup' }, [
        el('span', { 'class': 'label', text: 'Next' }),
        nxt && nxt.thumb ? el('img', { 'class': 'thumb', src: nxt.thumb, alt: '' }) : null,
        el('span', { 'class': 'small', text: nxt ? nxt.title + ' – ' + nxt.label : 'End of the run of show' })
      ]));
    });

    // Key numbers and open/closed
    var k = [['In the room', st.online]];
    if (inst) {
      k.push([inst.module === 'm1' ? 'Answering' : 'Responded', inst.dots.done]);
      if (inst.module === 'm1') k.push(['Answers', inst.total_answers]);
      else if (inst.module === 'm2') k.push(['To approve', inst.pending.length]);
      else k.push(['Watching', inst.dots.watching]);
    }
    part('kpis', [k, inst && inst.id, inst && inst.is_open], function (box) {
      box.appendChild(el('div', { 'class': 'kpis', style: 'grid-template-columns:repeat(' + k.length + ',1fr)' },
        k.map(function (x) { return el('div', { 'class': 'kpi' }, [el('b', { text: String(x[1]) }), el('span', { 'class': 'small muted', text: x[0] })]); })));
      if (inst) {
        box.appendChild(el('div', { 'class': 'actions', style: 'margin:8px 0 4px' }, [
          el('span', { 'class': 'small', text: 'Phones can respond:' }),
          el('div', { 'class': 'switch' }, [
            el('button', { type: 'button', 'class': inst.is_open ? 'on' : '', text: 'Open', on: { click: act('open', { instance_id: inst.id, open: true }) } }),
            el('button', { type: 'button', 'class': inst.is_open ? '' : 'on', text: 'Closed', on: { click: act('open', { instance_id: inst.id, open: false }) } })
          ])
        ]));
      }
    });

    // Approval queues for every text-input module
    var m2s = st.instances.filter(function (i) { return i.module === 'm2'; });
    part('approve', m2s.map(function (i) { return [i.id, i.title, i.pending, i.approved]; }), function (box) {
      m2s.forEach(function (i) {
        box.appendChild(el('div', { 'class': 'section', text: 'Approve: ' + i.title + ' (' + i.pending.length + ' waiting)' }));
        if (!i.pending.length) box.appendChild(el('p', { 'class': 'muted small', text: 'Nothing waiting.' }));
        i.pending.forEach(function (p) {
          box.appendChild(el('div', { 'class': 'mod' }, [
            el('p', { 'class': 'txt', text: p.body }),
            el('div', { 'class': 'actions' }, [
              el('button', { type: 'button', 'class': 'btn sm', text: 'Approve', on: { click: act('moderate', { submission_id: +p.id, status: 'approved' }) } }),
              el('button', { type: 'button', 'class': 'btn danger sm', text: 'Reject', on: { click: act('moderate', { submission_id: +p.id, status: 'hidden' }) } })
            ])
          ]));
        });
        if (i.approved.length) {
          var d = el('details', {}, [el('summary', { text: 'On screen (' + i.approved.length + ')' })]);
          i.approved.forEach(function (p) {
            d.appendChild(el('div', { 'class': 'mod' }, [el('p', { 'class': 'txt', text: p.body }),
              el('button', { type: 'button', 'class': 'btn danger sm', text: 'Remove from screen', on: { click: act('moderate', { submission_id: +p.id, status: 'hidden' }) } })]));
          });
          box.appendChild(d);
        }
      });
    });

    // Statement results for the live module 1
    part('stats', inst && inst.module === 'm1' ? inst.statements : null, function (box) {
      if (!(inst && inst.module === 'm1')) return;
      box.appendChild(el('div', { 'class': 'section', text: 'Statements' }));
      inst.statements.forEach(function (s) {
        var n = s.yes + s.no + s.dk || 1;
        var bar = el('div', { 'class': 'bar' }, [['y', s.yes], ['n', s.no], ['d', s.dk]].map(function (p) { return el('i', { 'class': p[0], style: 'width:' + (100 * p[1] / n) + '%' }); }));
        box.appendChild(el('div', { 'class': 'mod' }, [
          el('div', { 'class': 'small' }, [badge(STATUS[s.status][0], STATUS[s.status][1]), ' Yes ' + s.yes + ' · No ' + s.no + " · Don't know " + s.dk]),
          bar, el('div', { 'class': 'small txt', text: s.text })
        ]));
      });
    });

    // Run of show (only redrawn when slides, the live slide or open/closed change)
    var openById = {};
    st.instances.forEach(function (i) { openById[i.id] = i.is_open; });
    part('ros', [st.slides, cur && cur.id, openById], function (box) {
      box.appendChild(el('div', { 'class': 'section', text: 'Run of show' }));
      var list = el('div', { 'class': 'list' });
      list.appendChild(el('div', { 'class': 'row' + (cur ? '' : ' is-live') }, [
        el('div', { 'class': 'grow' }, [el('b', { text: 'Welcome screen' })]),
        cur ? el('button', { type: 'button', 'class': 'btn light sm', text: 'Show', on: { click: act('blank') } }) : badge('green', 'Live')
      ]));
      st.slides.forEach(function (s, idx) {
        var live = cur && cur.id === s.id;
        list.appendChild(el('div', { 'class': 'row' + (s.visible ? '' : ' is-hidden') + (live ? ' is-live' : '') }, [
          el('span', { 'class': 'label', style: 'width:20px', text: String(idx + 1) }),
          s.thumb ? el('img', { 'class': 'thumb', src: s.thumb, alt: '', loading: 'lazy' }) : null,
          el('div', { 'class': 'grow' }, [
            el('b', { text: s.title }), ' – ' + s.label, el('br'),
            el('span', { 'class': 'small' }, [
              s.kind === 'collect' ? (openById[s.instance_id] ? 'Open' : 'Closed') : '',
              s.hidden_by_parent ? 'Hidden with its participation slide' : ''
            ])
          ]),
          el('div', { 'class': 'actions' }, [
            live ? badge('green', 'Live') : (s.visible ? el('button', { type: 'button', 'class': 'btn sm', text: 'Go live', on: { click: act('goto', { slide_id: s.id }) } }) : badge('grey', 'Hidden')),
            s.hidden_by_parent ? null : el('button', { type: 'button', 'class': 'btn light sm', text: s.hidden ? 'Show' : 'Hide', on: { click: act('hide', { slide_id: s.id, hidden: !s.hidden }) } })
          ])
        ]));
      });
      box.appendChild(list);
    });
  }


  // Go to page N (the numbers shown in the run of show).
  document.getElementById('goto').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('goto-n'), n = parseInt(input.value, 10);
    var slides = lastState ? lastState.slides : [];
    var s = slides[n - 1];
    if (!s) { errBox.textContent = 'There is no page ' + (input.value || '') + '. Pages go from 1 to ' + slides.length + '.'; errBox.hidden = false; return; }
    if (!s.visible) { errBox.textContent = 'Page ' + n + ' (' + s.title + ' – ' + s.label + ') is hidden. Show it first in the run of show below.'; errBox.hidden = false; return; }
    errBox.hidden = true;
    input.value = '';
    input.blur();
    act('goto', { slide_id: s.id })(e);
  });

  document.getElementById('next').addEventListener('click', act('next'));
  document.getElementById('prev').addEventListener('click', act('prev'));

  // Ask, wait for the reply, then ask again: never more than one refresh in flight.
  var failures = 0, lastHash = '', pollTimer = null;
  function poll() {
    clearTimeout(pollTimer);
    if (busy || document.hidden) { pollTimer = setTimeout(poll, POLL_MS); return; }
    call('remote' + (lastHash ? '&h=' + lastHash : ''))
      .then(function (st) {
        failures = 0; errBox.hidden = true;
        if (st.same) return;
        lastHash = st.h || '';
        render(st, false);
      })
      .catch(function (e) {
        if (e.message === 'login') return;
        failures++;
        // One missed refresh is normal on venue Wi-Fi; only say something when it keeps failing.
        if (failures >= 2) { errBox.textContent = 'Connection lost (' + e.message + ') – retrying…'; errBox.hidden = false; }
      })
      .then(function () { pollTimer = setTimeout(poll, failures ? Math.min(30000, 1000 * Math.pow(2, failures)) : POLL_MS); });
  }
  poll();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
