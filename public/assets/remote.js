// Host remote: drive the run of show, open/close collections, approve statements.
(function () {
  'use strict';
  var code = window.EVENT_CODE, app = document.getElementById('app'), errBox = document.getElementById('err');
  var POLL_MS = 2000, lastSig = '', busy = false;
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
      if (r.status === 401) { location.href = 'login.php?next=' + encodeURIComponent(location.pathname + location.search); throw new Error('login'); }
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'error'); return j; });
    });
  }

  function act(action, body) {
    return function () {
      if (busy) return;
      busy = true;
      call(action, body || {}).then(function (st) { errBox.hidden = true; render(st, true); })
        .catch(function (e) { if (e.message !== 'login') { errBox.textContent = 'Could not reach the server – try again.'; errBox.hidden = false; } })
        .then(function () { busy = false; });
    };
  }

  function badge(cls, text) { return el('span', { 'class': 'badge ' + cls, text: text }); }

  function render(st, force) {
    var sig = JSON.stringify(st);
    if (!force && sig === lastSig) return;
    lastSig = sig;
    app.innerHTML = '';
    var cur = null;
    st.slides.forEach(function (s) { if (s.id === st.current_slide_id) cur = s; });
    var inst = null;
    if (cur) st.instances.forEach(function (i) { if (i.id === cur.instance_id) inst = i; });

    var nxt = null;
    st.slides.forEach(function (s) { if (s.id === st.next_slide_id) nxt = s; });

    // Now on screen, and what comes next
    app.appendChild(el('div', { 'class': 'now' }, [
      el('div', { 'class': 'label', text: 'On screen now' }),
      cur && cur.thumb ? el('img', { 'class': 'now-thumb', src: cur.thumb, alt: '' }) : null,
      el('div', { 'class': 'title', text: cur ? cur.title : 'Welcome screen' }),
      el('div', { 'class': 'small', style: 'color:#ABABAB', text: cur ? MOD[cur.module] + ' · ' + cur.label : 'Event name and QR code' })
    ]));
    app.appendChild(el('div', { 'class': 'nextup' }, [
      el('span', { 'class': 'label', text: 'Next' }),
      nxt && nxt.thumb ? el('img', { 'class': 'thumb', src: nxt.thumb, alt: '' }) : null,
      el('span', { 'class': 'small', text: nxt ? nxt.title + ' – ' + nxt.label : 'End of the run of show' })
    ]));

    // Key numbers
    var k = [['In the room', st.online]];
    if (inst) {
      k.push([inst.module === 'm1' ? 'Answering' : 'Responded', inst.dots.done]);
      if (inst.module === 'm1') k.push(['Answers', inst.total_answers]);
      else if (inst.module === 'm2') k.push(['To approve', inst.pending.length]);
      else k.push(['Watching', inst.dots.watching]);
    }
    app.appendChild(el('div', { 'class': 'kpis', style: 'grid-template-columns:repeat(' + k.length + ',1fr)' },
      k.map(function (x) { return el('div', { 'class': 'kpi' }, [el('b', { text: String(x[1]) }), el('span', { 'class': 'small muted', text: x[0] })]); })));

    if (inst) {
      app.appendChild(el('div', { 'class': 'actions', style: 'margin:8px 0 4px' }, [
        el('span', { 'class': 'small', text: 'Phones can respond:' }),
        el('div', { 'class': 'switch' }, [
          el('button', { type: 'button', 'class': inst.is_open ? 'on' : '', text: 'Open', on: { click: act('open', { instance_id: inst.id, open: true }) } }),
          el('button', { type: 'button', 'class': inst.is_open ? '' : 'on', text: 'Closed', on: { click: act('open', { instance_id: inst.id, open: false }) } })
        ])
      ]));
    }

    // Approval queues for every text-input module
    st.instances.filter(function (i) { return i.module === 'm2'; }).forEach(function (i) {
      app.appendChild(el('div', { 'class': 'section', text: 'Approve: ' + i.title + ' (' + i.pending.length + ' waiting)' }));
      if (!i.pending.length) app.appendChild(el('p', { 'class': 'muted small', text: 'Nothing waiting.' }));
      i.pending.forEach(function (p) {
        app.appendChild(el('div', { 'class': 'mod' }, [
          el('p', { text: p.body }),
          el('div', { 'class': 'actions' }, [
            el('button', { type: 'button', 'class': 'btn sm', text: 'Approve', on: { click: act('moderate', { submission_id: +p.id, status: 'approved' }) } }),
            el('button', { type: 'button', 'class': 'btn danger sm', text: 'Reject', on: { click: act('moderate', { submission_id: +p.id, status: 'hidden' }) } })
          ])
        ]));
      });
      if (i.approved.length) {
        var d = el('details', {}, [el('summary', { text: 'On screen (' + i.approved.length + ')' })]);
        i.approved.forEach(function (p) {
          d.appendChild(el('div', { 'class': 'mod' }, [el('p', { text: p.body }),
            el('button', { type: 'button', 'class': 'btn danger sm', text: 'Remove from screen', on: { click: act('moderate', { submission_id: +p.id, status: 'hidden' }) } })]));
        });
        app.appendChild(d);
      }
    });

    // Statement results for the live module 1
    if (inst && inst.module === 'm1') {
      app.appendChild(el('div', { 'class': 'section', text: 'Statements' }));
      inst.statements.forEach(function (s) {
        var n = s.yes + s.no + s.dk || 1;
        var bar = el('div', { 'class': 'bar' }, [['y', s.yes], ['n', s.no], ['d', s.dk]].map(function (p) { return el('i', { 'class': p[0], style: 'width:' + (100 * p[1] / n) + '%' }); }));
        app.appendChild(el('div', { 'class': 'mod' }, [
          el('div', { 'class': 'small' }, [badge(STATUS[s.status][0], STATUS[s.status][1]), ' Yes ' + s.yes + ' · No ' + s.no + " · Don't know " + s.dk]),
          bar, el('div', { 'class': 'small', text: s.text })
        ]));
      });
    }

    // Run of show
    app.appendChild(el('div', { 'class': 'section', text: 'Run of show' }));
    var list = el('div', { 'class': 'list' });
    list.appendChild(el('div', { 'class': 'row' + (cur ? '' : ' is-live') }, [
      el('div', { 'class': 'grow' }, [el('b', { text: 'Welcome screen' })]),
      cur ? el('button', { type: 'button', 'class': 'btn light sm', text: 'Show', on: { click: act('blank') } }) : badge('green', 'Live')
    ]));
    st.slides.forEach(function (s, idx) {
      var live = cur && cur.id === s.id;
      var instOf = st.instances.filter(function (i) { return i.id === s.instance_id; })[0];
      var row = el('div', { 'class': 'row' + (s.visible ? '' : ' is-hidden') + (live ? ' is-live' : '') }, [
        el('span', { 'class': 'label', style: 'width:20px', text: String(idx + 1) }),
        s.thumb ? el('img', { 'class': 'thumb', src: s.thumb, alt: '', loading: 'lazy' }) : null,
        el('div', { 'class': 'grow' }, [
          el('b', { text: s.title }), ' – ' + s.label, el('br'),
          el('span', { 'class': 'small' }, [
            s.kind === 'collect' ? (instOf.is_open ? 'Open' : 'Closed') + ' · ' + instOf.dots.done + ' responded' : '',
            s.hidden_by_parent ? 'Hidden with its participation slide' : ''
          ])
        ]),
        el('div', { 'class': 'actions' }, [
          live ? badge('green', 'Live') : (s.visible ? el('button', { type: 'button', 'class': 'btn sm', text: 'Go live', on: { click: act('goto', { slide_id: s.id }) } }) : null),
          s.hidden_by_parent ? null : el('button', { type: 'button', 'class': 'btn light sm', text: s.hidden ? 'Show' : 'Hide', on: { click: act('hide', { slide_id: s.id, hidden: !s.hidden }) } })
        ])
      ]);
      list.appendChild(row);
    });
    app.appendChild(list);
  }

  document.getElementById('next').addEventListener('click', act('next'));
  document.getElementById('prev').addEventListener('click', act('prev'));

  function poll() {
    if (!busy && !document.hidden) {
      call('remote').then(function (st) { errBox.hidden = true; render(st, false); })
        .catch(function (e) { if (e.message !== 'login') { errBox.textContent = 'Connection lost – retrying…'; errBox.hidden = false; } });
    }
    setTimeout(poll, POLL_MS);
  }
  poll();
})();
