const CACHE_NAME = 'formops-checkin-v2';

self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter(name => name.startsWith('formops-checkin-') && name !== CACHE_NAME)
            .map(name => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    const isCheckinPage = request.mode === 'navigate' && url.pathname.endsWith('/entrada');
    const isCheckinAsset =
        url.pathname.endsWith('/assets/vendor/jsQR.js') ||
        url.pathname.endsWith('/assets/clients/formops/favicon-formops.png');

    if (isCheckinAsset) {
        event.respondWith((async () => {
            const cache = await caches.open(CACHE_NAME);
            const cached = await cache.match(request);
            if (cached) return cached;
            const response = await fetch(request);
            if (response.ok) await cache.put(request, response.clone());
            return response;
        })());
        return;
    }

    if (isCheckinPage) {
        event.respondWith((async () => {
            const cache = await caches.open(CACHE_NAME);
            try {
                const response = await fetch(request);
                if (response.ok) await cache.put(request, response.clone());
                return response;
            } catch (_) {
                const cached = await cache.match(request);
                if (cached) return cached;
                return new Response(
                    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FormOps offline</title><body style="font-family:system-ui;padding:24px;background:#f3f6fb;color:#172033"><h1>Sem conexão</h1><p>Abra este acesso uma vez com internet para preparar a cópia local do controle de entrada.</p></body>',
                    {headers:{'Content-Type':'text/html; charset=utf-8'}}
                );
            }
        })());
    }
});