const CACHE_PREFIX = 'athina-eshop';
const CACHE_VERSION = 'v1';
const STATIC_CACHE = `${CACHE_PREFIX}-static-${CACHE_VERSION}`;
const PAGE_CACHE = `${CACHE_PREFIX}-pages-${CACHE_VERSION}`;
const OFFLINE_URL = new URL('./offline.html', self.registration.scope).toString();

const PRECACHE_URLS = [
    './offline.html',
    './assets/styling/styles.css',
    './assets/styling/header.css',
    './assets/js/pwa.js',
    './assets/images/athina-eshop-logo.png',
    './assets/pwa/icon-192.png',
    './assets/pwa/icon-512.png'
].map(function (path) {
    return new URL(path, self.registration.scope).toString();
});

const SENSITIVE_PREFIXES = [
    'authentication/',
    'profile/',
    'modules/admin/',
    'uploads/avatars/'
];

const SENSITIVE_PATHS = new Set([
    'cart.php',
    'cart_api.php',
    'wishlist.php',
    'wishlist_action.php',
    'wishlist_toggle.php',
    'newsletter_subscribe.php',
    'submit_product_review.php',
    'modules/checkout.php',
    'modules/place_order.php',
    'modules/payment_finalize.php',
    'modules/checkout_success.php',
    'modules/checkout_failed.php',
    'modules/receipt.php',
    'modules/stripe_webhook.php',
    'modules/paypal_webhook.php'
]);

const PUBLIC_PAGE_PATHS = new Set([
    '',
    'index.php',
    'shop.php',
    'about.php',
    'contact.php',
    'faq.php',
    'privacy_policy.php',
    'shipping_returns.php',
    'terms.php'
]);

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(function (cache) {
                return cache.addAll(PRECACHE_URLS);
            })
            .then(function () {
                return self.skipWaiting();
            })
    );
});

self.addEventListener('activate', function (event) {
    const currentCaches = new Set([STATIC_CACHE, PAGE_CACHE]);

    event.waitUntil(
        caches.keys()
            .then(function (cacheNames) {
                return Promise.all(cacheNames.map(function (cacheName) {
                    if (cacheName.indexOf(CACHE_PREFIX) === 0 && !currentCaches.has(cacheName)) {
                        return caches.delete(cacheName);
                    }

                    return Promise.resolve();
                }));
            })
            .then(function () {
                return self.clients.claim();
            })
    );
});

self.addEventListener('fetch', function (event) {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (isSensitiveRequest(url)) {
        event.respondWith(fetch(request).catch(function () {
            if (request.mode === 'navigate') {
                return caches.match(OFFLINE_URL);
            }

            return Response.error();
        }));
        return;
    }

    if (isStaticRequest(request, url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(networkFirstPage(request, url));
    }
});

function relativePath(url) {
    const scopeUrl = new URL(self.registration.scope);
    const href = url.href.split('#')[0].split('?')[0];

    if (href.indexOf(scopeUrl.href) === 0) {
        return href.slice(scopeUrl.href.length).replace(/^\/+/, '').toLowerCase();
    }

    return url.pathname.replace(/^\/+/, '').toLowerCase();
}

function isSensitiveRequest(url) {
    const path = relativePath(url);

    if (SENSITIVE_PATHS.has(path)) {
        return true;
    }

    if (url.searchParams.has('token') || url.searchParams.has('mto_token')) {
        return true;
    }

    return SENSITIVE_PREFIXES.some(function (prefix) {
        return path.indexOf(prefix) === 0;
    });
}

function isStaticRequest(request, url) {
    if (['style', 'script', 'image', 'font', 'manifest'].indexOf(request.destination) !== -1) {
        return true;
    }

    return /\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|webmanifest)$/i.test(url.pathname);
}

function isCacheablePage(url) {
    if (url.search) {
        return false;
    }

    return PUBLIC_PAGE_PATHS.has(relativePath(url).replace(/\/$/, ''));
}

function shouldCacheResponse(response, expectedType) {
    if (!response || !response.ok || response.type !== 'basic') {
        return false;
    }

    if (!expectedType) {
        return true;
    }

    return (response.headers.get('content-type') || '').toLowerCase().indexOf(expectedType) !== -1;
}

function cacheFirst(request) {
    return caches.match(request).then(function (cachedResponse) {
        if (cachedResponse) {
            return cachedResponse;
        }

        return fetch(request).then(function (networkResponse) {
            if (shouldCacheResponse(networkResponse)) {
                const responseToCache = networkResponse.clone();
                caches.open(STATIC_CACHE).then(function (cache) {
                    cache.put(request, responseToCache);
                });
            }

            return networkResponse;
        });
    });
}

function networkFirstPage(request, url) {
    const canCachePage = isCacheablePage(url);

    return fetch(request).then(function (networkResponse) {
        if (canCachePage && shouldCacheResponse(networkResponse, 'text/html')) {
            const responseToCache = networkResponse.clone();
            caches.open(PAGE_CACHE).then(function (cache) {
                cache.put(request, responseToCache);
            });
        }

        return networkResponse;
    }).catch(function () {
        if (!canCachePage) {
            return caches.match(OFFLINE_URL);
        }

        return caches.match(request).then(function (cachedResponse) {
            return cachedResponse || caches.match(OFFLINE_URL);
        });
    });
}
