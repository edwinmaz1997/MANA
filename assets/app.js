/* MANA - utilidades de interfaz */
function toggleSidebar(open) {
  document.getElementById('sidebar').classList.toggle('-translate-x-full', !open);
  document.getElementById('backdrop').classList.toggle('hidden', !open);
}
function money(n) {
  n = Number(n || 0);
  return (window.MONEDA || 'Q') + '\u00A0' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function toast(msg, ms) {
  var t = document.createElement('div');
  t.className = 'toast'; t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function () { t.remove(); }, ms || 2600);
}
async function api(url, data, method) {
  var opts = { method: method || (data ? 'POST' : 'GET'), headers: { 'X-Requested-With': 'fetch', 'X-CSRF': window.CSRF, 'Accept': 'application/json' } };
  if (data) {
    if (data instanceof FormData) { data.append('_csrf', window.CSRF); opts.body = data; }
    else { var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, typeof data[k] === 'object' && data[k] !== null ? JSON.stringify(data[k]) : (data[k] == null ? '' : data[k])); }); fd.append('_csrf', window.CSRF); opts.body = fd; }
  }
  var r = await fetch(url, opts);
  var txt = await r.text();
  try { return JSON.parse(txt); } catch (e) { console.error(txt); return { ok: false, error: 'Error del servidor' }; }
}
function openModal(html) {
  var root = document.getElementById('modal-root');
  root.innerHTML = '<div class="modal-wrap" onclick="if(event.target===this)closeModal()"><div class="modal"><button type="button" class="modal-close" onclick="closeModal()" aria-label="Cerrar"><i data-lucide="x"></i></button>' + html + '</div></div>';
  document.body.classList.add('modal-open');
  if (window.lucide) lucide.createIcons();
  var f = root.querySelector('[autofocus]'); if (f) f.focus();
  return root.firstChild;
}
function closeModal() { document.getElementById('modal-root').innerHTML = ''; document.body.classList.remove('modal-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.querySelector('.modal-wrap')) closeModal(); });
function getGeo() {
  return new Promise(function (res) {
    if (!navigator.geolocation) return res(null);
    navigator.geolocation.getCurrentPosition(function (p) { res({ lat: p.coords.latitude.toFixed(7), lng: p.coords.longitude.toFixed(7) }); }, function () { res(null); }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
  });
}
function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (f.dataset.confirm && !confirm(f.dataset.confirm)) { ev.preventDefault(); return; }
  var b = f.querySelector('button[type=submit]:not([data-nolock])');
  if (b && !f.dataset.nolock) setTimeout(function () { b.disabled = true; }, 10);
});
document.addEventListener('click', function (ev) {
  var a = ev.target.closest('[data-confirm]');
  if (a && a.tagName === 'A' && !confirm(a.dataset.confirm)) ev.preventDefault();
});
document.addEventListener('DOMContentLoaded', function () {
  if (window.lucide) lucide.createIcons();
  document.querySelectorAll('.flash').forEach(function (f) { setTimeout(function () { f.style.transition = 'opacity .4s'; f.style.opacity = 0; setTimeout(function () { f.remove(); }, 400); }, 5000); });
  document.querySelectorAll('button[name=r]').forEach(function (b) {
    if (b.value === 'rango' && !b.closest('#rango-box')) b.addEventListener('click', function (e) { var box = document.getElementById('rango-box'); if (box && box.classList.contains('hidden')) { e.preventDefault(); box.classList.remove('hidden'); } });
  });
  // Vista previa de imágenes
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var img = document.getElementById(inp.dataset.preview);
      if (img && inp.files[0]) { img.src = URL.createObjectURL(inp.files[0]); img.classList.remove('hidden'); }
    });
  });
});

/* Formularios en modal: openForm('tpl-id', {campo: valor}) */
function openForm(tplId, data) {
  var tpl = document.getElementById(tplId);
  var m = openModal(tpl.innerHTML);
  var f = m.querySelector('form');
  data = data || {};
  Object.keys(data).forEach(function (k) {
    var els = f.querySelectorAll('[name="' + k + '"]');
    els.forEach(function (el) {
      if (el.type === 'checkbox') el.checked = !!Number(data[k]);
      else if (el.type === 'radio') el.checked = String(el.value) === String(data[k]);
      else if (el.type !== 'file') el.value = data[k] == null ? '' : data[k];
    });
  });
  var t = m.querySelector('[data-title]');
  if (t) t.textContent = data.id ? t.dataset.edit || 'Editar' : t.dataset.title;
  f.querySelectorAll('[data-onlynew]').forEach(function (el) { el.classList.toggle('hidden', !!data.id); });
  f.querySelectorAll('[data-onlyedit]').forEach(function (el) { el.classList.toggle('hidden', !data.id); });
  f.dispatchEvent(new Event('formopen'));
  return f;
}

/* Bloquear zoom (pinch / doble toque) en móviles */
(function () {
  ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (ev) { document.addEventListener(ev, function (e) { e.preventDefault(); }, { passive: false }); });
  document.addEventListener('touchmove', function (e) { if (e.touches.length > 1 || (e.scale && e.scale !== 1)) e.preventDefault(); }, { passive: false });
  var last = 0;
  document.addEventListener('touchend', function (e) {
    var now = Date.now();
    if (now - last < 300 && !e.target.closest('input, textarea, select')) e.preventDefault();
    last = now;
  }, { passive: false });
  document.addEventListener('wheel', function (e) { if (e.ctrlKey) e.preventDefault(); }, { passive: false });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0'].indexOf(e.key) >= 0) e.preventDefault(); });
})();

/* Tablas responsive: copia el encabezado de cada columna a data-label */
function responsiveTables(root) {
  (root || document).querySelectorAll('table.table').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      var i = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (!td.hasAttribute('data-label')) td.setAttribute('data-label', td.hasAttribute('colspan') ? '' : (heads[i] || ''));
        var nodes = Array.prototype.filter.call(td.childNodes, function (n) { return n.nodeType === 1 || (n.nodeType === 3 && n.textContent.trim()); });
        if (nodes.length > 1 && !td.querySelector(':scope > .td-v')) {
          var w = document.createElement('div'); w.className = 'td-v';
          while (td.firstChild) w.appendChild(td.firstChild);
          td.appendChild(w);
        }
        i += parseInt(td.getAttribute('colspan') || '1', 10);
      });
    });
  });
}
document.addEventListener('DOMContentLoaded', function () { responsiveTables(); });

/* Ajusta el tamaño de letra de los montos para que nunca se salgan de su tarjeta */
function fitText() {
  document.querySelectorAll('.fit').forEach(function (el) {
    el.style.fontSize = '';
    var size = parseFloat(getComputedStyle(el).fontSize), min = 11;
    while (el.scrollWidth > el.clientWidth + 1 && size > min) { size -= 1; el.style.fontSize = size + 'px'; }
  });
}
document.addEventListener('DOMContentLoaded', fitText);
window.addEventListener('load', fitText);
window.addEventListener('resize', function () { clearTimeout(window.__fitT); window.__fitT = setTimeout(fitText, 120); });

/* Autocompletar genérico: autocomplete(input, cajaSugerencias, url, render(item), pick(item)) */
function autocomplete(input, box, url, render, pick) {
  var timer, items = [], hl = -1;
  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 1) { box.classList.add('hidden'); return; }
    timer = setTimeout(async function () {
      var r = await api(url + '&q=' + encodeURIComponent(q));
      items = r.data || []; hl = -1;
      box.innerHTML = items.length ? items.map(function (it, i) { return '<div data-i="' + i + '">' + render(it) + '</div>'; }).join('') : '<div class="text-slate-400">Sin resultados</div>';
      box.classList.remove('hidden');
    }, 220);
  });
  input.addEventListener('keydown', function (e) {
    var els = box.querySelectorAll('[data-i]');
    if (e.key === 'ArrowDown') { hl = Math.min(hl + 1, els.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { hl = Math.max(hl - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (items[hl >= 0 ? hl : 0]) { pick(items[hl >= 0 ? hl : 0]); box.classList.add('hidden'); input.value = ''; } return; }
    els.forEach(function (el, i) { el.classList.toggle('hl', i === hl); });
  });
  box.addEventListener('mousedown', function (e) {
    var d = e.target.closest('[data-i]'); if (!d) return;
    e.preventDefault(); pick(items[d.dataset.i]); box.classList.add('hidden'); input.value = '';
  });
  input.addEventListener('blur', function () { setTimeout(function () { box.classList.add('hidden'); }, 150); });
}
