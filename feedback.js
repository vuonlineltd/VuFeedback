/*
 * On-page feedback pins. Activates only when a review key is present:
 *   https://site/page.html?review=KEY   (key is remembered in this browser)
 *   ?review=off  forgets the key.
 * Everything renders inside a shadow root so site CSS and widget CSS never touch.
 */
(function () {
  'use strict';

  // WordPress passes its own route (admin-ajax.php, never cached by hosts) in
  // VUFB_API; a plain HTML site talks to feedback.php beside this script.
  var SCRIPT = document.currentScript && document.currentScript.src;
  var ENDPOINT = window.VUFB_API || (SCRIPT ? SCRIPT.replace(/feedback\.js.*$/, 'feedback.php') : 'feedback/feedback.php');
  var OP = window.VUFB_API ? 'op' : 'action';
  var REVIEW = window.VUFB_REVIEW || ENDPOINT.replace('feedback.php', 'review.html');
  // A unique value on every read, so no cache can answer one visitor with another's response.
  function url(op, extra) {
    return ENDPOINT + (ENDPOINT.indexOf('?') === -1 ? '?' : '&') + OP + '=' + op + (extra || '') + '&_=' + Date.now() + Math.random().toString(36).slice(2, 7);
  }
  var LS_KEY = 'fb_key', LS_NAME = 'fb_name';

  /* ---- gate ------------------------------------------------------------ */
  var qs = new URLSearchParams(location.search);
  var qk = qs.get('review');
  var key = null;
  try {
    if (qk === 'off') { localStorage.removeItem(LS_KEY); }
    else if (qk) { localStorage.setItem(LS_KEY, qk); }
    key = localStorage.getItem(LS_KEY);
  } catch (e) { key = qk && qk !== 'off' ? qk : null; }
  if (!key) return;

  var page = location.pathname;
  var pageLeaf = page.split('/').pop() || 'index.html';
  function samePage(p) { return p.page === page || p.page === pageLeaf; }
  var isAdmin = false, pins = [], pinMode = false, hideResolved = false, openId = null;
  var hash = location.hash.match(/^#fb-([a-z0-9]+)$/);

  /* ---- shadow host ------------------------------------------------------ */
  var host = document.createElement('div');
  host.id = 'fb-host';
  host.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;overflow:visible;z-index:2147483000';
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;font-size:13px;line-height:1.4;color:#1b263c}' +
    '.fixed{position:fixed;inset:0;pointer-events:none;z-index:5}' +
    '.fixed>*{pointer-events:auto}' +
    '.layer{position:absolute;top:0;left:0;width:0;height:0;overflow:visible;z-index:2}' +
    '.hl{position:absolute;pointer-events:none;border:2px solid #19c6a8;background:rgba(25,198,168,.08);border-radius:3px;display:none;z-index:1}' +
    '.mask{position:fixed;inset:0;cursor:crosshair;z-index:4;display:none}' +
    '.mask.on{display:block}' +
    '.fab{position:absolute;right:20px;bottom:20px;display:flex;gap:6px;align-items:center}' +
    '.btn{border:0;cursor:pointer;border-radius:999px;padding:10px 16px;font-weight:600;background:#1b263c;color:#fff;box-shadow:0 6px 20px rgba(0,0,0,.2);display:inline-flex;align-items:center;gap:8px;font-size:13px}' +
    '.btn *{color:inherit}.btn.on{background:#19c6a8;color:#06302a}' +
    '.btn.ghost{background:#fff;color:#1b263c;padding:10px 12px}' +
    '.btn .n{background:#19c6a8;color:#06302a;border-radius:999px;padding:1px 7px;font-size:11px;min-width:20px;text-align:center}' +
    '.hint{position:absolute;left:50%;top:16px;transform:translateX(-50%);background:#1b263c;color:#fff;padding:8px 14px;border-radius:999px;box-shadow:0 6px 20px rgba(0,0,0,.25);display:none}' +
    '.hint.on{display:block}' +
    '.pin{position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:#e5533d;color:#fff;border:2px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.3);cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;pointer-events:auto}' +
    '.pin span{transform:rotate(45deg)}' +
    '.pin.progress{background:#e69a17}.pin.resolved{background:#7a8aa3}.pin.approx{border-style:dashed}' +
    '.pin.active{outline:3px solid #19c6a8;outline-offset:2px}' +
    '.pop{position:absolute;width:320px;background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.28);padding:14px;margin-left:18px;margin-top:-10px;pointer-events:auto;z-index:5}' +
    '.pop.left{margin-left:-338px}' +
    '.pop h4{margin:0 0 8px;font-size:14px;display:flex;justify-content:space-between;align-items:center}' +
    '.pop .x{cursor:pointer;color:#7a8aa3;font-size:18px;line-height:1;padding:0 4px}' +
    '.pop label{display:block;font-size:11px;color:#5b6b85;margin:8px 0 3px;text-transform:uppercase;letter-spacing:.04em}' +
    '.pop input,.pop textarea,.pop select{width:100%;border:1px solid #d5dbe5;border-radius:6px;padding:7px 9px;font-size:13px;background:#fff;color:#1b263c}' +
    '.pop textarea{min-height:76px;resize:vertical}' +
    '.row{display:flex;gap:8px}.row>*{flex:1}' +
    '.act{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;align-items:center}' +
    '.sm{border:0;cursor:pointer;border-radius:6px;padding:7px 12px;font-weight:600;font-size:12px;background:#1b263c;color:#fff}' +
    '.sm.ghost{background:#eef1f5;color:#1b263c}.sm.danger{background:#fbe4e0;color:#a3321f}' +
    '.meta{font-size:11px;color:#7a8aa3;margin-top:4px}' +
    '.txt{white-space:pre-wrap;margin:6px 0;font-size:13px}' +
    '.tag{display:inline-block;font-size:10px;padding:2px 7px;border-radius:999px;background:#eef1f5;margin-right:4px;text-transform:uppercase;letter-spacing:.04em;font-weight:600}' +
    '.tag.bug{background:#fbe4e0;color:#a3321f}.tag.question{background:#e3f1ff;color:#1f5aa3}.tag.high{background:#fff0d6;color:#8a5a00}' +
    '.tag.open{background:#fbe4e0;color:#a3321f}.tag.progress{background:#fff0d6;color:#8a5a00}.tag.resolved{background:#e6f7f2;color:#0d6b58}' +
    '.replies{border-top:1px solid #eef1f5;margin-top:10px;padding-top:8px;max-height:180px;overflow:auto}' +
    '.reply{margin-bottom:8px}.reply b{font-size:12px}' +
    '.panel{position:absolute;right:20px;bottom:70px;width:320px;max-height:70vh;overflow:auto;background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.28);display:none}' +
    '.panel.on{display:block}' +
    '.panel .hd{padding:12px 14px;border-bottom:1px solid #eef1f5;display:flex;justify-content:space-between;align-items:center;font-weight:600;position:sticky;top:0;background:#fff}' +
    '.panel .hd a{font-size:12px;color:#0d6b58;text-decoration:none;font-weight:500}' +
    '.item{padding:10px 14px;border-bottom:1px solid #f3f5f8;cursor:pointer;display:flex;gap:10px}' +
    '.item:hover{background:#f7f9fb}' +
    '.item .num{width:22px;height:22px;border-radius:50%;background:#e5533d;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}' +
    '.item .num.progress{background:#e69a17}.item .num.resolved{background:#7a8aa3}' +
    '.item .t{font-size:12px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}' +
    '.empty{padding:20px 14px;color:#7a8aa3;text-align:center}' +
    '.toast{position:absolute;left:50%;bottom:80px;transform:translateX(-50%);background:#1b263c;color:#fff;padding:8px 14px;border-radius:8px;display:none}' +
    '.toast.on{display:block}' +
    '.files{margin-top:8px}.file{display:flex;align-items:center;gap:8px;padding:5px 0;border-top:1px solid #f3f5f8;font-size:12px}' +
    '.file img{width:36px;height:36px;object-fit:cover;border-radius:4px;background:#eef1f5}.file .ic{width:36px;height:36px;border-radius:4px;background:#eef1f5;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#5b6b85;text-transform:uppercase}' +
    '.file a{color:#0d6b58;text-decoration:none;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}.file .rm{cursor:pointer;color:#a3321f;font-size:16px;padding:0 4px}' +
    '.attach{text-transform:none;letter-spacing:0;display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:#0d6b58;font-weight:600;font-size:12px;margin-top:8px}.attach input{display:none}' +
    '.chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}.chip{background:#eef1f5;border-radius:999px;padding:2px 8px;font-size:11px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '@media (max-width:600px){.pop{width:calc(100vw - 32px);margin-left:0;left:16px!important}.panel{width:calc(100vw - 40px)}}' +
    '</style>' +
    '<div class="hl"></div>' +
    '<div class="mask"></div>' +
    '<div class="layer"></div>' +
    '<div class="fixed">' +
      '<div class="hint">Click anywhere on the page to leave a comment. Press Esc to cancel.</div>' +
      '<div class="panel"><div class="hd"><span>Comments on this page</span><a class="rev" target="_blank">Review all</a></div><div class="list"></div></div>' +
      '<div class="toast"></div>' +
      '<div class="fab">' +
        '<button class="btn ghost list-btn" title="List comments">&#9776;</button>' +
        '<button class="btn ghost hide-btn" title="Hide resolved">&#10003;</button>' +
        '<button class="btn main"><span class="lbl">Feedback</span><span class="n">0</span></button>' +
      '</div>' +
    '</div>';

  var $ = function (s) { return root.querySelector(s); };
  var hl = $('.hl'), mask = $('.mask'), layer = $('.layer'), hint = $('.hint'), panel = $('.panel'),
      list = $('.list'), toast = $('.toast'), mainBtn = $('.btn.main'), countEl = $('.n');

  $('.rev').href = REVIEW + '?key=' + encodeURIComponent(key) + (window.VUFB_API ? '&api=' + encodeURIComponent(window.VUFB_API) : '');

  /* ---- helpers ---------------------------------------------------------- */
  function api(action, data) {
    return fetch(url(action), {
      cache: 'no-store',
      method: data ? 'POST' : 'GET',
      headers: { 'X-Feedback-Key': key, 'Content-Type': 'application/json' },
      body: data ? JSON.stringify(data) : undefined
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || r.status); return j; }); });
  }
  function say(msg) { toast.textContent = msg; toast.classList.add('on'); clearTimeout(say.t); say.t = setTimeout(function () { toast.classList.remove('on'); }, 2500); }
  function esc(s) { return String(s == null ? '' : s); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function fmt(iso) { var d = new Date(iso); return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }); }
  function getName() { try { return localStorage.getItem(LS_NAME) || ''; } catch (e) { return ''; } }
  function setName(n) { try { localStorage.setItem(LS_NAME, n); } catch (e) {} }

  function fileUrl(a, dl) { return url('file', '&f=' + encodeURIComponent(a.id) + '&key=' + encodeURIComponent(key) + (dl ? '&dl=1' : '')); }
  function fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function isImg(a) { return /\.(jpe?g|png|gif|webp)$/i.test(a.id); }
  function upload(pinId, author, files) {
    if (!files || !files.length) return Promise.resolve(null);
    var fd = new FormData(); fd.append('id', pinId); fd.append('author', author);
    for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
    return fetch(url('upload'), { method: 'POST', cache: 'no-store', headers: { 'X-Feedback-Key': key }, body: fd })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || r.status); if (j.rejected && j.rejected.length) say('Not attached: ' + j.rejected.join(', ')); return j; }); });
  }
  function attachControl(label) {
    var wrap = el('label', 'attach'); wrap.appendChild(el('span', '', '\u{1F4CE} ' + (label || 'Attach images or documents')));
    var input = el('input'); input.type = 'file'; input.multiple = true; wrap.appendChild(input);
    var chips = el('div', 'chips');
    input.onchange = function () { chips.innerHTML = ''; for (var i = 0; i < input.files.length; i++) chips.appendChild(el('span', 'chip', input.files[i].name + ' · ' + fmtSize(input.files[i].size))); };
    var box = el('div'); box.appendChild(wrap); box.appendChild(chips); box.input = input;
    return box;
  }
  function fileList(p) {
    var box = el('div', 'files');
    (p.attachments || []).forEach(function (a) {
      var row = el('div', 'file');
      if (isImg(a)) { var im = el('img'); im.src = fileUrl(a); im.alt = ''; row.appendChild(im); }
      else row.appendChild(el('div', 'ic', a.id.split('.').pop()));
      var link = el('a', '', a.name); link.href = fileUrl(a, !isImg(a)); link.target = '_blank'; link.title = a.author + ' · ' + fmt(a.created); row.appendChild(link);
      row.appendChild(el('span', 'meta', fmtSize(a.size)));
      if (isAdmin) { var rm = el('span', 'rm', '×'); rm.title = 'Remove'; rm.onclick = function () { if (!confirm('Remove ' + a.name + '?')) return; api('unattach', { id: p.id, file: a.id }).then(function (j) { merge(j.pin); }); }; row.appendChild(rm); }
      box.appendChild(row);
    });
    return box;
  }

  function cssPath(node) {
    if (!(node instanceof Element)) return '';
    var parts = [];
    while (node && node.nodeType === 1 && node !== document.body) {
      var sel = node.tagName.toLowerCase();
      if (node.id && !/\d{3,}/.test(node.id)) { parts.unshift('#' + CSS.escape(node.id)); break; }
      var sib = node, n = 1;
      while ((sib = sib.previousElementSibling)) if (sib.tagName === node.tagName) n++;
      if (n > 1 || node.nextElementSibling) sel += ':nth-of-type(' + n + ')';
      parts.unshift(sel);
      node = node.parentElement;
    }
    return parts.join(' > ');
  }
  function resolve(pin) {
    var target = null;
    try { target = pin.selector ? document.querySelector(pin.selector) : null; } catch (e) {}
    if (target && target.getClientRects().length) {
      var r = target.getBoundingClientRect();
      return { x: r.left + window.scrollX + r.width * pin.rx, y: r.top + window.scrollY + r.height * pin.ry, approx: false };
    }
    return { x: pin.docX, y: pin.docY, approx: true };
  }

  /* ---- render ----------------------------------------------------------- */
  function visible() { return pins.filter(function (p) { return samePage(p) && !(hideResolved && p.status === 'resolved'); }); }
  function render() {
    layer.innerHTML = '';
    var vis = visible();
    vis.forEach(function (p, i) {
      var pos = resolve(p);
      var m = el('div', 'pin ' + p.status + (pos.approx ? ' approx' : '') + (openId === p.id ? ' active' : ''));
      m.style.left = pos.x + 'px'; m.style.top = pos.y + 'px';
      m.title = p.text; m.dataset.id = p.id;
      m.appendChild(el('span', '', i + 1));
      m.addEventListener('click', function (e) { e.stopPropagation(); openId = openId === p.id ? null : p.id; render(); });
      layer.appendChild(m);
      if (openId === p.id) layer.appendChild(popup(p, pos));
    });
    var pageCount = pins.filter(function (p) { return samePage(p) && p.status !== 'resolved'; }).length;
    countEl.textContent = pageCount;
    renderList(vis);
  }
  function placePop(pop, pos) {
    pop.style.left = pos.x + 'px'; pop.style.top = pos.y + 'px';
    if (pos.x + 350 > window.innerWidth) pop.classList.add('left');
  }
  function popup(p, pos) {
    var pop = el('div', 'pop'); placePop(pop, pos);
    var h = el('h4'); h.appendChild(el('span', '', p.author + ' · ' + fmt(p.created)));
    var x = el('span', 'x', '×'); x.onclick = function () { openId = null; render(); }; h.appendChild(x); pop.appendChild(h);
    var tags = el('div');
    tags.appendChild(el('span', 'tag ' + p.status, p.status === 'progress' ? 'in progress' : p.status));
    tags.appendChild(el('span', 'tag ' + p.type, p.type));
    if (p.priority === 'high') tags.appendChild(el('span', 'tag high', 'high priority'));
    pop.appendChild(tags);
    pop.appendChild(el('div', 'txt', p.text));
    if (p.snippet) pop.appendChild(el('div', 'meta', 'On: "' + p.snippet + '"'));
    if (pos.approx) pop.appendChild(el('div', 'meta', 'Element moved since this was pinned; position is approximate.'));
    if (p.replies && p.replies.length) {
      var rs = el('div', 'replies');
      p.replies.forEach(function (r) { var d = el('div', 'reply'); d.appendChild(el('b', '', r.author + ' · ' + fmt(r.created))); d.appendChild(el('div', 'txt', r.text)); rs.appendChild(d); });
      pop.appendChild(rs);
    }
    if (p.attachments && p.attachments.length) pop.appendChild(fileList(p));
    var ta = el('textarea'); ta.placeholder = 'Reply…'; ta.style.marginTop = '8px'; ta.style.minHeight = '44px'; pop.appendChild(ta);
    var att = attachControl('Attach files'); pop.appendChild(att);
    var act = el('div', 'act');
    if (isAdmin) {
      var sel = el('select'); ['open', 'progress', 'resolved'].forEach(function (s) { var o = el('option', '', s === 'progress' ? 'in progress' : s); o.value = s; if (s === p.status) o.selected = true; sel.appendChild(o); });
      sel.onchange = function () { api('status', { id: p.id, status: sel.value }).then(function (j) { merge(j.pin); say('Status updated'); }).catch(function (e) { say('Failed: ' + e.message); }); };
      act.appendChild(sel);
      var del = el('button', 'sm danger', 'Delete'); del.onclick = function () { if (!confirm('Delete this comment?')) return; api('delete', { id: p.id }).then(function () { pins = pins.filter(function (q) { return q.id !== p.id; }); openId = null; render(); }); }; act.appendChild(del);
    }
    var rep = el('button', 'sm', 'Reply'); rep.onclick = function () {
      var name = getName() || prompt('Your name') || ''; if (!name) return; setName(name);
      var files = att.input.files, text = ta.value.trim();
      if (!text && !files.length) return;
      rep.disabled = true;
      var chain = text ? api('reply', { id: p.id, author: name, text: text }) : Promise.resolve(null);
      chain.then(function () { return upload(p.id, name, files); })
        .then(function (j) { return j ? j : api('list').then(function (l) { return { pin: l.pins.find(function (q) { return q.id === p.id; }) }; }); })
        .then(function (j) { merge(j.pin); }).catch(function (e) { rep.disabled = false; say('Failed: ' + e.message); });
    }; act.appendChild(rep);
    pop.appendChild(act);
    return pop;
  }
  function merge(pin) { var i = pins.findIndex(function (q) { return q.id === pin.id; }); if (i < 0) pins.push(pin); else pins[i] = pin; render(); }

  function renderList(vis) {
    list.innerHTML = '';
    if (!vis.length) { list.appendChild(el('div', 'empty', 'No comments on this page yet.')); return; }
    vis.forEach(function (p, i) {
      var it = el('div', 'item');
      it.appendChild(el('div', 'num ' + p.status, i + 1));
      var t = el('div'); t.appendChild(el('div', 't', p.text)); t.appendChild(el('div', 'meta', p.author + ' · ' + fmt(p.created))); it.appendChild(t);
      it.onclick = function () { openId = p.id; render(); var pos = resolve(p); window.scrollTo({ top: Math.max(0, pos.y - window.innerHeight / 3), behavior: 'smooth' }); };
      list.appendChild(it);
    });
  }

  /* ---- new pin form ----------------------------------------------------- */
  function newPinForm(target, pos, rx, ry) {
    openId = null; render();
    var pop = el('div', 'pop'); placePop(pop, pos);
    var h = el('h4'); h.appendChild(el('span', '', 'New comment'));
    var x = el('span', 'x', '×'); x.onclick = function () { pop.remove(); marker.remove(); }; h.appendChild(x); pop.appendChild(h);
    var marker = el('div', 'pin active'); marker.style.left = pos.x + 'px'; marker.style.top = pos.y + 'px'; marker.appendChild(el('span', '', '+')); layer.appendChild(marker);

    pop.appendChild(el('label', '', 'Your name'));
    var name = el('input'); name.value = getName(); name.placeholder = 'Name'; pop.appendChild(name);
    pop.appendChild(el('label', '', 'Comment'));
    var ta = el('textarea'); ta.placeholder = 'What should change here?'; pop.appendChild(ta);
    var row = el('div', 'row');
    var typeW = el('div'); typeW.appendChild(el('label', '', 'Type'));
    var type = el('select'); [['change', 'Change'], ['bug', 'Bug'], ['question', 'Question']].forEach(function (o) { var e = el('option', '', o[1]); e.value = o[0]; type.appendChild(e); }); typeW.appendChild(type);
    var priW = el('div'); priW.appendChild(el('label', '', 'Priority'));
    var pri = el('select'); [['normal', 'Normal'], ['high', 'High'], ['low', 'Low']].forEach(function (o) { var e = el('option', '', o[1]); e.value = o[0]; pri.appendChild(e); }); priW.appendChild(pri);
    row.appendChild(typeW); row.appendChild(priW); pop.appendChild(row);
    var att = attachControl(); pop.appendChild(att);
    var act = el('div', 'act');
    var cancel = el('button', 'sm ghost', 'Cancel'); cancel.onclick = x.onclick; act.appendChild(cancel);
    var save = el('button', 'sm', 'Save comment'); act.appendChild(save); pop.appendChild(act);
    save.onclick = function () {
      if (!ta.value.trim()) { ta.focus(); return; }
      setName(name.value.trim());
      save.disabled = true; save.textContent = 'Saving…';
      api('add', { pin: {
        page: page, title: document.title, author: name.value.trim() || 'Anonymous', type: type.value, priority: pri.value, text: ta.value.trim(),
        selector: cssPath(target), rx: rx, ry: ry, docX: Math.round(pos.x), docY: Math.round(pos.y),
        snippet: (target.innerText || target.alt || target.tagName).trim().replace(/\s+/g, ' ').slice(0, 120),
        viewport: window.innerWidth + 'x' + window.innerHeight, ua: navigator.userAgent
      } }).then(function (j) {
        var files = att.input.files;
        if (files.length) { save.textContent = 'Uploading…'; return upload(j.pin.id, j.pin.author, files).catch(function (e) { say('Comment saved but files failed: ' + e.message); return j; }); }
        return j;
      }).then(function (j) { pop.remove(); marker.remove(); pins.push(j.pin); openId = j.pin.id; render(); say('Saved. Thanks!'); })
        .catch(function (e) { save.disabled = false; save.textContent = 'Save comment'; say('Failed: ' + e.message); });
    };
    layer.appendChild(pop);
    setTimeout(function () { ta.focus(); }, 50);
  }

  /* ---- pin mode ----------------------------------------------------------- */
  function setPinMode(on) {
    pinMode = on;
    mask.classList.toggle('on', on); hint.classList.toggle('on', on); mainBtn.classList.toggle('on', on);
    $('.lbl').textContent = on ? 'Cancel' : 'Feedback';
    if (!on) hl.style.display = 'none';
  }
  function under(e) {
    mask.style.display = 'none';
    var t = document.elementFromPoint(e.clientX, e.clientY);
    mask.style.display = '';
    if (!t || t === host || host.contains(t)) return null;
    return t;
  }
  mask.addEventListener('mousemove', function (e) {
    var t = under(e); if (!t) { hl.style.display = 'none'; return; }
    var r = t.getBoundingClientRect();
    hl.style.display = 'block'; hl.style.left = (r.left + window.scrollX) + 'px'; hl.style.top = (r.top + window.scrollY) + 'px'; hl.style.width = r.width + 'px'; hl.style.height = r.height + 'px';
  });
  mask.addEventListener('click', function (e) {
    var t = under(e); if (!t) return;
    var r = t.getBoundingClientRect();
    var rx = r.width ? (e.clientX - r.left) / r.width : .5, ry = r.height ? (e.clientY - r.top) / r.height : .5;
    setPinMode(false);
    newPinForm(t, { x: e.clientX + window.scrollX, y: e.clientY + window.scrollY }, rx, ry);
  });
  mainBtn.addEventListener('click', function () { panel.classList.remove('on'); setPinMode(!pinMode); });
  $('.list-btn').addEventListener('click', function () { setPinMode(false); panel.classList.toggle('on'); });
  $('.hide-btn').addEventListener('click', function () { hideResolved = !hideResolved; this.classList.toggle('on', hideResolved); this.title = hideResolved ? 'Show resolved' : 'Hide resolved'; render(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setPinMode(false); if (openId) { openId = null; render(); } } });

  /* ---- reposition on layout change --------------------------------------- */
  var raf; function schedule() { cancelAnimationFrame(raf); raf = requestAnimationFrame(render); }
  window.addEventListener('resize', schedule);
  window.addEventListener('load', schedule);
  if (window.ResizeObserver) new ResizeObserver(schedule).observe(document.documentElement);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);

  /* ---- boot --------------------------------------------------------------- */
  function boot() {
    document.body.appendChild(host);
    api('list').then(function (j) {
      pins = j.pins || []; isAdmin = !!j.admin;
      if (hash) { openId = hash[1]; var p = pins.find(function (q) { return q.id === openId; }); if (p) setTimeout(function () { var pos = resolve(p); window.scrollTo({ top: Math.max(0, pos.y - window.innerHeight / 3) }); render(); }, 300); }
      render();
      setTimeout(schedule, 800);
    }).catch(function (e) {
      if (String(e.message) === 'forbidden') { try { localStorage.removeItem(LS_KEY); } catch (x) {} host.remove(); return; }
      say('Feedback: could not load (' + e.message + ')');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
