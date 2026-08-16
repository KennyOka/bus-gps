// バス停GPS検証 Service Worker
// アプリ本体をキャッシュし、2回目以降はオフライン・通信ゼロで起動させる。
// バス停データは localStorage に持つのでSWのキャッシュ対象外(通信不要)。
const CACHE = 'gps-check-v8';
const ASSETS = [
  './gps_check.html',
  './bus_monitor.html',
  './manifest.webmanifest',
  './manifest_monitor.webmanifest',
  './icon-192.png',
  './icon-512.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// 同一オリジン(アプリ本体)だけを扱う。APIなど別オリジンはSWが横取りせず素通し。
self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;   // 別オリジンは素通し
  if (url.pathname.includes('/api/')) return;        // API は素通し(キャッシュしない=常に最新)

  const isHTML = e.request.mode === 'navigate' ||
                 (e.request.headers.get('accept') || '').includes('text/html');

  if (isHTML) {
    // HTML はネットワーク優先(常に最新コードを取得。古い版が残らない)。オフライン時のみキャッシュ。
    e.respondWith(
      fetch(e.request).then(resp => {
        const copy = resp.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
        return resp;
      }).catch(() => caches.match(e.request).then(c => c || caches.match('./gps_check.html')))
    );
    return;
  }

  // それ以外(アイコン/manifest等)はキャッシュ優先。
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
      const copy = resp.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      return resp;
    }))
  );
});
