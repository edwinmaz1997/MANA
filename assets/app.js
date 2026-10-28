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

/* Filtros automáticos: los formularios de búsqueda (GET) se aplican solos al escribir o cambiar */
(function () {
  var KEY = 'mana_focus';
  function enviar(f, el) {
    try { if (el && el.name) sessionStorage.setItem(KEY, JSON.stringify({ url: location.search.split('&')[0], name: el.name, pos: el.selectionStart != null ? el.selectionStart : null })); } catch (e) {}
    if (f.requestSubmit) f.requestSubmit(); else f.submit();
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('main form').forEach(function (f) {
      if ((f.getAttribute('method') || 'get').toLowerCase() !== 'get' || f.dataset.noauto) return;
      var t;
      f.querySelectorAll('input[type=search], input[type=text], input:not([type])').forEach(function (inp) {
        inp.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { enviar(f, inp); }, 500); });
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); enviar(f, inp); } });
      });
      f.querySelectorAll('select').forEach(function (sel) { if (!sel.getAttribute('onchange')) sel.addEventListener('change', function () { enviar(f, sel); }); });
      f.querySelectorAll('input[type=date]').forEach(function (d) {
        if (d.getAttribute('onchange')) return;
        d.addEventListener('change', function () {
          if (!d.value) return;
          var box = d.closest('#rango-box');
          if (box) {
            var a = box.querySelector('input[name=desde]'), b = box.querySelector('input[name=hasta]');
            if (!a.value || !b.value) return;
            var h = f.querySelector('input[type=hidden][name=r]'); if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'r'; f.appendChild(h); } h.value = 'rango';
          }
          enviar(f, d);
        });
      });
    });
    // Recuperar el cursor en el buscador después de filtrar
    try {
      var s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY);
      if (s && s.url === location.search.split('&')[0]) {
        var el = document.querySelector('main form [name="' + s.name + '"]');
        if (el && (el.type === 'search' || el.type === 'text')) { el.focus(); var n = el.value.length; try { el.setSelectionRange(n, n); } catch (e) {} }
      }
    } catch (e) {}
  });
})();

/* Barras de pestañas con scroll horizontal: mantener visible la opción activa */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tabs, .overflow-x-auto').forEach(function (bar) {
    if (bar.scrollWidth <= bar.clientWidth) return;
    var act = bar.querySelector('.tab.active, .bg-slate-900, .active');
    if (!act || act === bar) return;
    var br = bar.getBoundingClientRect(), ar = act.getBoundingClientRect();
    bar.scrollLeft += (ar.left - br.left) - (bar.clientWidth - ar.width) / 2;
  });
});

/* ---- App instalable (PWA) ---- */
(function () {
  var deferred = null;
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  function bannerOculto() { try { return localStorage.getItem('rutero_banner') === '1'; } catch (e) { return false; } }
  function mostrar() {
    if (standalone) return;
    document.querySelectorAll('.pwa-install').forEach(function (el) {
      if (el.classList.contains('pwa-banner') && bannerOculto()) return;
      el.classList.remove('hidden');
    });
  }
  if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; mostrar(); });
  window.addEventListener('appinstalled', function () { document.querySelectorAll('.pwa-install').forEach(function (el) { el.classList.add('hidden'); }); toast('Rutero se instaló correctamente'); });
  document.addEventListener('DOMContentLoaded', function () { if (ios && !standalone) mostrar(); });
  window.ocultarBanner = function () { try { localStorage.setItem('rutero_banner', '1'); } catch (e) {} document.querySelectorAll('.pwa-banner').forEach(function (el) { el.classList.add('hidden'); }); };
  window.instalarApp = function () {
    if (deferred) { deferred.prompt(); deferred.userChoice.finally(function () { deferred = null; }); return; }
    var pasos = ios
      ? '<li>Toca el botón <b>Compartir</b> <i data-lucide="share" class="w-4 h-4 inline"></i> en la barra de Safari.</li><li>Elige <b>Agregar a pantalla de inicio</b>.</li><li>Toca <b>Agregar</b>.</li>'
      : '<li>Abre el menú del navegador <b>⋮</b>.</li><li>Toca <b>Instalar aplicación</b> o <b>Agregar a pantalla principal</b>.</li><li>Confirma con <b>Instalar</b>.</li>';
    openModal('<div class="p-6 text-center"><img src="assets/icons/icon-192.png" class="w-16 h-16 rounded-2xl mx-auto" alt=""><h3 class="text-lg font-semibold mt-3 !pr-0">Instalar Rutero</h3><ol class="text-left text-sm text-slate-600 mt-4 space-y-2 list-decimal pl-5">' + pasos + '</ol><button class="btn btn-primary w-full mt-5" onclick="closeModal()">Entendido</button></div>');
  };
})();

/* ---- Campanita de notificaciones ---- */
(function () {
  var datos = [], abierto = false;
  var colores = { red: 'bg-rose-50 text-rose-600', yellow: 'bg-amber-50 text-amber-600', brand: 'bg-indigo-50 text-indigo-600', blue: 'bg-sky-50 text-sky-600', violet: 'bg-violet-50 text-violet-600', green: 'bg-emerald-50 text-emerald-600' };
  function pintarBadge(n) {
    var b = document.getElementById('notif-badge'); if (!b) return;
    b.textContent = n > 9 ? '9+' : n; b.classList.toggle('hidden', !n);
    var t = document.title.replace(/^\(\d+\+?\) /, ''); document.title = n ? '(' + (n > 9 ? '9+' : n) + ') ' + t : t;
  }
  function pintarLista() {
    var l = document.getElementById('notif-list'); if (!l) return;
    var nuevas = datos.filter(function (x) { return x.nueva; }).length;
    document.getElementById('notif-sub').textContent = datos.length ? (nuevas ? nuevas + ' nueva(s)' : 'al día') : '';
    l.innerHTML = datos.length ? datos.map(function (x) {
      return '<a href="' + x.url + '" class="flex gap-3 px-4 py-3 hover:bg-slate-50 ' + (x.nueva ? 'bg-indigo-50/40' : '') + '">' +
        '<span class="w-9 h-9 rounded-xl grid place-items-center shrink-0 ' + (colores[x.color] || colores.brand) + '"><i data-lucide="' + x.icono + '" class="w-4 h-4"></i></span>' +
        '<span class="min-w-0 flex-1"><span class="flex items-start gap-2"><span class="text-sm font-semibold text-slate-800 flex-1">' + escapeHtml(x.titulo) + '</span>' + (x.nueva ? '<span class="w-2 h-2 rounded-full bg-indigo-500 mt-1.5 shrink-0"></span>' : '') + '</span>' +
        '<span class="block text-xs text-slate-500 mt-0.5">' + escapeHtml(x.texto) + '</span>' + (x.hace ? '<span class="block text-[11px] text-slate-400 mt-1">' + escapeHtml(x.hace) + '</span>' : '') + '</span></a>';
    }).join('') : '<div class="p-8 text-center text-sm text-slate-400"><i data-lucide="bell-off" class="w-8 h-8 mx-auto mb-2"></i>No tienes notificaciones</div>';
    if (window.lucide) lucide.createIcons();
  }
  async function cargar() {
    if (!document.getElementById('notif')) return;
    var r = await api('index.php?p=api&a=notif');
    if (!r || !r.ok) return;
    datos = r.data; pintarBadge(r.nuevas);
    if (abierto) pintarLista();
  }
  window.toggleNotif = function (forzar) {
    var p = document.getElementById('notif-panel');
    abierto = typeof forzar === 'boolean' ? forzar : p.classList.contains('hidden');
    p.classList.toggle('hidden', !abierto);
    if (abierto) {
      pintarLista();
      var claves = datos.filter(function (x) { return x.nueva; }).map(function (x) { return x.clave; });
      if (claves.length) { api('index.php?p=api&a=notif', { claves: claves }); pintarBadge(0); }
    } else {
      datos.forEach(function (x) { x.nueva = false; });
    }
  };
  document.addEventListener('click', function (e) {
    if (abierto && !e.composedPath().some(function (el) { return el && el.id === 'notif'; })) toggleNotif(false);
  });
  document.addEventListener('DOMContentLoaded', function () { cargar(); setInterval(cargar, 60000); });
})();
