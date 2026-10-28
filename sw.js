/* Rutero - Service Worker */
const CACHE = 'rutero-v1';
const ASSETS = [
  'assets/styles.css', 'assets/app.js', 'assets/vendor/lucide.min.js', 'assets/vendor/chart.umd.min.js',
  'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icon.svg', 'offline.html',
  'assets/fonts/inter-latin-400-normal.woff2', 'assets/fonts/inter-latin-500-normal.woff2', 'assets/fonts/inter-latin-600-normal.woff2',
  'assets/fonts/inter-latin-700-normal.woff2', 'assets/fonts/inter-latin-800-normal.woff2'
];
self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  // Páginas: siempre desde la red (datos al día); sin conexión, página offline
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  // Recursos estáticos: caché primero (el ?v= cambia en cada versión)
  if (url.pathname.includes('/assets/')) {
    e.respondWith(caches.match(req).then((r) => r || fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
      return res;
    })));
  }
});
