// Network only; offline navigations get a tiny notice. ponytail: no asset/page caching (Livewire pages carry CSRF tokens), add if offline use matters.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
    if (e.request.mode !== 'navigate') return;
    e.respondWith(fetch(e.request).catch(() => new Response('<meta name="viewport" content="width=device-width"><body style="font:16px system-ui;padding:2rem"><h1>Offline</h1><p>Jacker needs a connection. Pull down to retry.</p>', { headers: { 'Content-Type': 'text/html' } })));
});
