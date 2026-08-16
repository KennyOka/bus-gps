// バス停GPS検証 Service Worker
// アプリ本体をキャッシュし、2回目以降はオフライン・通信ゼロで起動させる。
// バス停データは localStorage に持つのでSWのキャッシュ対象外(通信不要)。
const CACHE = 'gps-check-v1';
const ASSETS = [
  './gps_check.html',
  './manifest.webmanifest',
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

// キャッシュ優先(cache-first)。無ければ取得してキャッシュに追加。
self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(resp => {
        const copy = resp.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
        return resp;
      }).catch(() => caches.match('./gps_check.html'));
    })
  );
});
