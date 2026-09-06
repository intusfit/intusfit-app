const CACHE_NAME = 'intus-v31';
const ASSETS = [
  'aluno.html',
  'nutricao.html',
  'mensalidades.html',
  'alunos.html',
  'treinos.html',
  'frequencia.html',
  'api.js',
  '_mock.js',
  'alimentos-db.js',
  'favicon.ico',
  'favicon-192.png',
  'logo-icon-dark.png',
  'logo-icon-light.png',
  'logo-intus-dark.png',
  'logo-intus-light.png',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  // API calls — always network, never cache
  if (event.request.url.includes('/api/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // HTML/JS assets — network first, cache fallback
  event.respondWith(
    fetch(event.request, { cache: 'no-cache' })
      .then(response => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

// Force update check every time a page loads
self.addEventListener('message', event => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});

// Clique na notificação de treino: foca o app (ou abre) direto no aluno.html
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || 'aluno.html';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) {
        if (c.url.includes('aluno.html') && 'focus' in c) return c.focus();
      }
      return clients.openWindow(url);
    })
  );
});
