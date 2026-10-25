/*
 * Service worker Fluggi CRM (PWA). Страницы с данными не кэшируются — они всегда с сервера
 * (права и свежесть данных). Кэшируется только статика сборки, иконки и офлайн-страница.
 * Иконки бренда (/brand/…?v=версия) — адрес меняется при каждой замене логотипа, поэтому кэш не устаревает;
 * манифест (/app-manifest) не кэшируется.
 *
 * Web Push: системное уведомление со звуком ОС (silent: false, вибрация), даже когда вкладка и приложение закрыты.
 * Если вкладка CRM сейчас перед глазами (в фокусе), уведомление показывает и озвучивает сама вкладка (notify.js);
 * Safari (iOS/macOS) требует показывать уведомление на каждый push — там оно показывается всегда.
 */
const CACHE = 'fluggi-static-v4';
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
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/sounds/') || versionedBrand) {
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

const isWebKit = () => /AppleWebKit/.test(self.navigator.userAgent) && !/Chrome|Chromium|Edg|OPR|Android/.test(self.navigator.userAgent);

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: event.data ? event.data.text() : '' };
  }
  const title = data.title || 'Fluggi CRM';
  const options = {
    body: data.body || '',
    icon: data.icon || '/brand/icon-192',
    badge: data.badge || '/brand/icon-192',
    tag: data.tag || 'fluggi',
    renotify: true,
    requireInteraction: false,
    silent: false,
    vibrate: [200, 100, 200],
    timestamp: Date.now(),
    data: { url: data.url || '/notifications' },
  };
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      list.forEach((c) => c.postMessage({ type: 'fluggi-push', tag: options.tag }));
      const focused = list.some((c) => c.focused && c.visibilityState === 'visible');
      if (focused && !isWebKit()) return undefined;
      return self.registration.showNotification(title, options);
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/notifications', self.location.origin);
  if (target.origin !== self.location.origin) return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const same = list.find((c) => new URL(c.url).origin === self.location.origin);
      if (same && 'navigate' in same) {
        return same.focus().then((c) => (c || same).navigate(target.href)).catch(() => self.clients.openWindow(target.href));
      }
      return self.clients.openWindow(target.href);
    }),
  );
});
