/*
 * Service worker Fluggi CRM (PWA). Страницы с данными не кэшируются — они всегда с сервера
 * (права и свежесть данных). Кэшируется только статика сборки, иконки и офлайн-страница.
 * Иконки бренда (/brand/…?v=версия) — адрес меняется при каждой замене логотипа, поэтому кэш не устаревает;
 * манифест (/app-manifest) не кэшируется.
 */
const CACHE = 'fluggi-static-v3';
const OFFLINE = '/offline';

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Статика сборки (имена с хэшем) и иконки с версией в адресе — из кэша
  const versionedBrand = url.pathname.startsWith('/brand/') && url.searchParams.has('v');
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || versionedBrand) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(request, copy));
        }
        return res;
      })),
    );
    return;
  }
  // Страницы — всегда из сети; без сети — офлайн-страница
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
  }
  // Остальное (манифест, /brand/ без версии) — браузер запрашивает сам, без кэша service worker
});
