/* TeamSayo · server connector.
 * Injected by the platform after the app's own scripts. Replaces the browser-only storage of the prototype with the
 * workspace database: loads what the signed-in member may see, saves changes in small batches with version checks,
 * picks up colleagues' changes, and routes notifications, audit and billing through the server. */
(function () {
  'use strict';
  var B = window.TS_BOOT;
  if (!B) return;

  var ARR = { orgs: 'id', people: 'id', records: 'id', meetings: 'id', actions: 'id', goals: 'id', kudos: 'id', checkins: 'id', reviews: 'id',
    feedback: 'id', feedbackRequests: 'id', rules: 'id', xmeet: 'id', msess: 'id', mentees: 'id', japp: 'id', prep: 'id', media: 'id', projs: 'id', recordTypes: 'key' };
  var PREFIXED = ['att', 'capg', 'jweek'];          // S[c][sprint]['personId|…'] = value
  var CONFIG = ['cad', 'guilds', 'cerDefs', 'kpis', 'kpiManual', 'integrations', 'stats', 'mentorDir'];
  var CONFIG_ARRAYS = ['guilds', 'cerDefs', 'kpis'];
  var LEADER = ['owner', 'admin', 'director'].indexOf(B.me.role) >= 0;
  var SETTINGS_COLL = ['config', 'recordTypes', 'rules'];
  var PREF_KEY = 'ts_pref_' + B.workspace.uuid + '_' + B.me.userId;
  var MAX_INLINE = 150 * 1024;   // larger embedded files are uploaded and linked instead

  var base = {};        // 'c\u0001k' -> stable JSON of what the server has
  var vers = {};        // 'c\u0001k' -> server version
  var serverTime = null, timer = null, syncing = false, again = false, pendingEvents = [], pendingRender = false, online = true;

  /* ---------- helpers ---------- */
  function id(c, k) { return c + '\u0001' + k; }
  function split(key) { var i = key.indexOf('\u0001'); return [key.slice(0, i), key.slice(i + 1)]; }
  function stable(v) {
    if (Array.isArray(v)) return '[' + v.map(stable).join(',') + ']';
    if (v && typeof v === 'object') return '{' + Object.keys(v).sort().filter(function (k) { return v[k] !== undefined; }).map(function (k) { return JSON.stringify(k) + ':' + stable(v[k]); }).join(',') + '}';
    return JSON.stringify(v === undefined ? null : v);
  }
  function clone(v) { return v == null ? v : JSON.parse(JSON.stringify(v)); }
  // PHP turns {} into [] — mark empty objects on the way out and restore them on the way in.
  function enc(v) {
    if (Array.isArray(v)) return v.map(enc);
    if (v && typeof v === 'object') { var ks = Object.keys(v); if (!ks.length) return { __o: 1 }; var o = {}; ks.forEach(function (k) { o[k] = enc(v[k]); }); return o; }
    return v;
  }
  function dec(v) {
    if (Array.isArray(v)) return v.map(dec);
    if (v && typeof v === 'object') { var ks = Object.keys(v); if (ks.length === 1 && ks[0] === '__o') return {}; var o = {}; ks.forEach(function (k) { o[k] = dec(v[k]); }); return o; }
    return v;
  }
  function api(path, opts) {
    opts = opts || {};
    var h = { 'Accept': 'application/json', 'X-CSRF-TOKEN': B.csrf, 'X-Requested-With': 'XMLHttpRequest' };
    if (opts.body && !(opts.body instanceof FormData)) { h['Content-Type'] = 'application/json'; opts.body = JSON.stringify(opts.body); }
    return fetch(B.api + path, { method: opts.method || 'GET', headers: h, body: opts.body, credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401 || r.status === 419) { location.href = B.urls.workspaces; throw new Error('signed_out'); }
      return r.json().catch(function () { return {}; }).then(function (j) { j._status = r.status; return j; });
    });
  }
  function prefs() { try { return JSON.parse(localStorage.getItem(PREF_KEY)) || {}; } catch (e) { return {}; } }
  function savePrefs() {
    try { localStorage.setItem(PREF_KEY, JSON.stringify({ ai: S.settings.ai, lang: S.settings.lang, theme: S.settings.theme, currentOrg: S.currentOrg })); } catch (e) { /* private mode */ }
  }
  function nav(url) { if (url) location.href = url; }
  function post(url) { var f = document.createElement('form'); f.method = 'POST'; f.action = url; var i = document.createElement('input'); i.type = 'hidden'; i.name = '_token'; i.value = B.csrf; f.appendChild(i); document.body.appendChild(f); f.submit(); }

  /* ---------- state <-> items ---------- */
  function flatten() {
    var out = {};
    Object.keys(ARR).forEach(function (c) {
      var kf = ARR[c];
      (S[c] || []).forEach(function (x) { if (x && x[kf] != null && x[kf] !== '') out[id(c, String(x[kf]))] = x; });
    });
    PREFIXED.forEach(function (c) {
      Object.keys(S[c] || {}).forEach(function (sid) {
        var doc = S[c][sid] || {}, groups = {};
        Object.keys(doc).forEach(function (k) { var i = k.indexOf('|'), p = i > 0 ? k.slice(0, i) : '_'; (groups[p] = groups[p] || {})[k] = doc[k]; });
        Object.keys(groups).forEach(function (p) { out[id(c, sid + '|' + p)] = groups[p]; });
      });
    });
    Object.keys(S.ment || {}).forEach(function (sid) {
      Object.keys(S.ment[sid] || {}).forEach(function (p) { out[id('ment', sid + '|' + p)] = S.ment[sid][p]; });
    });
    Object.keys(S.cer || {}).forEach(function (k) { out[id('cer', k)] = S.cer[k]; });
    CONFIG.forEach(function (k) { if (S[k] !== undefined) out[id('config', k)] = CONFIG_ARRAYS.indexOf(k) >= 0 ? { v: S[k] } : S[k]; });
    var ws = Object.assign({}, S.settings); delete ws.ai; delete ws.lang;
    out[id('config', 'wsettings')] = ws;
    return out;
  }
  function blank() {
    var s = { users: [], audit: [], notifs: [], nkeys: {}, invoices: [], support: [], att: {}, capg: {}, ment: {}, jweek: {}, cer: {}, _cadDemo: 1 };
    Object.keys(ARR).forEach(function (c) { s[c] = []; });
    return s;
  }
  function put(s, c, k, d) {
    if (ARR[c]) { var kf = ARR[c], arr = s[c], i = arr.findIndex(function (x) { return String(x[kf]) === k; }); if (i >= 0) arr[i] = d; else arr.push(d); return; }
    var bar = k.indexOf('|');
    if (PREFIXED.indexOf(c) >= 0) { var sid = k.slice(0, bar), p = k.slice(bar + 1), doc = s[c][sid] = s[c][sid] || {};
      Object.keys(doc).forEach(function (x) { if ((x.indexOf('|') > 0 ? x.slice(0, x.indexOf('|')) : '_') === p) delete doc[x]; });
      Object.assign(doc, d); return; }
    if (c === 'ment') { var sd = k.slice(0, bar); (s.ment[sd] = s.ment[sd] || {})[k.slice(bar + 1)] = d; return; }
    if (c === 'cer') { s.cer[k] = d; return; }
    if (c === 'config') {
      if (k === 'wsettings') { s.settings = Object.assign(s.settings || {}, d); return; }
      if (CONFIG.indexOf(k) >= 0) s[k] = CONFIG_ARRAYS.indexOf(k) >= 0 ? (d && d.v) || [] : d;
    }
  }
  function drop(s, c, k) {
    if (ARR[c]) { var kf = ARR[c]; s[c] = (s[c] || []).filter(function (x) { return String(x[kf]) !== k; }); return; }
    var bar = k.indexOf('|');
    if (PREFIXED.indexOf(c) >= 0) { var doc = s[c][k.slice(0, bar)] || {}, p = k.slice(bar + 1); Object.keys(doc).forEach(function (x) { if ((x.indexOf('|') > 0 ? x.slice(0, x.indexOf('|')) : '_') === p) delete doc[x]; }); return; }
    if (c === 'ment') { var m = s.ment[k.slice(0, bar)]; if (m) delete m[k.slice(bar + 1)]; return; }
    if (c === 'cer') delete s.cer[k];
  }
  function canWriteColl(c) {
    if (B.workspace.readOnly || B.me.role === 'auditor') return false;
    if (c === 'orgs') return ['owner', 'admin'].indexOf(B.me.role) >= 0;
    if (SETTINGS_COLL.indexOf(c) >= 0) return LEADER;
    return true;
  }

  /* ---------- large embedded files ---------- */
  function uploads(obj, jobs) {
    if (!obj || typeof obj !== 'object') return;
    Object.keys(obj).forEach(function (k) {
      var v = obj[k];
      if (typeof v === 'string' && v.length > MAX_INLINE && v.slice(0, 5) === 'data:') jobs.push({ o: obj, k: k, v: v });
      else if (v && typeof v === 'object') uploads(v, jobs);
    });
  }
  function upload(job) {
    var m = /^data:([^;,]+)?(;base64)?,/.exec(job.v); if (!m) return Promise.resolve();
    var raw = job.v.slice(m[0].length), bytes;
    if (m[2]) { var b = atob(raw); bytes = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) bytes[i] = b.charCodeAt(i); } else bytes = new TextEncoder().encode(decodeURIComponent(raw));
    var type = m[1] || 'application/octet-stream', ext = (type.split('/')[1] || 'bin').replace(/[^a-z0-9]/gi, '').slice(0, 5);
    var fd = new FormData(); fd.append('file', new Blob([bytes], { type: type }), 'file.' + ext);
    return api('/files', { method: 'POST', body: fd }).then(function (r) {
      if (r.url) { if (job.o[job.k] === job.v) job.o[job.k] = r.url; }
      else { toast((r.message || 'A file could not be uploaded') + ' — it was not saved.'); if (job.o[job.k] === job.v) job.o[job.k] = ''; }
    });
  }

  /* ---------- sync ---------- */
  function schedule(ms) { clearTimeout(timer); timer = setTimeout(flush, ms == null ? 700 : ms); }
  function changes() {
    var now = flatten(), ups = [], dels = [];
    Object.keys(now).forEach(function (key) {
      var s = stable(now[key]); if (base[key] === s) return;
      var ck = split(key);
      if (!canWriteColl(ck[0])) { base[key] = s; return; }  // local defaults the member cannot store
      ups.push({ key: key, c: ck[0], k: ck[1], d: now[key], s: s });
    });
    Object.keys(base).forEach(function (key) {
      if (now[key] !== undefined) return;
      var ck = split(key);
      if (ck[0] === 'config') return;
      if (!canWriteColl(ck[0])) { delete base[key]; return; }
      dels.push({ key: key, c: ck[0], k: ck[1] });
    });
    return { ups: ups, dels: dels };
  }
  function flush() {
    if (syncing) { again = true; return Promise.resolve(); }
    var ch = changes();
    if (!ch.ups.length && !ch.dels.length && !pendingEvents.length) { setStatus(); return Promise.resolve(); }
    syncing = true; setStatus();
    var jobs = []; ch.ups.forEach(function (u) { uploads(u.d, jobs); });
    var pre = jobs.length ? Promise.all(jobs.map(upload)).then(function () { ch = changes(); }) : Promise.resolve();
    var events = pendingEvents.splice(0, 30);
    return pre.then(function () {
      var ups = ch.ups.slice(0, 200), dels = ch.dels.slice(0, 200);
      return api('/sync', { method: 'POST', body: {
        upserts: ups.map(function (u) { var o = { c: u.c, k: u.k, d: enc(u.d) }; if (vers[u.key] != null) o.v = vers[u.key]; return o; }),
        deletes: dels.map(function (d) { var o = { c: d.c, k: d.k }; if (vers[d.key] != null) o.v = vers[d.key]; return o; }),
        events: events } }).then(function (r) {
        if (r._status === 402) { goReadOnly(r.message); return; }
        if (r._status >= 400) { pendingEvents = events.concat(pendingEvents); throw new Error(r.message || ('HTTP ' + r._status)); }
        online = true;
        var sent = {}; ups.forEach(function (u) { sent[u.key] = u.s; });
        (r.ok || []).forEach(function (o) {
          var key = id(o.c, o.k);
          if (o.deleted) { delete base[key]; delete vers[key]; } else { base[key] = sent[key]; vers[key] = o.v; }
        });
        var changed = false, msgs = {};
        (r.rejected || []).forEach(function (o) {
          var key = id(o.c, o.k);
          if (o.d !== undefined) { var d = dec(o.d); put(S, o.c, o.k, d); base[key] = stable(d); vers[key] = o.v; changed = true; }
          else if (o.reason === 'forbidden' || o.reason === 'conflict') { drop(S, o.c, o.k); delete base[key]; changed = true; }
          else if (o.reason === 'remove_member_first') { changed = restoreFromBase(key) || changed; }
          else base[key] = sent[key];  // invalid / too large: do not retry endlessly
          msgs[o.reason] = (msgs[o.reason] || 0) + 1;
        });
        if (msgs.conflict) toast('A colleague changed ' + msgs.conflict + ' item(s) at the same time — their version is shown.');
        if (msgs.forbidden) toast(msgs.forbidden + ' change(s) are not allowed for your role and were undone.');
        if (msgs.remove_member_first) toast('Remove people with a login under Members & teams.');
        if (msgs.too_large) toast('An item is too large to save. Attach big files as links instead.');
        if (changed) safeRender();
      });
    }).catch(function (e) {
      if (e.message === 'signed_out') return;
      online = false; pendingEvents = events.concat(pendingEvents); schedule(15000);
    }).then(function () {
      syncing = false; setStatus();
      if (again) { again = false; schedule(50); }
    });
  }
  var lastServer = {};
  function restoreFromBase(key) {
    var ck = split(key), d = lastServer[key]; if (d === undefined) return false;
    put(S, ck[0], ck[1], clone(d)); return true;
  }
  function apply(items, deleted) {
    var changed = false, now = null;
    (items || []).forEach(function (it) {
      var key = id(it.c, it.k), d = dec(it.d), s = stable(d);
      if (vers[key] === it.v && base[key] === s) return;
      now = now || flatten();
      var local = now[key];
      var dirty = base[key] !== undefined ? (local === undefined || stable(local) !== base[key]) : local !== undefined;
      if (dirty && base[key] !== undefined) return;  // our edit is pending; the server answers with a conflict
      put(S, it.c, it.k, d); base[key] = s; vers[key] = it.v; lastServer[key] = clone(d); changed = true;
    });
    (deleted || []).forEach(function (o) {
      var key = id(o.c, o.k); if (base[key] === undefined) return;
      now = now || flatten();
      if (now[key] !== undefined && stable(now[key]) !== base[key]) return;
      drop(S, o.c, o.k); delete base[key]; delete vers[key]; changed = true;
    });
    return changed;
  }
  function safeRender() {
    var a = document.activeElement, busy = a && a.closest && (a.closest('#view') || a.closest('#modalbg') || a.closest('#drawer')) && /INPUT|TEXTAREA|SELECT/.test(a.tagName);
    var mb = document.getElementById('modalbg'), dr = document.getElementById('drawer');
    var overlay = (mb && mb.style.display && mb.style.display !== 'none') || (dr && dr.style.display && dr.style.display !== 'none');
    if (busy || overlay) { pendingRender = true; return; }
    pendingRender = false; render();
  }
  function poll() {
    if (document.hidden || syncing) return;
    Promise.all([
      api('/items?since=' + encodeURIComponent(serverTime)),
      api('/notifications')
    ]).then(function (res) {
      var r = res[0];
      if (r.items) { serverTime = r.serverTime; online = true; if (apply(r.items, r.deleted)) safeRender(); }
      if (res[1].notifications && mergeNotifs(res[1].notifications)) renderTop();
      setStatus();
    }).catch(function () { online = false; setStatus(); });
  }
  function mergeNotifs(list) {
    var before = stable(S.notifs.map(function (n) { return [n.id, n.read]; }));
    S.notifs = list.map(function (n) { return { id: 'n' + n.id, ts: n.ts, to: B.me.personKey, text: n.text, ref: n.ref, channels: n.channels, read: n.read }; });
    return before !== stable(S.notifs.map(function (n) { return [n.id, n.read]; }));
  }

  /* ---------- status, banners, read-only ---------- */
  function setStatus() {
    var el = document.getElementById('sync'); if (!el) return;
    var ch = S ? changes() : { ups: [], dels: [] }, n = ch.ups.length + ch.dels.length;
    if (B.workspace.readOnly) { el.className = 'status off'; el.textContent = '● ' + t('Read-only'); return; }
    if (!online) { el.className = 'status off'; el.textContent = '● ' + t('Offline') + (n ? ' — ' + n + ' change(s) waiting' : ''); return; }
    el.className = 'status'; el.textContent = syncing || n ? '● ' + t('Saving…') : '● ' + t('Saved');
  }
  function goReadOnly(msg) {
    B.workspace.readOnly = true; SNAPSHOT_RO = true; render();
    toast(msg || 'This workspace is read-only.');
  }
  function daysLeft() { return B.workspace.trialEndsAt ? Math.max(0, Math.ceil((Date.parse(B.workspace.trialEndsAt) - Date.now()) / 864e5)) : null; }

  /* ---------- wiring into the app ---------- */
  function wire() {
    save = function () { if (!S) return; savePrefs(); if (!B.workspace.readOnly) schedule(); setStatus(); };
    renderSync = setStatus;
    runRules = function () { /* reminders and escalations run on the server every 15 minutes */ };
    log = function (action, detail) {
      if (/^Signed (in|out)/.test(action)) return;
      pendingEvents.push({ a: String(action), d: detail ? String(detail) : '' });
      if (S && S.audit) S.audit.push({ id: uid(), ts: new Date().toISOString(), user: S.session, action: action, detail: detail || '' });
      schedule();
    };
    notify = function (to, text, ref, key) {
      if (!to) return;
      if (key) { if (S.nkeys[key]) return; S.nkeys[key] = 1; }
      if (to === S.session) S.notifs.unshift({ id: uid(), ts: new Date().toISOString(), to: to, text: text, ref: ref || null, channels: ['In-app'], read: false });
      api('/notifications', { method: 'POST', body: { to: to, text: String(text).slice(0, 500), ref: ref || null, key: key ? String(key).slice(0, 150) : null } }).catch(function () {});
    };
    signOut = function () { post(B.urls.logout); };
    showLogin = function () {
      document.getElementById('overlay').innerHTML = '<div class="lbox" role="dialog" aria-modal="true"><h2>' + esc(t('Your profile is not set up yet')) + '</h2><p class="muted small">Your workspace administrator needs to link your account to a person record under Members &amp; teams.</p><div class="row"><a class="btn" href="' + esc(B.urls.workspaces) + '">Workspaces</a><a class="btn" href="' + esc(B.urls.account) + '">My account</a><button class="btn" onclick="signOut()">Sign out</button></div></div>';
      document.getElementById('overlay').style.display = 'block';
    };
    signupWizard = function () { nav(B.urls.workspaces); };
    if (typeof ssoDemo === 'function') ssoDemo = function () { toast('Single sign-on is set up on request for Business and Enterprise customers.'); };
    var _go = go;
    go = function (v, extra) {
      if (v === 'settings' && extra && extra.setTab === 'billing') { nav(B.urls.billing); return; }
      if (v === 'operator') { nav(B.urls.admin || B.urls.workspaces); return; }
      return _go(v, extra);
    };
    var _setOrg = setOrg; setOrg = function (v) { _setOrg(v); savePrefs(); };
    var _setLang = setLang; setLang = function (v) { _setLang(v); savePrefs(); };
    var _top = renderTop;
    renderTop = function () {
      _top();
      var b = [], dl = daysLeft(), adminish = ['owner', 'admin'].indexOf(B.me.role) >= 0;
      if (B.workspace.readOnly) b.push('<div class="banner ro">🔒 ' + esc(B.workspace.status === 'expired' ? 'Your free trial has ended.' : 'There is no active subscription.') + ' Everything is kept and readable. ' + (adminish ? '<a href="' + esc(B.urls.billing) + '">Choose a plan</a> to keep editing.' : 'Ask your administrator to choose a plan.') + '</div>');
      else if (B.workspace.status === 'trialing' && dl != null) b.push('<div class="banner noprint">' + esc(B.workspace.name) + ' · Business trial: <b>' + dl + ' day' + (dl === 1 ? '' : 's') + ' left</b>' + (adminish ? ' · <a href="' + esc(B.urls.billing) + '">Choose a plan</a>' : '') + (B.allowDemo && LEADER ? ' · <a onclick="TS_SERVER.demo()">Load sample data (staging)</a>' : '') + '</div>');
      else if (B.workspace.status === 'past_due' && adminish) b.push('<div class="banner ro">⚠ The last payment failed. <a href="' + esc(B.urls.billing) + '">Update billing</a> to avoid interruption.</div>');
      if (B.env === 'staging') b.push('<div class="banner noprint" style="background:#7a2e0e;color:#fff">STAGING — test environment. Data here may be reset.</div>');
      document.getElementById('banners').innerHTML = b.join('');
    };
    var _nav = renderNav;
    renderNav = function () {
      _nav();
      var el = document.getElementById('nav'); if (!el) return;
      var links = [['👤', 'Members & teams', B.urls.members, ['owner', 'admin', 'director'].indexOf(B.me.role) >= 0],
        ['💳', 'Plan & billing', B.urls.billing, ['owner', 'admin'].indexOf(B.me.role) >= 0],
        ['🏢', 'Workspace settings', B.urls.settings, LEADER],
        ['🔑', 'My account & security', B.urls.account, true],
        ['🔀', 'Switch workspace', B.urls.workspaces, true],
        ['🛰', 'Operator console', B.urls.admin, !!B.urls.admin]];
      el.insertAdjacentHTML('beforeend', '<div class="navh">' + esc(t('Account')) + '</div>' + links.filter(function (l) { return l[3]; }).map(function (l) {
        return '<a class="nav" href="' + esc(l[2]) + '" style="text-decoration:none"><span class="ic" aria-hidden="true">' + l[0] + '</span>' + esc(t(l[1])) + '</a>';
      }).join('') + '<button class="nav" onclick="signOut()"><span class="ic" aria-hidden="true">↩</span>' + esc(t('Sign out')) + '</button>');
    };
    // Opening the notification drawer marks them read on the server too.
    var _on = openNotifs;
    openNotifs = function () { var unread = S.notifs.some(function (n) { return !n.read; }); _on(); if (unread) api('/notifications/read', { method: 'POST' }).catch(function () {}); };
    document.addEventListener('click', function () { if (pendingRender) setTimeout(safeRender, 300); }, true);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); else flush(); });
    window.addEventListener('beforeunload', function (e) { var c = changes(); if (c.ups.length || c.dels.length) { flush(); e.preventDefault(); e.returnValue = ''; } });
    window.addEventListener('online', function () { online = true; schedule(50); });
  }

  /* ---------- boot ---------- */
  function boot() {
    wire();
    var view = document.getElementById('view');
    if (view) view.innerHTML = '<div class="card" style="margin:20px">Loading your workspace…</div>';
    Promise.all([api('/items'), api('/notifications')]).then(function (res) {
      var r = res[0];
      if (!r.items) throw new Error(r.message || 'Could not load the workspace.');
      serverTime = r.serverTime;
      S = blank();
      r.items.forEach(function (it) { var d = dec(it.d), key = id(it.c, it.k); put(S, it.c, it.k, d); vers[key] = it.v; lastServer[key] = clone(d); });
      var fresh = !S.recordTypes.length;
      if (fresh) S.recordTypes = CORE_TYPES();
      if (!S.rules.length) S.rules = DEFAULT_RULES();
      if (!S.integrations) { S.integrations = {}; Object.keys(makeIntegrations()).forEach(function (k) { S.integrations[k] = false; }); }
      var p = prefs(), settings = Object.assign(baseSettings(), { apiKeys: [], webhooks: [] }, S.settings || {});
      settings.ai = Object.assign({ key: '', model: 'claude-sonnet-5-5' }, p.ai || {});
      settings.lang = p.lang || settings.lang || 'en';
      if (p.theme) settings.theme = p.theme;
      S.settings = settings;
      S.stats = S.stats || { reports: 0, ai: 0, updates: 0 };
      S.plan = B.workspace.plan === 'trial' ? 'Business' : (B.workspace.plan || 'Business').replace(/^./, function (c) { return c.toUpperCase(); });
      S.trialEnds = B.workspace.status === 'trialing' && B.workspace.trialEndsAt ? B.workspace.trialEndsAt.slice(0, 10) : null;
      S.seats = B.workspace.seats; S.workspaceName = B.workspace.name;
      S.session = B.me.personKey;
      mergeNotifs(res[1].notifications || []);
      // Baseline = exactly what the server holds, so locally added defaults are saved by those allowed to.
      r.items.forEach(function (it) { base[id(it.c, it.k)] = stable(dec(it.d)); });
      if (B.workspace.readOnly) SNAPSHOT_RO = true;
      ensureShape();
      S.currentOrg = (function () { var u = me(); if (!u) return 'all'; if (!can('viewAllOrgs')) return u.org || 'all'; return p.currentOrg && (p.currentOrg === 'all' || S.orgs.some(function (o) { return o.id === p.currentOrg; })) ? p.currentOrg : 'all'; })();
      var ov = document.getElementById('overlay'); if (ov) ov.style.display = 'none';
      if (!me()) { showLogin(); return; }
      UI.view = 'dashboard'; render();
      if (LEADER && can('audit')) loadAudit();
      schedule(1500);
      setInterval(poll, 30000);
      setInterval(function () { if (LEADER) loadAudit(); }, 300000);
    }).catch(function (e) {
      if (e.message === 'signed_out') return;
      if (view) view.innerHTML = '<div class="card" style="margin:20px"><h2>We could not load your workspace</h2><p class="muted">' + esc(e.message) + '</p><button class="btn p" onclick="location.reload()">Try again</button></div>';
    });
  }
  function makeIntegrations() { try { return makeDemo().integrations || {}; } catch (e) { return {}; } }
  function loadAudit() {
    api('/audit').then(function (r) { if (r.audit) { S.audit = r.audit.map(function (a) { return { id: 'a' + a.id, ts: a.ts, user: a.user || 'system', action: a.action, detail: (a.detail || '') + (a.user ? '' : ' · ' + a.name) }; }).reverse(); } }).catch(function () {});
  }

  window.TS_SERVER = {
    boot: boot,
    flush: flush,
    demo: function () {
      if (!B.allowDemo) return;
      if (!confirm('Replace this staging workspace\'s data with the sample data set? Existing records are kept only if they share an id.')) return;
      var d = makeDemo(), keep = S.session, people = S.people, orgs = S.orgs;
      Object.keys(d).forEach(function (k) { if (['users', 'session', 'currentOrg', 'notifs', 'nkeys', 'audit', 'plan', 'trialEnds', 'seats', 'invoices', 'support', 'workspaceName', 'settings'].indexOf(k) < 0) S[k] = d[k]; });
      // Keep the real accounts so everyone can still sign in, and give them the demo roles' places.
      orgs.forEach(function (o) { if (!S.orgs.some(function (x) { return x.id === o.id; })) S.orgs.push(o); });
      people.forEach(function (p) { if (!S.people.some(function (x) { return x.id === p.id; })) S.people.push(Object.assign({}, p, { org: p.org || S.orgs[0].id })); });
      S._cadDemo = 0; S.session = keep; ensureShape(); S.users = []; render(); schedule(10);
      toast('Sample data loaded and saving to staging…');
    }
  };
})();
