const CACHE_NAME = 'fyuhls-v7';
const ASSETS = [
    '/assets/css/style.css',
    '/assets/css/filemanager.css'
];

function isCacheableAsset(requestUrl) {
    const url = new URL(requestUrl);

    if (url.origin !== self.location.origin) {
        return false;
    }

    if (url.pathname.startsWith('/download/')
        || url.pathname.startsWith('/file/')
        || url.pathname.startsWith('/api/')
        || url.pathname.startsWith('/admin/')
        || url.pathname.startsWith('/checkout/')
        || url.pathname.startsWith('/payment/')) {
        return false;
    }

    return ASSETS.includes(url.pathname)
        || url.pathname.startsWith('/assets/')
        || url.pathname.startsWith('/themes/');
}

self.addEventListener('install', (event) => {
    // Skip waiting forces the waiting service worker to become the active service worker.
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS))
    );
});

self.addEventListener('activate', (event) => {
    // Delete any old caches that don't match the current CACHE_NAME
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            // Take control of all clients immediately
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    if (!isCacheableAsset(event.request.url)) return;

    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request).then((response) => {
                return response || new Response('Network error and no offline cache available.', {
                    status: 503,
                    statusText: 'Service Unavailable'
                });
            });
        })
    );
});
